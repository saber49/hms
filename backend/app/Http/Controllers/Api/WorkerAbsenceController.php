<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Services\UrlaubskontoService;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkerAbsenceController extends Controller
{
    /**
     * POST /api/worker-absences/{id}/approve
     * Manager confirms a pending request. Adds an entry to the
     * worker-facing notification feed + sends an email to the worker.
     */
    /**
     * GET /api/worker-absences/pending-counts
     *
     * Returns a flat map { "MA001": 2, "MA005": 1 } so the Mitarbeiter
     * grid can paint a small red badge on each worker card with the
     * number of absence requests still awaiting a decision.
     */
    public function pendingCounts(Request $request)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');
        $rows = DB::connection('tenant')->table('mitarbeiter_abwesenheiten as a')
            ->join('mitarbeiter as mi', 'mi.dienst_nr', '=', 'a.dienst_nr')
            ->whereRaw($wSql, $wParams)
            ->select('a.dienst_nr', DB::raw('COUNT(*) as c'))
            ->where('a.status', 'pending')
            ->groupBy('a.dienst_nr')
            ->get();

        $out = [];
        foreach ($rows as $r) $out[$r->dienst_nr] = (int) $r->c;
        return response()->json(['pending' => $out]);
    }

    public function approve(Request $request, int $id)
    {
        return $this->decide($request, $id, 'genehmigt');
    }

    public function zeitFreigabe(Request $request, int $id)
    {
        $data = $request->validate(['freigabe' => 'required|boolean']);
        $absence = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->first();
        if (!$absence || !BereichScope::allowsWorker($request->user(), (string) $absence->dienst_nr)) {
            return response()->json(['error' => 'Abwesenheit nicht gefunden'], 404);
        }
        if (($absence->status ?? 'genehmigt') !== 'genehmigt') {
            return response()->json(['error' => 'Nur genehmigte Abwesenheiten freigeben'], 409);
        }
        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)
            ->update(['zeit_freigabe' => $data['freigabe']]);
        return response()->json(['success' => true]);
    }

    /**
     * POST /api/worker-absences/{id}/reject
     * Body: { grund: "..." }
     */
    public function reject(Request $request, int $id)
    {
        return $this->decide($request, $id, 'abgelehnt');
    }

    /**
     * Shared decision flow. Idempotent: deciding again with the same status
     * is a no-op (returns success but doesn't re-notify the worker).
     */
    private function decide(Request $request, int $id, string $status)
    {
        $absence = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->first();
        if (!$absence || !BereichScope::allowsWorker($request->user(), (string) $absence->dienst_nr)) {
            return response()->json(['error' => 'Abwesenheit nicht gefunden'], 404);
        }
        if (($absence->status ?? 'genehmigt') === $status) {
            return response()->json(['success' => true, 'note' => 'already ' . $status]);
        }

        $grund = $status === 'abgelehnt'
            ? trim((string) $request->input('grund', ''))
            : null;

        $userId = optional($request->user())->id;

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('id', $id)
            ->update([
                'status'           => $status,
                'zeit_freigabe'    => false,
                'entschieden_von'  => $userId,
                'entschieden_am'   => now(),
                'ablehnungsgrund'  => $grund,
            ]);

        // Notify the worker through their own feed + a beautifully styled
        // tenant-branded HTML email (see resources/views/emails/worker-absence-decision.blade.php).
        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $absence->dienst_nr)->first();
        if ($worker) {
            $approved = $status === 'genehmigt';

            $range = date('d.m.Y', strtotime($absence->startdatum));
            if ($absence->enddatum && $absence->enddatum !== $absence->startdatum) {
                $range .= ' – ' . date('d.m.Y', strtotime($absence->enddatum));
            }

            // Plain-text fallback (used in the in-app feed body too).
            if ($approved) {
                $title = 'Antrag genehmigt: ' . $absence->typ;
                $body  = "Ihr Antrag ({$absence->typ}) für {$range} wurde genehmigt.";
            } else {
                $title = 'Antrag abgelehnt: ' . $absence->typ;
                $body  = "Ihr Antrag ({$absence->typ}) für {$range} wurde leider abgelehnt."
                       . ($grund ? "\n\nBegründung: " . $grund : '');
            }

            // Render the styled email — same visual language as the NU
            // emails (rounded card, gradient header in the result color,
            // info table, highlight box, branded footer).
            $tenant = TenantService::current();
            $companyName = $tenant->name ?? config('app.name', 'Clood-x');
            $workerName = trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''));
            if ($workerName === '') $workerName = $worker->dienst_nr;

            $htmlBody = null;
            try {
                $htmlBody = view('emails.worker-absence-decision', [
                    'company'     => $companyName,
                    'approved'    => $approved,
                    'workerName'  => $workerName,
                    'typ'         => $absence->typ,
                    'zeitraum'    => $range,
                    'tage'        => (float) $absence->tage . ' ' . ((float)$absence->tage == 1.0 ? 'Tag' : 'Tage'),
                    'beschreibung'=> $absence->beschreibung ?? null,
                    'decidedAt'   => date('d.m.Y H:i'),
                    'grund'       => $grund,
                ])->render();
            } catch (\Throwable $e) {
                \Log::warning('worker-absence-decision template render failed: ' . $e->getMessage());
            }

            NotificationDispatcher::notifyWorker(
                $worker,
                'absence.' . $status,
                $title,
                $body,
                [
                    'absence_id' => $id,
                    'typ'        => $absence->typ,
                    'startdatum' => $absence->startdatum,
                    'enddatum'   => $absence->enddatum,
                    'status'     => $status,
                    'grund'      => $grund,
                ],
                $htmlBody
            );
        }

        return response()->json(['success' => true, 'status' => $status]);
    }

    protected $absenceTypes = [
        'Arzt Termin' => 1, 'Berufsschule' => 0, 'Fehlt unentschuldigt' => 0,
        'Feiertag' => 1, 'Frei aus Überstunden' => 1, 'Krank Mit AU' => 1,
        'Krank Ohne AU' => 0, 'Krankmeldung' => 1, 'Kunden Außendienst' => 1, 'Kurierdienst' => 1,
        'Unbezahlte Urlaub' => 0, 'Urlaub' => 1, 'Wunschfrei' => 0,
    ];

    /** Map a legacy `typ` label onto the professional 3-category model. */
    private function kategorieForTyp(string $typ): string
    {
        if ($typ === 'Urlaub') return 'urlaub';
        if (in_array($typ, ['Krank Mit AU', 'Krank Ohne AU', 'Krankmeldung'], true)) return 'krank';
        return 'sonstiges';
    }

    /**
     * GET /api/worker-absences/requests
     * Central approvals inbox — every pending request across ALL workers,
     * newest first, with worker identity for one-click decisions.
     */
    public function requests(Request $request)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');
        $rows = DB::connection('tenant')->select("
            SELECT a.*, CONCAT(mi.vorname, ' ', mi.name) AS worker_name, mi.foto, mi.bereich, mi.urlaubstage
            FROM mitarbeiter_abwesenheiten a
            JOIN mitarbeiter mi ON mi.dienst_nr = a.dienst_nr
            WHERE a.status = 'pending' AND $wSql
            ORDER BY a.created_at IS NULL, a.created_at DESC, a.id DESC
        ", $wParams);

        // Attach the live Urlaubskonto per worker so the approver sees the
        // impact of the decision without opening the profile.
        $konten = [];
        foreach ($rows as $r) {
            if (!isset($konten[$r->dienst_nr])) {
                $konten[$r->dienst_nr] = UrlaubskontoService::balance($r->dienst_nr);
            }
            $r->urlaubskonto = $konten[$r->dienst_nr];
        }

        return response()->json(['requests' => $rows, 'count' => count($rows)]);
    }

    /**
     * GET /api/worker-absences/history
     * Decision log — the most recent decided (approved/rejected) requests
     * across all workers, incl. WHO decided and WHEN, newest decision first.
     * Only rows that actually went through the workflow (entschieden_am set)
     * appear; direct admin entries without a decision step are excluded.
     */
    public function history(Request $request)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');
        $rows = DB::connection('tenant')->select("
            SELECT a.id, a.dienst_nr, a.typ, a.kategorie, a.status, a.startdatum, a.enddatum,
                   a.ganzer_tag, a.tage, a.beschreibung, a.ablehnungsgrund,
                   a.entschieden_von, a.entschieden_am, a.created_at,
                   CONCAT(mi.vorname, ' ', mi.name) AS worker_name, mi.foto, mi.bereich
            FROM mitarbeiter_abwesenheiten a
            JOIN mitarbeiter mi ON mi.dienst_nr = a.dienst_nr
            WHERE a.entschieden_am IS NOT NULL
              AND a.status IN ('genehmigt', 'abgelehnt')
              AND $wSql
            ORDER BY a.entschieden_am DESC
            LIMIT 100
        ", $wParams);

        // Resolve decider names from the central DB (no cross-DB FK).
        $ids = array_values(array_unique(array_filter(array_map(fn ($r) => $r->entschieden_von, $rows))));
        $names = [];
        if ($ids) {
            foreach (DB::table('central_users')->whereIn('id', $ids)->get(['id', 'name']) as $u) {
                $names[$u->id] = $u->name;
            }
        }
        foreach ($rows as $r) {
            $r->entschieden_von_name = $r->entschieden_von ? ($names[$r->entschieden_von] ?? null) : null;
        }

        return response()->json(['history' => $rows, 'count' => count($rows)]);
    }

    /**
     * GET /api/worker-absences/calendar?von=YYYY-MM-DD&bis=YYYY-MM-DD
     * Team absence calendar — all non-rejected absences overlapping the
     * range, grouped per worker, so planners can see who is out when.
     */
    public function calendar(Request $request)
    {
        $von = $request->query('von') ?: date('Y-m-01');
        $bis = $request->query('bis') ?: date('Y-m-t');

        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');
        $rows = DB::connection('tenant')->select("
            SELECT a.id, a.dienst_nr, a.typ, a.kategorie, a.status, a.startdatum, a.enddatum,
                   a.ganzer_tag, a.tage, a.beschreibung,
                   CONCAT(mi.vorname, ' ', mi.name) AS worker_name, mi.foto, mi.bereich
            FROM mitarbeiter_abwesenheiten a
            JOIN mitarbeiter mi ON mi.dienst_nr = a.dienst_nr
            WHERE mi.aktiv = 1
              AND COALESCE(a.status, 'genehmigt') != 'abgelehnt'
              AND a.startdatum <= ? AND COALESCE(a.enddatum, a.startdatum) >= ?
              AND $wSql
            ORDER BY a.startdatum
        ", array_merge([$bis, $von], $wParams));

        return response()->json(['von' => $von, 'bis' => $bis, 'eintraege' => $rows]);
    }

    public function index(Request $request)
    {
        $dienst_nr = $request->query('dienst_nr');
        if (!$dienst_nr) return response()->json(['error' => 'dienst_nr erforderlich'], 400);
        if (!BereichScope::allowsWorker($request->user(), (string) $dienst_nr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $query = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('dienst_nr', $dienst_nr);

        // SARGable date range so the (dienst_nr, startdatum) index can be used.
        // YEAR()/MONTH() functions on the column would force a full table scan.
        if ($request->filled('jahr')) {
            $jahr = max(1900, (int) $request->jahr);
            if ($request->filled('monat')) {
                $monat = max(1, min(12, (int) $request->monat));
                $startDate = sprintf('%04d-%02d-01', $jahr, $monat);
                $endDate   = date('Y-m-t', strtotime($startDate));
            } else {
                $startDate = sprintf('%04d-01-01', $jahr);
                $endDate   = sprintf('%04d-12-31', $jahr);
            }
            $query->whereBetween('startdatum', [$startDate, $endDate]);
        }
        if ($request->filled('typ')) $query->where('typ', $request->typ);

        $absences = $query->orderByDesc('startdatum')->get();

        // Rejected requests never count towards any totals.
        $summaryQuery = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->where('dienst_nr', $dienst_nr)
            ->whereRaw("COALESCE(status, 'genehmigt') != 'abgelehnt'")
            ->selectRaw('typ, bezahlt, SUM(tage) as total_tage, COUNT(*) as anzahl')
            ->groupBy('typ', 'bezahlt')->orderBy('typ');

        if ($request->filled('jahr')) {
            $jahr = max(1900, (int) $request->jahr);
            $summaryQuery->whereBetween('startdatum', [
                sprintf('%04d-01-01', $jahr),
                sprintf('%04d-12-31', $jahr),
            ]);
        }

        $summary = $summaryQuery->get();

        $totalBezahlt = $summary->where('bezahlt', 1)->sum('total_tage');
        $totalUnbezahlt = $summary->where('bezahlt', 0)->sum('total_tage');

        $konto = UrlaubskontoService::balance(
            $dienst_nr,
            $request->filled('jahr') ? max(1900, (int) $request->jahr) : null
        );

        return response()->json([
            'abwesenheiten' => $absences,
            'zusammenfassung' => $summary,
            'total_bezahlt' => $totalBezahlt,
            'total_unbezahlt' => $totalUnbezahlt,
            'total_tage' => $totalBezahlt + $totalUnbezahlt,
            'urlaubskonto' => $konto,
            'types' => array_keys($this->absenceTypes),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->all();
        if (empty($data['dienst_nr']) || empty($data['typ']) || empty($data['startdatum'])) {
            return response()->json(['error' => 'dienst_nr, typ und startdatum sind erforderlich'], 400);
        }
        if (!BereichScope::allowsWorker($request->user(), (string) $data['dienst_nr'])) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $start = \Carbon\Carbon::parse($data['startdatum']);
        $end = !empty($data['enddatum']) ? \Carbon\Carbon::parse($data['enddatum']) : $start->copy();
        $ganzerTag = (int)($data['ganzer_tag'] ?? 1);
        $tage = $data['tage'] ?? UrlaubskontoService::workdays($start, $end, (bool) $ganzerTag);
        $bezahlt = isset($data['bezahlt']) ? (int)$data['bezahlt'] : ($this->absenceTypes[$data['typ']] ?? 0);

        $overlap = DB::connection('tenant')->selectOne("
            SELECT id FROM mitarbeiter_abwesenheiten
            WHERE dienst_nr = ? AND startdatum <= ? AND COALESCE(enddatum, startdatum) >= ?
              AND COALESCE(status, 'genehmigt') != 'abgelehnt' LIMIT 1
        ", [$data['dienst_nr'], $end->toDateString(), $start->toDateString()]);
        if ($overlap) {
            return response()->json(['error' => 'Es existiert bereits eine Abwesenheit in diesem Zeitraum'], 409);
        }

        $id = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->insertGetId([
            'dienst_nr' => $data['dienst_nr'],
            'typ' => $data['typ'],
            'kategorie' => $this->kategorieForTyp($data['typ']),
            'bezahlt' => $bezahlt,
            'ganzer_tag' => $data['ganzer_tag'] ?? 1,
            'startdatum' => $data['startdatum'],
            'enddatum' => $data['enddatum'] ?? $data['startdatum'],
            'startzeit' => $data['startzeit'] ?? null,
            'endzeit' => $data['endzeit'] ?? null,
            'tage' => $tage,
            'beschreibung' => $data['beschreibung'] ?? null,
        ]);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->all();

        $existing = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->first();
        if (!$existing || !BereichScope::allowsWorker($request->user(), (string) $existing->dienst_nr)) {
            return response()->json(['error' => 'Abwesenheit nicht gefunden'], 404);
        }

        $start = \Carbon\Carbon::parse($data['startdatum']);
        $end = !empty($data['enddatum']) ? \Carbon\Carbon::parse($data['enddatum']) : $start->copy();
        $ganzerTag = (int)($data['ganzer_tag'] ?? 1);
        $tage = $data['tage'] ?? UrlaubskontoService::workdays($start, $end, (bool) $ganzerTag);
        $bezahlt = isset($data['bezahlt']) ? (int)$data['bezahlt'] : ($this->absenceTypes[$data['typ']] ?? 0);

        $overlap = DB::connection('tenant')->selectOne("
            SELECT id FROM mitarbeiter_abwesenheiten a
            JOIN (SELECT dienst_nr FROM mitarbeiter_abwesenheiten WHERE id = ?) me ON me.dienst_nr = a.dienst_nr
            WHERE a.id != ? AND a.startdatum <= ? AND COALESCE(a.enddatum, a.startdatum) >= ?
              AND COALESCE(a.status, 'genehmigt') != 'abgelehnt' LIMIT 1
        ", [$id, $id, $end->toDateString(), $start->toDateString()]);
        if ($overlap) {
            return response()->json(['error' => 'Es existiert bereits eine Abwesenheit in diesem Zeitraum'], 409);
        }

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->update([
            'typ' => $data['typ'],
            'kategorie' => $this->kategorieForTyp($data['typ']),
            'bezahlt' => $bezahlt,
            'ganzer_tag' => $data['ganzer_tag'] ?? 1,
            'startdatum' => $data['startdatum'],
            'enddatum' => $data['enddatum'] ?? $data['startdatum'],
            'startzeit' => $data['startzeit'] ?? null,
            'endzeit' => $data['endzeit'] ?? null,
            'tage' => $tage,
            'beschreibung' => $data['beschreibung'] ?? null,
            'zeit_freigabe' => false,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(int $id)
    {
        $existing = DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->first();
        if (!$existing || !BereichScope::allowsWorker(request()->user(), (string) $existing->dienst_nr)) {
            return response()->json(['error' => 'Abwesenheit nicht gefunden'], 404);
        }
        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }
}
