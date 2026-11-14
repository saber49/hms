<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages company employees (Mitarbeiter).
 *
 * Endpoints:
 *  GET    /workers                      list (filterable)
 *  GET    /workers/stats                KPIs (active/inactive/birthdays/docs/...)
 *  GET    /workers/expiring-docs        flat list of expiring or expired docs
 *  GET    /workers/{id}                 one worker (with docs/abwesenheiten)
 *  GET    /workers/{id}/checklist       compliance — required docs status
 *  POST   /workers                      create (returns generated initial PW once)
 *  PUT    /workers/{id}                 patch — only sent fields touched
 *  DELETE /workers/{id}                 deactivate (soft) or permanent=1
 *  POST   /workers/reset-password       admin sets new pw for worker
 *  POST   /workers/upload-photo         photo upload
 */
class WorkerController extends Controller
{
    /** Required documents per worker (compliance checklist) */
    private const REQUIRED_DOC_TYPES = [
        'Arbeitsvertrag',
        'Personalausweis',
        'Steuer-ID',
        'SV-Nummer',
        'Krankenkassen-Bescheinigung',
        'Bankverbindung',
    ];

    private const ART_DER_BESCHAEFTIGUNG = [
        'Vollzeit', 'Vollzeit 160Std', 'Vollzeit 140Std',
        'Teilzeit', 'Teilzeit 80 Std.', 'Teilzeit 100 Std',
        'Minijob', 'Werkstudent', 'Werkstudientenvertrag', 'Auf Abruf',
    ];

    // ============================================================
    // INDEX (filterable)
    // ============================================================
    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $hasGeb = Schema::connection('tenant')->hasColumn('mitarbeiter', 'geburtstag');
        $hasAdr = Schema::connection('tenant')->hasColumn('mitarbeiter', 'strasse');

