<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\UrlaubskontoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WorkerAppAbsenceController extends Controller
{
    /**
     * The three request categories a worker can submit from the app.
     *   urlaub    — paid vacation, deducted from Urlaubskonto, needs approval
     *   krank     — sickness report: recorded immediately, no approval loop
     *   sonstiges — unpaid / other, needs approval
     */
    private const CATEGORIES = [
        'urlaub'    => ['typ' => 'Urlaub',       'bezahlt' => 1, 'status' => 'pending'],
        'krank'     => ['typ' => 'Krankmeldung', 'bezahlt' => 1, 'status' => 'genehmigt'],
        'sonstiges' => ['typ' => 'Wunschfrei',   'bezahlt' => 0, 'status' => 'pending'],
    ];

    /**
     * GET — List worker's own absences + summary.
     */
    public function index(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $where  = "dienst_nr = ?";
        $params = [$dienstNr];

        // SARGable date range so the (dienst_nr, startdatum) index can be used.
        if ($request->filled('jahr')) {
            $jahr = max(1900, (int) $request->jahr);
            $where   .= " AND startdatum BETWEEN ? AND ?";
            $params[] = sprintf('%04d-01-01', $jahr);
            $params[] = sprintf('%04d-12-31', $jahr);
        }

        $absences = DB::connection('tenant')->select("SELECT * FROM mitarbeiter_abwesenheiten WHERE $where ORDER BY startdatum DESC", $params);

        // Summary
        $summaryWhere  = "dienst_nr = ?";
        $summaryParams = [$dienstNr];
        if ($request->filled('jahr')) {
            $jahr = max(1900, (int) $request->jahr);
            $summaryWhere   .= " AND startdatum BETWEEN ? AND ?";
            $summaryParams[] = sprintf('%04d-01-01', $jahr);
            $summaryParams[] = sprintf('%04d-12-31', $jahr);
        }
        $summary = DB::connection('tenant')->select("
            SELECT typ, bezahlt, SUM(tage) as total_tage, COUNT(*) as anzahl
            FROM mitarbeiter_abwesenheiten WHERE $summaryWhere
            GROUP BY typ, bezahlt ORDER BY typ
        ", $summaryParams);

        $totalDays = 0;
        foreach ($summary as $s) $totalDays += $s->total_tage;

        // Unified Urlaubskonto — the SAME numbers the admin portal shows.
        $konto = UrlaubskontoService::balance($dienstNr);

        return response()->json([
            'abwesenheiten'   => $absences,
            'zusammenfassung' => $summary,
            'total_tage'      => $totalDays,
            'urlaubskonto'    => $konto,
            // Legacy keys kept so an older installed app version keeps working.
            'urlaubstage'     => $konto['anspruch'],
            'urlaub_genommen' => $konto['genommen'],
            'urlaub_rest'     => $konto['rest'],
            'kategorien'      => array_keys(self::CATEGORIES),
        ]);
    }

    /**
     * POST — Create absence request.
     */
    public function store(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        if (!$request->filled('startdatum')) {
            return response()->json(['error' => 'Startdatum ist erforderlich'], 400);
        }

        $kategorie = strtolower((string) $request->input('kategorie', 'sonstiges'));
        if (!isset(self::CATEGORIES[$kategorie])) {
            return response()->json(['error' => 'Ungültige Kategorie'], 400);
        }
        $cfg = self::CATEGORIES[$kategorie];

        $start = Carbon::parse($request->startdatum);
        if ($kategorie === 'krank') {
            // Sickness may be reported retroactively (up to 14 days back).
            if ($start->lt(Carbon::today()->subDays(14))) {
                return response()->json(['error' => 'Krankmeldungen sind maximal 14 Tage rückwirkend möglich'], 400);
            }
        } elseif ($start->lt(Carbon::today())) {
            return response()->json(['error' => 'Startdatum muss in der Zukunft liegen'], 400);
        }

        $end = $request->filled('enddatum') ? Carbon::parse($request->enddatum) : $start->copy();
        if ($end->lt($start)) {
            return response()->json(['error' => 'Enddatum muss nach Startdatum liegen'], 400);
        }

        $ganzerTag = (int)($request->input('ganzer_tag', 1));

        // Hourly (Von–Bis) absences only make sense within a SINGLE day —
        // e.g. a doctor's appointment. A multi-day range is always full days.
        if ($end->gt($start)) {
            $ganzerTag = 1;
        }

        if (!$ganzerTag && (!$request->filled('startzeit') || !$request->filled('endzeit'))) {
            return response()->json(['error' => 'Start- und Endzeit erforderlich für Teilabwesenheit'], 400);
        }

        // Every calendar day (incl. weekends) is counted / deducted.
        $tage = UrlaubskontoService::workdays($start, $end, (bool) $ganzerTag);
        if ($tage <= 0) {
            return response()->json(['error' => 'Der Zeitraum enthält keine Arbeitstage (Mo–Fr)'], 400);
        }

        // Vacation requests must fit in the remaining balance (approved + pending).
        if ($kategorie === 'urlaub') {
            $konto = UrlaubskontoService::balance($dienstNr, (int) $start->year);
            if ($tage > $konto['rest']) {
                return response()->json([
                    'error' => "Nicht genügend Resturlaub: {$konto['rest']} Tage verfügbar, {$tage} Tage beantragt",
                ], 422);
            }
        }

        // Rejected requests must not block a new attempt for the same period —
        // otherwise one rejection locks the worker out of those dates forever.
        $overlap = DB::connection('tenant')->selectOne("
            SELECT id FROM mitarbeiter_abwesenheiten
            WHERE dienst_nr = ? AND startdatum <= ? AND COALESCE(enddatum, startdatum) >= ?
              AND COALESCE(status, 'genehmigt') != 'abgelehnt' LIMIT 1
        ", [$dienstNr, $end->toDateString(), $start->toDateString()]);

        if ($overlap) {
            return response()->json(['error' => 'Es existiert bereits eine Abwesenheit in diesem Zeitraum'], 409);
        }

        $id = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->insertGetId([
            'dienst_nr'    => $dienstNr,
            'typ'          => $cfg['typ'],
            'kategorie'    => $kategorie,
            'status'       => $cfg['status'],
            'bezahlt'      => $cfg['bezahlt'],
            'ganzer_tag'   => $ganzerTag,
            'startdatum'   => $start->toDateString(),
            'enddatum'     => $end->toDateString(),
            'startzeit'    => $ganzerTag ? null : $request->startzeit,
            'endzeit'      => $ganzerTag ? null : $request->endzeit,
            'tage'         => $tage,
            'beschreibung' => $request->beschreibung,
        ]);

        $this->notifyManagers($request, $kategorie === 'krank' ? 'krank' : 'created', $id, $dienstNr, $start, $end, $ganzerTag, $tage, $kategorie);

        \App\Helpers\Realtime::changed('workers');
        return response()->json(['success' => true, 'id' => $id, 'tage' => $tage, 'status' => $cfg['status']], 201);
    }

    /**
     * Fan out an in-app + email notification to every central_user with
     * Mitarbeiter permission so they see the new/updated Wunschfrei request.
     * Failures are swallowed by the dispatcher; this never breaks the worker
     * request that triggered it.
     */
    private function notifyManagers(
        Request $request,
        string $action,
        int $absenceId,
        string $dienstNr,
        Carbon $start,
        Carbon $end,
        int $ganzerTag,
        $tage,
        string $kategorie = 'sonstiges'
    ): void {
        $worker = $request->attributes->get('worker');
        $workerName = $worker
            ? trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''))
            : $dienstNr;
        if ($workerName === '') $workerName = $dienstNr;

        $tenantId = optional(Tenant::query()
            ->where('database', config('database.connections.tenant.database'))
            ->first())->id;
        if (!$tenantId) return;

        $range = $start->format('d.m.Y');
        if ($end->ne($start)) {
            $range .= ' – ' . $end->format('d.m.Y');
        }
        $timeLabel = $ganzerTag
            ? 'Ganzer Tag'
            : (substr((string) $request->startzeit, 0, 5) . ' – ' . substr((string) $request->endzeit, 0, 5));

        $katLabel = ['urlaub' => 'Urlaubsantrag', 'krank' => 'Krankmeldung', 'sonstiges' => 'Wunschfrei-Antrag'][$kategorie] ?? 'Antrag';
        if ($action === 'krank') {
            $verb  = 'gemeldet';
            $title = "Krankmeldung: {$workerName}";
        } else {
            $verb  = $action === 'updated' ? 'aktualisiert' : 'eingereicht';
            $title = "{$katLabel} {$verb}: {$workerName}";
        }
        $body  = "Mitarbeiter {$workerName} (Dienst-Nr. {$dienstNr}) hat eine(n) {$katLabel} {$verb}.\n"
               . "Zeitraum: {$range}\n"
               . "Zeit: {$timeLabel}\n"
               . "Tage: {$tage}\n"
               . ($request->beschreibung ? ("Bemerkung: " . $request->beschreibung . "\n") : '')
               . "\nÖffnen Sie das Clood-X Portal → Mitarbeiter → " . $workerName . " → Abwesenheiten, um den Antrag zu prüfen.";

        // Render the styled HTML email — matches the visual language of
        // the rest of the system (rounded card, gradient header, info
        // table, highlight box, branded footer).
        $companyName = null;
        try {
            $tenantObj = \App\Services\TenantService::current();
            $companyName = $tenantObj->name ?? null;
        } catch (\Throwable $e) { /* optional */ }
        $companyName = $companyName ?: config('app.name', 'Clood-x');

        $portalUrl = rtrim(config('app.url', ''), '/');
        $portalUrl = $portalUrl
            ? $portalUrl . '/index.php#workers?dienst_nr=' . urlencode($dienstNr)
            : null;

        $htmlBody = null;
        try {
            $htmlBody = view('emails.worker-absence-request', [
                'company'    => $companyName,
                'action'     => $action,
                'workerName' => $workerName,
                'dienstNr'   => $dienstNr,
                'zeitraum'   => $range,
                'timeLabel'  => $timeLabel,
                'tage'       => $tage,
                'bemerkung'  => $request->beschreibung ?: null,
                'portalUrl'  => $portalUrl,
            ])->render();
        } catch (\Throwable $e) {
            \Log::warning('worker-absence-request template render failed: ' . $e->getMessage());
        }

        NotificationDispatcher::notifyMitarbeiterManagers(
            (int) $tenantId,
            'worker.absence.' . $action,
            $title,
            $body,
            '#absences?id=' . $absenceId,
            [
                'absence_id' => $absenceId,
                'dienst_nr'  => $dienstNr,
                'worker'     => $workerName,
                'kategorie'  => $kategorie,
                'startdatum' => $start->toDateString(),
                'enddatum'   => $end->toDateString(),
                'ganzer_tag' => $ganzerTag,
                'tage'       => $tage,
            ],
            $htmlBody
        );
    }

    /**
     * PUT — Update own future absence.
     */
    public function update(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        if (!$request->filled('id') || !$request->filled('startdatum')) {
            return response()->json(['error' => 'id und Startdatum sind erforderlich'], 400);
        }

        $absence = DB::connection('tenant')->selectOne("SELECT * FROM mitarbeiter_abwesenheiten WHERE id = ? AND dienst_nr = ?", [$request->id, $dienstNr]);
        if (!$absence) {
            return response()->json(['error' => 'Abwesenheit nicht gefunden'], 404);
        }
        if ($absence->startdatum <= date('Y-m-d')) {
            return response()->json(['error' => 'Vergangene oder aktive Abwesenheiten können nicht bearbeitet werden'], 403);
        }
        // Once the manager has decided, the worker cannot quietly edit the
        // request anymore — they must withdraw and submit a new one.
        if (in_array($absence->status ?? 'genehmigt', ['genehmigt', 'abgelehnt'], true)) {
            return response()->json(['error' => 'Dieser Antrag wurde bereits entschieden und kann nicht mehr bearbeitet werden'], 403);
        }

        $start = Carbon::parse($request->startdatum);
        if ($start->lt(Carbon::today())) {
            return response()->json(['error' => 'Startdatum muss in der Zukunft liegen'], 400);
        }

        $end = $request->filled('enddatum') ? Carbon::parse($request->enddatum) : $start->copy();
        if ($end->lt($start)) {
            return response()->json(['error' => 'Enddatum muss nach Startdatum liegen'], 400);
        }

        $ganzerTag = (int)($request->input('ganzer_tag', 1));

        // Hourly (Von–Bis) absences only make sense within a SINGLE day —
        // e.g. a doctor's appointment. A multi-day range is always full days.
        if ($end->gt($start)) {
            $ganzerTag = 1;
        }

        if (!$ganzerTag && (!$request->filled('startzeit') || !$request->filled('endzeit'))) {
            return response()->json(['error' => 'Start- und Endzeit erforderlich für Teilabwesenheit'], 400);
        }

        $tage = UrlaubskontoService::workdays($start, $end, (bool) $ganzerTag);
        if ($tage <= 0) {
            return response()->json(['error' => 'Der Zeitraum enthält keine Arbeitstage (Mo–Fr)'], 400);
        }

        if (($absence->kategorie ?? 'sonstiges') === 'urlaub') {
            $konto = UrlaubskontoService::balance($dienstNr, (int) $start->year);
            // The row being edited is itself part of 'beantragt' — add it back.
            $available = $konto['rest'] + (float) $absence->tage;
            if ($tage > $available) {
                return response()->json([
                    'error' => "Nicht genügend Resturlaub: {$available} Tage verfügbar, {$tage} Tage beantragt",
                ], 422);
            }
        }

        $overlap = DB::connection('tenant')->selectOne("
            SELECT id FROM mitarbeiter_abwesenheiten
            WHERE dienst_nr = ? AND id != ? AND startdatum <= ? AND COALESCE(enddatum, startdatum) >= ?
              AND COALESCE(status, 'genehmigt') != 'abgelehnt' LIMIT 1
        ", [$dienstNr, $request->id, $end->toDateString(), $start->toDateString()]);

        if ($overlap) {
            return response()->json(['error' => 'Es existiert bereits eine Abwesenheit in diesem Zeitraum'], 409);
        }

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('id', $request->id)
            ->where('dienst_nr', $dienstNr)
            ->update([
                'ganzer_tag'   => $ganzerTag,
                'startdatum'   => $start->toDateString(),
                'enddatum'     => $end->toDateString(),
                'startzeit'    => $ganzerTag ? null : $request->startzeit,
                'endzeit'      => $ganzerTag ? null : $request->endzeit,
                'tage'         => $tage,
                'beschreibung' => $request->beschreibung,
                'status'       => 'pending',  // editing resets approval state
            ]);

        $this->notifyManagers($request, 'updated', (int) $request->id, $dienstNr, $start, $end, $ganzerTag, $tage, $absence->kategorie ?? 'sonstiges');

        \App\Helpers\Realtime::changed('workers');
        return response()->json(['success' => true]);
    }

    /**
     * DELETE — Remove own future absence.
     */
    public function destroy(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $id = $request->input('id');

        if (!$id) {
            return response()->json(['error' => 'id erforderlich'], 400);
        }

        $absence = DB::connection('tenant')->selectOne("SELECT * FROM mitarbeiter_abwesenheiten WHERE id = ? AND dienst_nr = ?", [$id, $dienstNr]);
        if (!$absence) {
            return response()->json(['error' => 'Abwesenheit nicht gefunden'], 404);
        }
        if ($absence->startdatum <= date('Y-m-d')) {
            return response()->json(['error' => 'Vergangene oder aktive Abwesenheiten können nicht gelöscht werden'], 403);
        }
        // Same rule as update(): once the manager decided, the worker cannot
        // silently withdraw it via the API (the UI already hides the button —
        // an approved vacation disappearing unnoticed would corrupt planning).
        if (in_array($absence->status ?? 'genehmigt', ['genehmigt', 'abgelehnt'], true)) {
            return response()->json(['error' => 'Dieser Antrag wurde bereits entschieden und kann nicht mehr gelöscht werden. Bitte wenden Sie sich an die Verwaltung.'], 403);
        }

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->where('dienst_nr', $dienstNr)->delete();

        \App\Helpers\Realtime::changed('workers');
        return response()->json(['success' => true]);
    }
}