        $cols = ['id', 'dienst_nr', 'name', 'vorname', 'telefon', 'email',
                 'bereich', 'art_der_beschaeftigung', 'aktiv', 'foto',
                 'eintritt', 'urlaubstage', 'arbeitsstunden', 'arbeitsstunden_typ',
                 'familienstand', 'kinderzahl_freibetrag', 'steuerklasse',
                 'krankenkasse', 'sv_nummer', 'steuer_id', 'bank_verbindung',
                 'dfb_schulung', 'app_zugang', 'created_at'];
        // Hourly wage column added in 2026_05_18_090000 migration. Older
        // tenants may not have it yet, so we include it conditionally to
        // keep `index()` working everywhere.
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'stundenlohn')) {
            $cols[] = 'stundenlohn';
        }
        if ($hasGeb) $cols = array_merge($cols, ['geburtstag', 'geburtsort', 'staatsangehoerigkeit']);
        if ($hasAdr) $cols = array_merge($cols, ['strasse', 'plz', 'ort', 'austritt']);
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'bereiche')) {
            $cols[] = 'bereiche';
        }
        // Chosen Qualifikationen option ids (2026_10_16_120000 migration).
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'qualifikationen')) {
            $cols[] = 'qualifikationen';
        }

        // Identity + ID-document + security-guard fields added in
        // 2026_05_19_100000_add_security_guard_fields_to_mitarbeiter.
        // Conditional so older tenants without the migration still work.
        foreach ([
            'anrede', 'geschlecht', 'geburtsname', 'geburtsland',
            'personalausweis_nr', 'personalausweis_gueltig_ab', 'personalausweis_gueltig_bis',
            'mobil', 'land', 'bundesland',
            'bewacherregister_nr', 'gewo_qualifikation', 'dfb_pin', 'quasod_module', 'funkrufname', 'barcode', 'ordnernummer',
            'stadion_gesperrt',
        ] as $extraCol) {
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', $extraCol)) {
                $cols[] = $extraCol;
            }
        }

        // App usage tracking (2026_09_08 migration). Backfilled lazily from
        // the central token table so workers who logged in BEFORE the
        // column existed are recognised as app users too.
        $hasAppLogin = Schema::connection('tenant')->hasColumn('mitarbeiter', 'app_last_login');
        if ($hasAppLogin) {
            $this->backfillAppLogins();
            $cols[] = 'app_first_login';
            $cols[] = 'app_last_login';
        }

        $query = DB::connection('tenant')->table('mitarbeiter')->select($cols);

        // Per-user Bereich restriction: untagged workers form the shared
        // staffing pool; tagged workers need a matching Bereich or an
        // in-scope Einsatz they already work on.
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mitarbeiter');
        $query->whereRaw($wSql, $wParams);

        if ($request->has('aktiv') && $request->aktiv !== '') {
            $query->where('aktiv', $request->aktiv);
        }
        if ($request->filled('bereich')) {
            [$bSql, $bP] = BereichScope::tagMatch(
                'bereich',
                Schema::connection('tenant')->hasColumn('mitarbeiter', 'bereiche') ? 'bereiche' : null,
                [mb_strtolower(trim((string) $request->bereich))]
            );
            $query->whereRaw($bSql, $bP);
        }
        if ($request->filled('beschaeftigung')) {
            $query->where('art_der_beschaeftigung', 'like', $request->beschaeftigung . '%');
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('vorname', 'like', $s)
                  ->orWhere('dienst_nr', 'like', $s)
                  ->orWhere('email', 'like', $s)
                  ->orWhere('bereich', 'like', $s);
            });
        }

        return response()->json($query->orderBy('name')->orderBy('vorname')->get());
    }

    /**
     * One-shot backfill of app_first_login / app_last_login from the central
     * `personal_access_tokens` table (every worker-app login creates a token
     * row tagged with the tenant id). Cheap: only runs while at least one
     * app-enabled worker still has app_last_login = NULL, and is cached per
     * tenant for 10 minutes so the list endpoint stays fast.
     */
    private function backfillAppLogins(): void
    {
        $tenant = \App\Services\TenantService::current();
        if (!$tenant) {
            return;
        }
        $cacheKey = 'wk:app-login-backfill:'.$tenant->id;
        if (\Illuminate\Support\Facades\Cache::get($cacheKey)) {
            return;
        }
        \Illuminate\Support\Facades\Cache::put($cacheKey, 1, 600);

        try {
            $pending = DB::connection('tenant')->table('mitarbeiter')
                ->where('app_zugang', 1)->whereNull('app_last_login')->pluck('id');
            if ($pending->isEmpty()) {
                return;
            }
            $rows = DB::connection('mysql')->table('personal_access_tokens')
                ->selectRaw('tokenable_id, MIN(created_at) AS first_login, MAX(created_at) AS last_login')
                ->where('tokenable_type', 'worker')
                ->whereIn('tokenable_id', $pending->all())
                // abilities = {"0":"worker-app","tenant_id":<id>} — anchor on the
                // closing brace so tenant 5 never matches tenant 55.
                ->where('abilities', 'like', '%"tenant_id":'.(int) $tenant->id.'}%')
                ->groupBy('tokenable_id')
                ->get();
            foreach ($rows as $r) {
                DB::connection('tenant')->table('mitarbeiter')->where('id', $r->tokenable_id)->update([
                    'app_first_login' => $r->first_login,
                    'app_last_login' => $r->last_login,
                ]);
            }
        } catch (\Throwable $e) {
            // Best effort only — the badge simply stays red until the next login.
        }
    }

    // ============================================================
    // STATS — KPIs
    // ============================================================
    public function stats()
    {
        [$wSql, $wParams] = BereichScope::workerPredicate(request()->user(), 'mitarbeiter');
        $base = DB::connection('tenant')->table('mitarbeiter')->whereRaw($wSql, $wParams);

        $total = (clone $base)->count();
        $aktiv = (clone $base)->where('aktiv', 1)->count();
        $inaktiv = (clone $base)->where('aktiv', 0)->count();

        // Beschäftigungsverteilung
        $beschVerteilung = (clone $base)
            ->where('aktiv', 1)
            ->selectRaw('art_der_beschaeftigung as typ, COUNT(*) as anzahl')
            ->groupBy('art_der_beschaeftigung')
            ->orderByDesc('anzahl')
            ->get();

        // Birthdays this month (only if column exists)
        $birthdaysThisMonth = 0;
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'geburtstag')) {
            $birthdaysThisMonth = (clone $base)
                ->whereNotNull('geburtstag')
                ->where('aktiv', 1)
                ->whereRaw('MONTH(geburtstag) = MONTH(CURDATE())')
                ->count();
        }

        // Docs expiring/expired — active workers only (an inactive worker's
        // documents need no renewal).
        $docsExpired = 0;
        $docsExpiring = 0;
        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
            [$dSql, $dParams] = BereichScope::workerPredicate(request()->user(), 'm');
            $docsExpired = DB::connection('tenant')->table('mitarbeiter_dokumente as d')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'd.dienst_nr')
                ->whereRaw($dSql, $dParams)
                ->where('m.aktiv', 1)
                ->whereNotNull('d.ablaufdatum')
                ->whereDate('d.ablaufdatum', '<', now()->toDateString())
                ->count();
            $docsExpiring = DB::connection('tenant')->table('mitarbeiter_dokumente as d')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'd.dienst_nr')
                ->whereRaw($dSql, $dParams)
                ->where('m.aktiv', 1)
                ->whereNotNull('d.ablaufdatum')
                ->whereDate('d.ablaufdatum', '>=', now()->toDateString())
                ->whereDate('d.ablaufdatum', '<=', now()->addDays(60)->toDateString())
                ->count();
        }

        // DFB-Schulung soon expiring (assume valid 24 months)
        $dfbDueSoon = (clone $base)
            ->where('aktiv', 1)
            ->whereNotNull('dfb_schulung')
            ->whereRaw('DATE_ADD(dfb_schulung, INTERVAL 24 MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)')
            ->count();

        // New hires (last 30 days)
        $newHires = (clone $base)
            ->whereNotNull('eintritt')
            ->whereDate('eintritt', '>=', now()->subDays(30)->toDateString())
            ->count();

        // Avg urlaub usage (only workers with urlaubstage > 0 in current year)
        $avgUrlaubUsage = null;
        if (Schema::connection('tenant')->hasTable('mitarbeiter_abwesenheiten')) {
            [$mSql, $mParams] = BereichScope::workerPredicate(request()->user(), 'm');
            $year      = (int) now()->year;
            $yearStart = sprintf('%04d-01-01', $year);
            $yearEnd   = sprintf('%04d-12-31', $year);
            // SARGable date range so the (dienst_nr, startdatum) composite index
            // can satisfy the JOIN. YEAR() on the column would defeat the index.
            $row = DB::connection('tenant')
                ->select(
                    "SELECT AVG(usage_pct) AS avg_pct FROM (
                        SELECT m.dienst_nr,
                               (COALESCE(SUM(CASE WHEN a.typ = 'Urlaub' THEN a.tage ELSE 0 END), 0) /
                                NULLIF(m.urlaubstage, 0)) * 100 AS usage_pct
                        FROM mitarbeiter m
                        LEFT JOIN mitarbeiter_abwesenheiten a
                          ON a.dienst_nr = m.dienst_nr
                          AND a.startdatum BETWEEN ? AND ?
                        WHERE m.aktiv = 1 AND m.urlaubstage > 0
                          AND $mSql
                        GROUP BY m.dienst_nr, m.urlaubstage
                    ) t",
                    array_merge([$yearStart, $yearEnd], $mParams)
                );
            $avgUrlaubUsage = $row && isset($row[0]->avg_pct) ? round((float) $row[0]->avg_pct, 1) : null;
        }

        return response()->json([
            'total'                  => $total,
            'aktiv'                  => $aktiv,
            'inaktiv'                => $inaktiv,
            'beschaeftigung'         => $beschVerteilung,
            'birthdays_this_month'   => $birthdaysThisMonth,
            'docs_expired'           => $docsExpired,
            'docs_expiring'          => $docsExpiring,
            'dfb_due_soon'           => $dfbDueSoon,
            'new_hires_30d'          => $newHires,
            'avg_urlaub_usage_pct'   => $avgUrlaubUsage,
            'art_der_beschaeftigung' => self::ART_DER_BESCHAEFTIGUNG,
        ]);
    }

    // ============================================================
    // EXPIRING-DOCS — list of all docs expired or expiring soon
    // ============================================================
    public function expiringDocs(Request $request)
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
            return response()->json([]);
        }

        $days = (int) $request->input('within_days', 60);
        $limit = min(max((int) $request->input('limit', 200), 1), 1000);

        [$eSql, $eParams] = BereichScope::workerPredicate($request->user(), 'm');
        $rows = DB::connection('tenant')
            ->table('mitarbeiter_dokumente as d')
            ->leftJoin('mitarbeiter as m', 'd.dienst_nr', '=', 'm.dienst_nr')
            ->select(
                'd.id', 'd.dienst_nr', 'd.titel', 'd.dok_typ', 'd.ablaufdatum',
                'd.gueltig_ab', 'd.dateipfad', 'd.dateiname',
                'm.name', 'm.vorname', 'm.foto', 'm.aktiv', 'm.bereich'
            )
            ->whereRaw($eSql, $eParams)
            ->where('m.aktiv', 1)
            ->whereNotNull('d.ablaufdatum')
            ->where(function ($q) use ($days) {
                $q->whereDate('d.ablaufdatum', '<', now()->toDateString())
                  ->orWhereBetween('d.ablaufdatum', [now()->toDateString(), now()->addDays($days)->toDateString()]);
            })
            ->orderBy('d.ablaufdatum')
            ->limit($limit)
            ->get();

        // Add status enum
        $today = now()->startOfDay();
        $rows->transform(function ($r) use ($today) {
            $abl = \Carbon\Carbon::parse($r->ablaufdatum)->startOfDay();
            $diff = $today->diffInDays($abl, false);
            if ($diff < 0)        $r->status = 'expired';
            elseif ($diff <= 30)  $r->status = 'expiring-30d';
            else                  $r->status = 'expiring-soon';
            $r->days_remaining = $diff;
            return $r;
        });

        return response()->json($rows);
    }

    // ============================================================
    // SHOW — one worker with docs + abwesenheiten summary
    // ============================================================
    public function show(string $id)
    {
        $worker = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
        if (!$worker || !BereichScope::allowsWorker(request()->user(), $id)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $worker = (array) $worker;
        // Strip sensitive
        unset($worker['password_hash'], $worker['password_plain']);

        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
            $worker['dokumente'] = DB::connection('tenant')
                ->table('mitarbeiter_dokumente')
                ->where('dienst_nr', $id)
                ->orderByDesc('hochgeladen_am')
                ->get();
        } else {
            $worker['dokumente'] = [];
        }

        return response()->json($worker);
    }

    // ============================================================
    // CHECKLIST — required docs status
    // ============================================================
    public function checklist(string $id)
    {
        $worker = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
        if (!$worker || !BereichScope::allowsWorker(request()->user(), $id)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $docs = Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')
            ? DB::connection('tenant')->table('mitarbeiter_dokumente')->where('dienst_nr', $id)->get()
            : collect();

        $today = now()->startOfDay();
        $items = [];
        $okCount = 0;
        $expiringCount = 0;
        $expiredCount = 0;
        $missingCount = 0;

        foreach (self::REQUIRED_DOC_TYPES as $reqType) {
            // Match by dok_typ if set, else by titel substring
            $found = $docs->first(function ($d) use ($reqType) {
                if (!empty($d->dok_typ) && $d->dok_typ === $reqType) return true;
                return stripos($d->titel ?? '', $reqType) !== false;
            });

            $status = 'missing';
            if ($found) {
                if ($found->ablaufdatum) {
                    $abl = \Carbon\Carbon::parse($found->ablaufdatum)->startOfDay();
                    $diff = $today->diffInDays($abl, false);
                    if ($diff < 0) $status = 'expired';
                    elseif ($diff <= 60) $status = 'expiring';
                    else $status = 'ok';
                } else {
                    $status = 'ok';
                }
            }

            switch ($status) {
                case 'ok':       $okCount++; break;
                case 'expiring': $expiringCount++; break;
                case 'expired':  $expiredCount++; break;
                case 'missing':  $missingCount++; break;
            }

            $items[] = [
                'dok_typ' => $reqType,
                'status'  => $status,
                'doc'     => $found,
            ];
        }

        return response()->json([
            'checklist' => $items,
            'summary' => [
                'total_required' => count(self::REQUIRED_DOC_TYPES),
                'ok'             => $okCount,
                'expiring'       => $expiringCount,
                'expired'        => $expiredCount,
                'missing'        => $missingCount,
            ],
        ]);
    }

    // ============================================================
    // ACTIVITY — per-worker analytics (shifts, hours, history)
    // Aggregates the worker's Einsätze (assignments) into KPIs, a
    // monthly hours trend, recent + upcoming shifts and top customers.
    // Hours = ONLY what was really recorded for this worker (em.ist_std,
    // app clock or office) — never the plan, same rule as the Ist in
    // Zeiterfassung. A shift counts as done when it lies before today or
    // already carries recorded hours; cancelled (grey) shifts are ignored.
    // ============================================================
    public function activity(Request $request, string $id)
    {
        $worker = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
        if (!$worker || !BereichScope::allowsWorker($request->user(), $id)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // Tenants without the assignment tables get a valid empty shell so
        // the frontend can show a friendly "no data" state.
        if (!Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')
            || !Schema::connection('tenant')->hasTable('einsaetze')) {
            return response()->json([
                'available'     => false,
                'kpis'          => null,
                'monthly'       => [],
                'recent'        => [],
                'upcoming_list' => [],
                'top_kunden'    => [],
            ]);
        }

        $today      = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $months     = max(1, min(12, (int) $request->input('months', 6)));
        $rangeStart = now()->startOfMonth()->subMonths($months - 1)->toDateString();

        // Recorded hours only (NULL / 0 = nothing recorded → 0 h).
        $stdExpr = "COALESCE(em.ist_std, 0)";
        $live = Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')
            ? ' AND e.bestell_storno_at IS NULL' : '';
        // Done = before today, or today with recorded hours.
        $done = "e.datum <= ? AND (e.datum < ? OR COALESCE(em.ist_std, 0) > 0)";
        // Open = today without recorded hours, or later.
        $open = "e.datum >= ? AND NOT (e.datum = ? AND COALESCE(em.ist_std, 0) > 0)";

        // --- All-time totals ---
        $totals = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS shifts,
                   COALESCE(SUM($stdExpr),0) AS hours,
                   SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN 1 ELSE 0 END) AS confirmed,
                   MIN(e.datum) AS first_datum,
                   MAX(e.datum) AS last_datum
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
             WHERE em.dienst_nr = ? AND $done $live
        ", [$id, $today, $today]);

        // --- This calendar month ---
        $thisMonth = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS shifts, COALESCE(SUM($stdExpr),0) AS hours
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
             WHERE em.dienst_nr = ? AND e.datum >= ? AND $done $live
        ", [$id, $monthStart, $today, $today]);

        // --- Upcoming count ---
        $upcomingCount = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS c
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
             WHERE em.dienst_nr = ? AND $open $live
        ", [$id, $today, $today]);

        // --- Monthly trend (last N months, zero-filled) ---
        $monthlyRaw = DB::connection('tenant')->select("
            SELECT DATE_FORMAT(e.datum, '%Y-%m') AS ym,
                   COUNT(*) AS shifts,
                   COALESCE(SUM($stdExpr),0) AS hours
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
             WHERE em.dienst_nr = ? AND e.datum >= ? AND $done $live
             GROUP BY ym ORDER BY ym ASC
        ", [$id, $rangeStart, $today, $today]);
        $byYm = [];
        foreach ($monthlyRaw as $m) $byYm[$m->ym] = $m;
        $monthly = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $monthly[] = [
                'ym'     => $ym,
                'shifts' => (int) ($byYm[$ym]->shifts ?? 0),
                'hours'  => round((float) ($byYm[$ym]->hours ?? 0), 1),
            ];
        }

        // --- Recent shifts (past/today) ---
        $recent = DB::connection('tenant')->select("
            SELECT e.datum, e.hms_bestaetigt, e.pruefung,
                   COALESCE(em.ist_start, e.ist_start) AS start_zeit,
                   COALESCE(em.ist_ende,  e.ist_ende)  AS end_zeit,
                   NULLIF(em.ist_std, 0) AS std,
                   k.kundenname, o.objektname
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
              LEFT JOIN kunden  k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id    = o.obj_id
             WHERE em.dienst_nr = ? AND $done $live
             ORDER BY e.datum DESC, start_zeit DESC
             LIMIT 10
        ", [$id, $today, $today]);

        // --- Upcoming shifts ---
        $upcoming = DB::connection('tenant')->select("
            SELECT e.datum,
                   COALESCE(em.ist_start, e.ist_start) AS start_zeit,
                   COALESCE(em.ist_ende,  e.ist_ende)  AS end_zeit,
                   k.kundenname, o.objektname
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
              LEFT JOIN kunden  k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id    = o.obj_id
             WHERE em.dienst_nr = ? AND $open $live
             ORDER BY e.datum ASC, start_zeit ASC
             LIMIT 5
        ", [$id, $today, $today]);

        // --- Top customers by shift count ---
        $topKunden = DB::connection('tenant')->select("
            SELECT COALESCE(k.kundenname, '—') AS name,
                   COUNT(*) AS shifts,
                   COALESCE(SUM($stdExpr),0) AS hours
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
              LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
             WHERE em.dienst_nr = ? AND $done $live
             GROUP BY k.kunde_id, k.kundenname
             ORDER BY shifts DESC
             LIMIT 5
        ", [$id, $today, $today]);

        $mapShift = function ($r, $withStd = true) {
            $row = [
                'datum'  => $r->datum,
                'kunde'  => $r->kundenname,
                'objekt' => $r->objektname,
                'start'  => !empty($r->start_zeit) ? substr($r->start_zeit, 0, 5) : null,
                'ende'   => !empty($r->end_zeit) ? substr($r->end_zeit, 0, 5) : null,
            ];
            if ($withStd) {
                $row['stunden'] = isset($r->std) && $r->std !== null ? round((float) $r->std, 1) : null;
                $row['status']  = ($r->hms_bestaetigt ?? '') === 'ja'
                    ? 'bestätigt'
                    : (((string) ($r->pruefung ?? '')) === 'geplant' ? 'geplant' : ($r->hms_bestaetigt ?: '–'));
            }
            return $row;
        };

        return response()->json([
            'available' => true,
            'kpis' => [
                'shifts_total' => (int) ($totals->shifts ?? 0),
                'hours_total'  => round((float) ($totals->hours ?? 0), 1),
                'confirmed'    => (int) ($totals->confirmed ?? 0),
                'first_datum'  => $totals->first_datum ?? null,
                'last_datum'   => $totals->last_datum ?? null,
                'month_shifts' => (int) ($thisMonth->shifts ?? 0),
                'month_hours'  => round((float) ($thisMonth->hours ?? 0), 1),
                'upcoming'     => (int) ($upcomingCount->c ?? 0),
            ],
            'monthly'       => $monthly,
            'recent'        => array_map(fn($r) => $mapShift($r, true), $recent),
            'upcoming_list' => array_map(fn($r) => $mapShift($r, false), $upcoming),
            'top_kunden'    => array_map(fn($r) => [
                'name'   => $r->name,
                'shifts' => (int) $r->shifts,
                'hours'  => round((float) $r->hours, 1),
            ], $topKunden),
        ]);
    }

    // ============================================================
    // STORE — create
    // ============================================================
    public function store(Request $request)
    {
        $data = $request->all();
        if (!BereichScope::bereichInputAllowed($request->user(), $data['bereich'] ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (!BereichScope::bereicheInputAllowed($request->user(), $data['bereiche'] ?? [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        $errors = $this->validateData($data, false);
        if (!empty($errors)) {
            return response()->json(['error' => implode(' · ', $errors), 'errors' => $errors], 422);
        }

        try {
            $dienst_nr = IdGenerator::next('mitarbeiter', 'dienst_nr', 'MA');

            // Cryptographically random initial password
            $initialPassword = $this->generateInitialPassword();

            $row = $this->buildRow($data, false);
            // Multi-service tags (bereich remains the primary tag).
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'bereiche')) {
                $list = BereichScope::bereicheForStorage($data['bereiche'] ?? [], $row['bereich'] ?? null);
                $row['bereiche'] = $list ? json_encode($list) : null;
            }
            if (array_key_exists('qualifikationen', $data)
                && Schema::connection('tenant')->hasColumn('mitarbeiter', 'qualifikationen')) {
                $q = $this->qualifikationenForStorage($request->user(), $data['qualifikationen'], []);
                $row['qualifikationen'] = $q ? json_encode($q) : null;
            }
            $row['dienst_nr'] = $dienst_nr;
            $row['password_hash'] = Hash::make($initialPassword);
            $row['app_zugang'] = (int)($data['app_zugang'] ?? 1);
            $row['aktiv'] = (int)($data['aktiv'] ?? 1);
            // Systemic rule: an Austritt date always means inactive.
            if (!empty($row['austritt'])) $row['aktiv'] = 0;

            DB::connection('tenant')->table('mitarbeiter')->insert($row);

            return response()->json([
                'success'         => true,
                'dienst_nr'       => $dienst_nr,
                'initial_password' => $initialPassword,
                'note' => 'Bitte das Initial-Passwort kopieren — es wird nicht erneut angezeigt.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Mitarbeiter konnte nicht erstellt werden: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // UPDATE — patch only sent fields
    // ============================================================
    public function update(Request $request, string $id)
    {
        $data = $request->all();

        $existing = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
        if (!$existing || !BereichScope::allowsWorker($request->user(), $id)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }
        if (array_key_exists('bereich', $data)
            && !BereichScope::bereichWriteAllowed($request->user(), $data['bereich'], $existing->bereich ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (array_key_exists('bereiche', $data)
            && !BereichScope::bereicheWriteAllowed(
                $request->user(), $data['bereiche'] ?? [],
                json_decode($existing->bereiche ?? '[]', true) ?: [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }

        $errors = $this->validateData($data, true, $id);
        if (!empty($errors)) {
            return response()->json(['error' => implode(' · ', $errors), 'errors' => $errors], 422);
        }

        $row = $this->buildRow($data, true);
        // Multi-service tags: the picker's list is merged with stored tags
        // outside the user's scope so his save never strips them.
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'bereiche')) {
            if (array_key_exists('bereiche', $data)) {
                $keep = BereichScope::outOfScopeValues(
                    $request->user(), json_decode($existing->bereiche ?? '[]', true) ?: []);
                $list = BereichScope::bereicheForStorage(
                    array_merge((array) ($data['bereiche'] ?? []), $keep),
                    $row['bereich'] ?? $existing->bereich);
                $row['bereiche'] = $list ? json_encode($list) : null;
            } elseif (array_key_exists('bereich', $data)) {
                // Primary tag changed without a list (older client) — merge
                // the new primary into the stored service list.
                $existingList = json_decode($existing->bereiche ?? '[]', true) ?: [];
                $list = BereichScope::bereicheForStorage($existingList, $row['bereich'] ?? $existing->bereich);
                $row['bereiche'] = $list ? json_encode($list) : null;
            }
        }
        if (array_key_exists('qualifikationen', $data)
            && Schema::connection('tenant')->hasColumn('mitarbeiter', 'qualifikationen')) {
            $q = $this->qualifikationenForStorage(
                $request->user(), $data['qualifikationen'],
                json_decode($existing->qualifikationen ?? '[]', true) ?: []);
            $row['qualifikationen'] = $q ? json_encode($q) : null;
        }
        if (empty($row)) {
            return response()->json(['error' => 'Keine Felder zum Aktualisieren'], 400);
        }

        // Systemic rule: an Austritt date always means inactive — enforced
        // server-side so no client (UI, import, direct API) can bypass it.
        // Uses the EFFECTIVE value: the patched one if sent, else the stored one.
        $effAustritt = array_key_exists('austritt', $row) ? $row['austritt'] : ($existing->austritt ?? null);
        if (!empty($effAustritt)) $row['aktiv'] = 0;

        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'geaendert_am')) {
            $row['geaendert_am'] = now();
        }

        DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->update($row);

        return response()->json(['success' => true]);
    }

    // ============================================================
    // DESTROY — soft (deactivate) or permanent=1
    // ============================================================
    public function destroy(Request $request, string $id)
    {
        if (!BereichScope::allowsWorker($request->user(), $id)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }
        if ($request->boolean('permanent')) {
            // Workers with shifts or clock records stay: deleting them would
            // drop their hours from Zeiterfassung, Abrechnung and every PDF
            // (all join `mitarbeiter`). Deactivate instead.
            $einsaetze = Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')
                ? DB::connection('tenant')->table('einsatz_mitarbeiter')->where('dienst_nr', $id)->count() : 0;
            $zeiten = Schema::connection('tenant')->hasTable('zeiterfassung')
                ? DB::connection('tenant')->table('zeiterfassung')->where('dienst_nr', $id)->count() : 0;
            // Equipment still issued (Lager) must come back first.
            $lagerOffen = Schema::connection('tenant')->hasTable('lager_ausgaben')
                ? (int) DB::connection('tenant')->table('lager_ausgaben')->where('mitarbeiter_dienst_nr', $id)->where('status', 'offen')->sum('menge') : 0;
            if ($lagerOffen > 0) {
                return response()->json([
                    'error' => "Löschen nicht möglich: {$lagerOffen} ausgegebene Ausrüstungsteile (Lager) sind noch nicht zurückgegeben.",
                    'lager_offen' => $lagerOffen,
                ], 409);
            }
            if ($einsaetze > 0 || $zeiten > 0) {
                return response()->json([
                    'error' => "Löschen nicht möglich: {$einsaetze} Einsätze und {$zeiten} Zeiterfassungen gehören zu diesem Mitarbeiter. Bitte stattdessen deaktivieren.",
                    'einsaetze' => $einsaetze, 'zeiten' => $zeiten,
                ], 409);
            }
            DB::connection('tenant')->beginTransaction();
            try {
                if (Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
                    DB::connection('tenant')->table('mitarbeiter_dokumente')->where('dienst_nr', $id)->delete();
                }
                if (Schema::connection('tenant')->hasTable('mitarbeiter_abwesenheiten')) {
                    DB::connection('tenant')->table('mitarbeiter_abwesenheiten')->where('dienst_nr', $id)->delete();
                }
                if (Schema::connection('tenant')->hasTable('mitarbeiter_nachrichten')) {
                    DB::connection('tenant')->table('mitarbeiter_nachrichten')->where('dienst_nr', $id)->delete();
                }
                DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->delete();
                DB::connection('tenant')->commit();
                return response()->json(['success' => true, 'mode' => 'deleted']);
            } catch (\Exception $e) {
                DB::connection('tenant')->rollBack();
                return response()->json(['error' => 'Löschen fehlgeschlagen: ' . $e->getMessage()], 500);
            }
        }

        $update = ['aktiv' => 0];
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'austritt')) {
            $existing = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->first();
            if ($existing && empty($existing->austritt)) {
                $update['austritt'] = now()->toDateString();
            }
        }
        DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $id)->update($update);

        // Still assigned to upcoming shifts → the UI warns to re-plan them.
        $future = 0;
        if (Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')) {
            $q = DB::connection('tenant')->table('einsatz_mitarbeiter as em')
                ->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
                ->where('em.dienst_nr', $id)->where('e.datum', '>=', now()->toDateString());
            if (Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) $q->whereNull('e.bestell_storno_at');
            $future = $q->count();
        }
        // Equipment still issued → the UI reminds to collect it (Lager → Rückgabe).
        $lagerOffen = Schema::connection('tenant')->hasTable('lager_ausgaben')
            ? (int) DB::connection('tenant')->table('lager_ausgaben')->where('mitarbeiter_dienst_nr', $id)->where('status', 'offen')->sum('menge') : 0;
        return response()->json(['success' => true, 'mode' => 'deactivated', 'future_shifts' => $future, 'lager_offen' => $lagerOffen]);
    }

    // ============================================================
    // RESET PASSWORD
    // ============================================================
    public function resetPassword(Request $request)
    {
        $dienst_nr = $request->input('dienst_nr', '');
        $newPassword = $request->input('password', '');

        if (!$dienst_nr) return response()->json(['error' => 'dienst_nr erforderlich'], 400);
        if (strlen($newPassword) < 8) {
            return response()->json(['error' => 'Passwort muss mindestens 8 Zeichen haben'], 400);
        }
        if (!BereichScope::allowsWorker($request->user(), $dienst_nr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $dienst_nr)->update([
            'password_hash' => Hash::make($newPassword),
        ]);

        return response()->json(['success' => true]);
    }

    // ============================================================
    // BARCODE SUGGEST
    // ------------------------------------------------------------
    // Returns a 7-character alphanumeric code (uppercase + digits)
    // that is currently NOT in use on any active OR inactive worker
    // in the calling tenant. The generated value is purely a
    // suggestion — the front-end pre-fills it into the input but the
    // user can still type their own barcode and we validate uniqueness
    // again at save time inside update()/store().
    //
    // Charset is restricted to 32 unambiguous symbols (we strip the
    // visually confusable 0/O, 1/I/L) so the value scans reliably from
    // a printed badge and is also readable when typed by a human:
    //
    //     2 3 4 5 6 7 8 9
    //     A B C D E F G H J K M N P Q R T U V W X Y Z
    //
    // Collision probability with 32^7 ≈ 34 billion combos vs a few
    // hundred employees is negligible, but we still loop up to 30
    // times defensively. If we somehow can't find a free slot, we
    // surface a 503 so the UI shows a clear error rather than silently
    // colliding at save.
    // ============================================================
    public function suggestBarcode(Request $request)
    {
        if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'barcode')) {
            return response()->json([
                'error' => 'Die Spalte „barcode" existiert in dieser Mandantendatenbank nicht.',
            ], 422);
        }

        $alphabet    = '23456789ABCDEFGHJKMNPQRTUVWXYZ';
        $alphabetLen = strlen($alphabet);
        $maxAttempts = 30;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= $alphabet[random_int(0, $alphabetLen - 1)];
            }
            $exists = DB::connection('tenant')->table('mitarbeiter')
                ->where('barcode', $code)
                ->exists();
            if (!$exists) {
                return response()->json(['barcode' => $code]);
            }
        }

        return response()->json([
            'error' => 'Konnte keinen eindeutigen Barcode erzeugen — bitte erneut versuchen.',
        ], 503);
    }

    // ============================================================
    // PHOTO UPLOAD
    // ============================================================
    public function uploadPhoto(Request $request)
    {
        try {
            $dienst_nr = $request->input('dienst_nr');
            if (!$dienst_nr) return response()->json(['error' => 'dienst_nr erforderlich'], 400);

            $worker = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $dienst_nr)->first();
            if (!$worker || !BereichScope::allowsWorker($request->user(), $dienst_nr)) {
                return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
            }

            if (!$request->hasFile('foto')) {
                return response()->json(['error' => 'Keine Datei empfangen'], 400);
            }

            $request->validate(['foto' => 'required|image|max:5120']);

            $tenant = \App\Services\TenantService::current();
            $tenantSlug = $tenant ? $tenant->slug : 'default';

            $file = $request->file('foto');
            $ext = $file->getClientOriginalExtension();
            $filename = 'foto_' . time() . '.' . $ext;
            $storagePath = 'uploads/workers/' . $tenantSlug . '/' . $dienst_nr;
            $filepath = $storagePath . '/' . $filename;

            $stored = $file->storeAs($storagePath, $filename, 'public');
            if (!$stored) {
                return response()->json(['error' => 'Datei konnte nicht gespeichert werden'], 500);
            }

            if ($worker->foto && $worker->foto !== $filepath) {
                Storage::disk('public')->delete($worker->foto);
            }

            DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $dienst_nr)->update(['foto' => $filepath]);

            return response()->json(['success' => true, 'foto' => $filepath]);
        } catch (\Exception $e) {
            \Log::error('Worker photo upload failed: ' . $e->getMessage());
            return response()->json(['error' => 'Upload fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // EXPORT — CSV (Lohn-style)
    // ============================================================
    public function export(Request $request)
    {
        [$xSql, $xParams] = BereichScope::workerPredicate($request->user(), 'mitarbeiter');
        $rows = DB::connection('tenant')->table('mitarbeiter')
            ->whereRaw($xSql, $xParams)
            ->orderBy('name')->orderBy('vorname')->get();

        $headers = ['Dienst-Nr', 'Name', 'Vorname', 'Eintritt', 'Austritt',
                    'Beschäftigung', 'Std-Typ', 'Std', 'Urlaubstage',
                    'Familienstand', 'Kinder', 'Steuerklasse',
                    'Steuer-ID', 'SV-Nummer', 'Krankenkasse', 'IBAN',
                    'Telefon', 'E-Mail', 'Bereich', 'Aktiv'];

        $esc = function ($v) {
            if ($v === null) return '';
            $s = str_replace('"', '""', (string) $v);
            return preg_match('/[",;\r\n]/', $s) ? '"' . $s . '"' : $s;
        };

        $out = "\xEF\xBB\xBF" . implode(';', $headers) . "\r\n";
        foreach ($rows as $r) {
            $out .= implode(';', array_map($esc, [
                $r->dienst_nr, $r->name, $r->vorname,
                $r->eintritt, $r->austritt ?? '',
                $r->art_der_beschaeftigung,
                $r->arbeitsstunden_typ, $r->arbeitsstunden,
                $r->urlaubstage,
                $r->familienstand, $r->kinderzahl_freibetrag,
                $r->steuerklasse, $r->steuer_id, $r->sv_nummer,
                $r->krankenkasse, $r->bank_verbindung,
                $r->telefon, $r->email, $r->bereich,
                $r->aktiv ? 'Ja' : 'Nein',
            ])) . "\r\n";
        }

        return response($out, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="mitarbeiter_' . now()->toDateString() . '.csv"',
        ]);
    }

    // ============================================================
    // HELPERS
    // ============================================================
    protected function generateInitialPassword(): string
    {
        // 10-char password: 2 upper, 4 lower, 2 digits, 2 symbols, shuffled
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%&*';
        $pw  = '';
        $pw .= $this->randSlice($upper, 2);
        $pw .= $this->randSlice($lower, 4);
        $pw .= $this->randSlice($digits, 2);
        $pw .= $this->randSlice($symbols, 2);
        return str_shuffle($pw);
    }

    private function randSlice(string $chars, int $count): string
    {
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    /**
     * Build the columns array for INSERT/UPDATE — only allowed fields,
     * nulls preserved properly.
     */
    /**
     * Qualifikationen payload → sorted unique list of KNOWN option ids.
     * A restricted user only edits options of his own mains; stored options
     * of foreign mains are kept so his save never strips them.
     *
     * @return array<int,int>
     */
    private function qualifikationenForStorage($user, $input, array $existing): array
    {
        $map = BereichQualifikationController::optionMainMap(); // option id → main id
        $allowed = BereichScope::for($user);
        $mainName = [];
        if ($allowed !== null) {
            $mainName = DB::connection('tenant')->table('bereiche_katalog')
                ->whereNull('parent_id')->pluck('name', 'id')->all();
        }
        $inScope = fn (int $optId) => $allowed === null
            || BereichScope::listAllows($allowed, $mainName[$map[$optId] ?? 0] ?? null);

        $out = [];
        foreach ((array) $input as $v) {
            $id = (int) $v;
            if ($id > 0 && isset($map[$id]) && $inScope($id)) {
                $out[$id] = $id;
            }
        }
        foreach ($existing as $v) {
            $id = (int) $v;
            if ($id > 0 && isset($map[$id]) && !$inScope($id)) {
                $out[$id] = $id;
            }
        }
        sort($out);
        return array_values($out);
    }

    private function buildRow(array $data, bool $isUpdate): array
    {
        $allowedAlways = [
            'name', 'vorname', 'telefon', 'email', 'bereich',
            'dfb_schulung', 'steuer_id', 'sv_nummer', 'krankenkasse',
            'bank_verbindung', 'steuerklasse', 'kinderzahl_freibetrag',
            'familienstand', 'eintritt', 'art_der_beschaeftigung',
            'urlaubstage', 'arbeitsstunden_typ', 'arbeitsstunden',
        ];
        $allowedIfExist = [
            'geburtstag', 'geburtsort', 'staatsangehoerigkeit',
            'strasse', 'plz', 'ort', 'austritt',
            // Stundenlohn (€/h) — used by the Controlling module to compute
            // internal MA-Kosten per Einsatz; column added in the 2026_05_18
            // migration. Conditional so this controller stays compatible
            // with older tenants that haven't migrated yet.
            'stundenlohn', 'lohn_typ',
            // Security-guard / Bewachung extension (2026_05_19 migration).
            // §34a GewO + GewSchV-compliant identity + ID-document fields
            // plus DFB stadium-guard fields. All optional; the controller
            // skips any column the tenant hasn't migrated yet.
            'anrede', 'geschlecht', 'geburtsname', 'geburtsland',
            'personalausweis_nr', 'personalausweis_gueltig_ab', 'personalausweis_gueltig_bis',
            'mobil', 'land', 'bundesland',
            'bewacherregister_nr', 'gewo_qualifikation', 'dfb_pin', 'quasod_module', 'funkrufname', 'barcode', 'ordnernummer',
        ];
        $bools = ['aktiv', 'app_zugang'];

        $row = [];
        foreach ($allowedAlways as $f) {
            if ($isUpdate) {
                if (array_key_exists($f, $data)) {
                    $row[$f] = $this->normalizeNullable($data[$f]);
                }
            } else {
                $row[$f] = $this->normalizeNullable($data[$f] ?? null);
            }
        }
        foreach ($allowedIfExist as $f) {
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', $f)) {
                if ($isUpdate) {
                    if (array_key_exists($f, $data)) {
                        $row[$f] = $this->normalizeNullable($data[$f]);
                    }
                } else {
                    $row[$f] = $this->normalizeNullable($data[$f] ?? null);
                }
            }
        }
        foreach (['stundenlohn', 'arbeitsstunden', 'urlaubstage', 'kinderzahl_freibetrag'] as $numericField) {
            if (isset($row[$numericField]) && is_string($row[$numericField])) {
                $row[$numericField] = str_replace(',', '.', $row[$numericField]);
            }
        }
        // Stadium ban (2026_10_19_100000) — a warning flag, separate from
        // the catalog qualifications.
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'stadion_gesperrt')
            && ($isUpdate ? array_key_exists('stadion_gesperrt', $data) : true)) {
            $row['stadion_gesperrt'] = (int) (bool) ($data['stadion_gesperrt'] ?? 0);
        }
        foreach ($bools as $f) {
            if (array_key_exists($f, $data)) {
                $row[$f] = (int) (bool) ($data[$f] ?? 0);
            } elseif (!$isUpdate) {
                $row[$f] = $f === 'aktiv' ? 1 : 1;
            }
        }

        // quasod_module is a JSON column. Frontends may post it as a raw
        // array or as a pre-encoded JSON string. Normalize to a JSON
        // string (or NULL) so the underlying query builder doesn't try
        // to bind a PHP array.
        if (array_key_exists('quasod_module', $row)) {
            if (is_array($row['quasod_module'])) {
                $row['quasod_module'] = $row['quasod_module']
                    ? json_encode(array_values($row['quasod_module']))
                    : null;
            } elseif (is_string($row['quasod_module'])) {
                $decoded = json_decode($row['quasod_module'], true);
                $row['quasod_module'] = is_array($decoded) && $decoded
                    ? json_encode(array_values($decoded))
                    : null;
            }
        }
        return $row;
    }

    private function normalizeNullable($v)
    {
        if ($v === '' || $v === null) return null;
        return $v;
    }

    /**
     * Server-side validation. Returns array of error messages.
     */
    private function validateData(array $data, bool $isUpdate, ?string $id = null): array
    {
        $errors = [];

        if (!$isUpdate || array_key_exists('name', $data)) {
            if (empty(trim((string)($data['name'] ?? '')))) $errors[] = 'Nachname ist erforderlich';
        }
        if (!$isUpdate || array_key_exists('vorname', $data)) {
            if (empty(trim((string)($data['vorname'] ?? '')))) $errors[] = 'Vorname ist erforderlich';
        }

        if (!empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'E-Mail: ungültiges Format';
            } else {
                $exists = DB::connection('tenant')->table('mitarbeiter')
                    ->where('email', $data['email'])
                    ->when($id, fn($q) => $q->where('dienst_nr', '<>', $id))
                    ->exists();
                if ($exists) $errors[] = 'E-Mail wird bereits verwendet';
            }
        }

        if (!empty($data['telefon'])) {
            if (!preg_match('/^[\d\s\+\-\(\)\/]{6,30}$/', $data['telefon'])) {
                $errors[] = 'Telefon: ungültiges Format';
            }
        }

        if (!empty($data['steuer_id'])) {
            $clean = preg_replace('/\s/', '', $data['steuer_id']);
            if (!preg_match('/^\d{11}$/', $clean)) {
                $errors[] = 'Steuer-ID: muss genau 11 Ziffern haben';
            }
        }

        if (!empty($data['sv_nummer'])) {
            $clean = preg_replace('/\s/', '', $data['sv_nummer']);
            if (!preg_match('/^\d{8}[A-Za-z]\d{3}$/', $clean)) {
                $errors[] = 'SV-Nummer: ungültiges Format (8 Ziffern + 1 Buchstabe + 3 Ziffern)';
            }
        }

        if (!empty($data['bank_verbindung'])) {
            $iban = strtoupper(preg_replace('/\s/', '', $data['bank_verbindung']));
            if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $iban)) {
                $errors[] = 'IBAN: ungültiges Format';
            }
        }

        if (isset($data['steuerklasse']) && $data['steuerklasse'] !== '' && $data['steuerklasse'] !== null) {
            if (!is_numeric($data['steuerklasse']) || $data['steuerklasse'] < 1 || $data['steuerklasse'] > 6) {
                $errors[] = 'Steuerklasse: 1–6';
            }
        }
        if (isset($data['urlaubstage']) && $data['urlaubstage'] !== '' && $data['urlaubstage'] !== null) {
            if (!is_numeric($data['urlaubstage']) || (float) $data['urlaubstage'] < 0 || (float) $data['urlaubstage'] > 366) {
                $errors[] = 'Urlaubstage: 0–366 erlaubt';
            }
        }
        if (!empty($data['arbeitsstunden_typ']) && !in_array($data['arbeitsstunden_typ'], ['wöchentlich', 'monatlich'])) {
            $errors[] = 'Arbeitsstunden-Typ: wöchentlich oder monatlich';
        }
        if (isset($data['arbeitsstunden']) && $data['arbeitsstunden'] !== '' && $data['arbeitsstunden'] !== null) {
            $hours = is_string($data['arbeitsstunden']) ? str_replace(',', '.', $data['arbeitsstunden']) : $data['arbeitsstunden'];
            $maxHours = ($data['arbeitsstunden_typ'] ?? null) === 'wöchentlich' ? 168 : 744;
            if (!is_numeric($hours) || (float) $hours <= 0 || (float) $hours > $maxHours) {
                $errors[] = 'Arbeitsstunden: Wert außerhalb des gültigen Bereichs';
            }
        }
        // Stundenlohn — must be a non-negative number (allow 0 to mark
        // "nicht berücksichtigen"; also accepts the German decimal comma).
        if (array_key_exists('stundenlohn', $data) && $data['stundenlohn'] !== '' && $data['stundenlohn'] !== null) {
            $val = is_string($data['stundenlohn'])
                ? str_replace(',', '.', $data['stundenlohn'])
                : $data['stundenlohn'];
            if (!is_numeric($val) || (float) $val < 0 || (float) $val > 99999999.99) {
                $errors[] = 'Stundenlohn/Gehalt: ungültiger Betrag';
            }
        }
        if (!empty($data['eintritt']) && !strtotime($data['eintritt'])) {
            $errors[] = 'Eintritt: ungültiges Datum';
        }

        // ---- Security-guard / Bewachung extension validations -----------
        if (!empty($data['geschlecht'])
            && !in_array($data['geschlecht'], ['m', 'w', 'd', 'k'])) {
            $errors[] = 'Geschlecht: m / w / d / k erlaubt';
        }
        if (!empty($data['anrede'])
            && !in_array($data['anrede'], ['Herr', 'Frau', 'Divers', 'neutral'])) {
            $errors[] = 'Anrede: Herr / Frau / Divers / neutral erlaubt';
        }
        if (!empty($data['land']) && !preg_match('/^[A-Za-z]{2,5}$/', $data['land'])) {
            $errors[] = 'Land: ungültiger Code (z. B. DE, AT, CH)';
        }
        // Personalausweis date sanity: gueltig_bis must be ≥ gueltig_ab.
        // Both are optional, but if both are set, enforce the ordering.
        if (!empty($data['personalausweis_gueltig_ab'])
            && !strtotime($data['personalausweis_gueltig_ab'])) {
            $errors[] = 'Personalausweis gültig ab: ungültiges Datum';
        }
        if (!empty($data['personalausweis_gueltig_bis'])
            && !strtotime($data['personalausweis_gueltig_bis'])) {
            $errors[] = 'Personalausweis gültig bis: ungültiges Datum';
        }
        if (!empty($data['personalausweis_gueltig_ab'])
            && !empty($data['personalausweis_gueltig_bis'])
            && strtotime($data['personalausweis_gueltig_bis'])
               < strtotime($data['personalausweis_gueltig_ab'])) {
            $errors[] = 'Personalausweis gültig bis darf nicht vor "gültig ab" liegen';
        }
        // DFB-Pin: 4–8 alphanumeric chars (Ziffern und/oder Buchstaben).
        if (!empty($data['dfb_pin']) && !preg_match('/^[A-Za-z0-9]{4,8}$/', preg_replace('/\s/', '', $data['dfb_pin']))) {
            $errors[] = 'DFB-Pin: 4–8 Zeichen (Ziffern und/oder Buchstaben)';
        }
        // §34a GewO qualification level.
        if (!empty($data['gewo_qualifikation'])
            && !in_array($data['gewo_qualifikation'], ['VOD', 'U', 'SKP', 'GSSK', 'FK'])) {
            $errors[] = '§34a-Qualifikation: VOD / U / SKP / GSSK / FK erlaubt';
        }
        // Lohn-Typ: 'gehalt' (monthly) | 'lohn' (hourly).
        if (!empty($data['lohn_typ']) && !in_array($data['lohn_typ'], ['gehalt', 'lohn'], true)) {
            $errors[] = 'Lohn-Typ: nur "gehalt" oder "lohn" erlaubt';
        }
        // QuaSOD modules: array of allowed short codes.
        if (isset($data['quasod_module']) && $data['quasod_module'] !== null && $data['quasod_module'] !== '') {
            $allowedQs = ['m1e', 'm1p', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8', 'm9', 'gesperrt'];
            $qs = is_string($data['quasod_module']) ? json_decode($data['quasod_module'], true) : $data['quasod_module'];
            if (!is_array($qs)) {
                $errors[] = 'QuaSOD-Module: ungültiges Format';
            } else {
                foreach ($qs as $code) {
                    if (!in_array($code, $allowedQs, true)) {
                        $errors[] = 'QuaSOD-Modul nicht erlaubt: ' . $code;
                        break;
                    }
                }
            }
        }
        // Mobil: same rule as Telefon.
        if (!empty($data['mobil']) && !preg_match('/^[\d\s\+\-\(\)\/]{6,30}$/', $data['mobil'])) {
            $errors[] = 'Mobil: ungültiges Format';
        }

        // Barcode: optional free-text identifier (max 50). When present it
        // must be unique across the tenant — both a manually typed value
        // and a value pulled from /workers/barcode/suggest pass through
        // here, so two parallel admins racing on suggest can never end up
        // with a duplicate. We exclude the current record on update.
        if (!empty($data['barcode'])) {
            $bc = trim((string) $data['barcode']);
            if (strlen($bc) > 50) {
                $errors[] = 'Barcode: maximal 50 Zeichen';
            } elseif (Schema::connection('tenant')->hasColumn('mitarbeiter', 'barcode')) {
                $exists = DB::connection('tenant')->table('mitarbeiter')
                    ->where('barcode', $bc)
                    ->when($id, fn($q) => $q->where('dienst_nr', '<>', $id))
                    ->exists();
                if ($exists) {
                    $errors[] = 'Barcode wird bereits von einem anderen Mitarbeiter verwendet';
                }
            }
        }

        return $errors;
    }
}
