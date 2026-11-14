<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControllingAggregationService;
use App\Services\ControllingCockpitService as Cockpit;
use App\Support\BereichScope;
use App\Support\XlsxTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlling / Analytics Controller
 * ----------------------------------
 * Provides the data layer for "Auswertung & Controlling".
 *
 * The controller is now a thin orchestrator: all reusable SQL helpers,
 * period resolution, bucketing, forecasting and cache-key logic lives in
 * ControllingAggregationService so it can be shared with
 * TenantDashboardController, ReportController and the AI assistant tools.
 *
 * All endpoints assume the `tenant` connection has been resolved by the
 * ResolveTenant middleware.
 */
class ControllingController extends Controller
{
    // ============================================================
    // PUBLIC ENDPOINTS
    // ============================================================

    /**
     * GET /api/controlling/dashboard
     *
     * Aggregator endpoint that returns ALL widgets in a SINGLE HTTP round-trip:
     * kpi + trend + breakdowns + bereich + schichtart + heatmap + forecast
     * + first page of details.
     */
    public function dashboard(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $compare = $request->input('compare', 'prev');
        $granularity = $request->input('granularity', ControllingAggregationService::autoGranularity($from, $to));

        // --- KPI (current + comparison) ---
        $kpi = $this->computeKpi($from, $to, $filters);
        [$pFrom, $pTo] = ControllingAggregationService::comparisonRange($from, $to, $compare);
        $kpiPrev = $this->computeKpi($pFrom, $pTo, $filters);

        $kpiPayload = [
            'period' => [
                'from' => $from,
                'to' => $to,
                'label' => ControllingAggregationService::periodLabel($from, $to),
            ],
            'compare' => [
                'mode' => $compare,
                'from' => $pFrom,
                'to' => $pTo,
                'label' => ControllingAggregationService::periodLabel($pFrom, $pTo),
            ],
            'filters' => $filters,
            'kpi' => $kpi,
            'kpi_prev' => $kpiPrev,
            'delta' => [
                'einnahmen' => ControllingAggregationService::delta($kpi['einnahmen'], $kpiPrev['einnahmen']),
                'kosten' => ControllingAggregationService::delta($kpi['kosten'], $kpiPrev['kosten']),
                'ma_kosten' => ControllingAggregationService::delta($kpi['ma_kosten'] ?? 0, $kpiPrev['ma_kosten'] ?? 0),
                'gewinn' => ControllingAggregationService::delta($kpi['gewinn'], $kpiPrev['gewinn']),
                'marge' => ControllingAggregationService::delta($kpi['marge'], $kpiPrev['marge']),
                'einsaetze' => ControllingAggregationService::delta($kpi['einsaetze_total'], $kpiPrev['einsaetze_total']),
                'ist_stunden' => ControllingAggregationService::delta($kpi['ist_stunden'], $kpiPrev['ist_stunden']),
            ],
        ];

        // --- Other widgets: delegate to the same per-endpoint methods so
        //     payload shapes (and caches) stay identical across the two paths.
        $trendResp = $this->trend($request);
        $detailsResp = $this->details($request);
        $bdCustomers = $this->breakdownCustomers($request);
        $bdObjects = $this->breakdownObjects($request);
        $bdNu = $this->breakdownSubcontractors($request);
        $bdBereich = $this->breakdownBereich($request);
        $bdSchichtart = $this->breakdownSchichtart($request);
        $bdMitarbeiter = $this->breakdownMitarbeiter($request);
        $bdArtikel = $this->breakdownArtikel($request);
        $heatmapResp = $this->heatmap($request);
        $forecastResp = $this->forecast($request);

        $unwrap = function ($resp) {
            return method_exists($resp, 'getData') ? $resp->getData(true) : $resp;
        };

        return ControllingAggregationService::jsonCached([
            'kpi' => $kpiPayload,
            'trend' => $unwrap($trendResp),
            'details' => $unwrap($detailsResp),
            'breakdown' => [
                'customers' => $unwrap($bdCustomers),
                'objects' => $unwrap($bdObjects),
                'subcontractors' => $unwrap($bdNu),
                'bereich' => $unwrap($bdBereich),
                'schichtart' => $unwrap($bdSchichtart),
                'mitarbeiter' => $unwrap($bdMitarbeiter),
                'artikel' => $unwrap($bdArtikel),
            ],
            'heatmap' => $unwrap($heatmapResp),
            'forecast' => $unwrap($forecastResp),
        ]);
    }

    /**
     * GET /api/controlling/kpi
     *
     * Returns the headline KPIs for the requested period together with the
     * same KPIs for the comparison period (previous period or previous year)
     * so the UI can show ±%.
     */
    public function kpi(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $compare = $request->input('compare', 'prev'); // 'prev' | 'year'

        $kpi = $this->computeKpi($from, $to, $filters);
        [$pFrom, $pTo] = ControllingAggregationService::comparisonRange($from, $to, $compare);
        $kpiPrev = $this->computeKpi($pFrom, $pTo, $filters);

        return ControllingAggregationService::jsonCached([
            'period' => [
                'from' => $from,
                'to' => $to,
                'label' => ControllingAggregationService::periodLabel($from, $to),
            ],
            'compare' => [
                'mode' => $compare,
                'from' => $pFrom,
                'to' => $pTo,
                'label' => ControllingAggregationService::periodLabel($pFrom, $pTo),
            ],
            'filters' => $filters,
            'kpi' => $kpi,
            'kpi_prev' => $kpiPrev,
            'delta' => [
                'einnahmen' => ControllingAggregationService::delta($kpi['einnahmen'], $kpiPrev['einnahmen']),
                'kosten' => ControllingAggregationService::delta($kpi['kosten'], $kpiPrev['kosten']),
                'ma_kosten' => ControllingAggregationService::delta($kpi['ma_kosten'] ?? 0, $kpiPrev['ma_kosten'] ?? 0),
                'gewinn' => ControllingAggregationService::delta($kpi['gewinn'], $kpiPrev['gewinn']),
                'marge' => ControllingAggregationService::delta($kpi['marge'], $kpiPrev['marge']),
                'einsaetze' => ControllingAggregationService::delta($kpi['einsaetze_total'], $kpiPrev['einsaetze_total']),
                'ist_stunden' => ControllingAggregationService::delta($kpi['ist_stunden'], $kpiPrev['ist_stunden']),
            ],
        ]);
    }

    /**
     * GET /api/controlling/trend?granularity=day|week|month|quarter
     *
     * Returns one bucket per day/week/month/quarter inside the period.
     * Used by the main revenue/cost/profit line chart and the sparklines.
     */
    public function trend(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $granularity = $request->input('granularity', ControllingAggregationService::autoGranularity($from, $to));

        $bucketExpr = ControllingAggregationService::bucketExpression($granularity);
        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();

        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);

        $sql = "
            SELECT {$bucketExpr} AS bucket,
                   COUNT(*)                                                                   AS einsaetze,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END), 0) AS kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END), 0)                                                AS ma_kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$bill} ELSE 0 END), 0)                                                  AS ist_stunden
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
              {$maJoin}
             WHERE {$where}
          GROUP BY bucket
          ORDER BY bucket ASC
        ";

        $cacheKey = ControllingAggregationService::cacheKey('trend', compact('from', 'to', 'filters', 'granularity'));
        $points = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($sql, $params, $from, $to, $granularity) {
            $rows = DB::connection('tenant')->select($sql, $params);

            $points = array_map(function ($r) {
                $einnahmen = (float) $r->einnahmen;
                $kosten = (float) $r->kosten;
                $maKosten = (float) ($r->ma_kosten ?? 0);
                $gewinn = $einnahmen - $kosten - $maKosten;

                return [
                    'bucket' => $r->bucket,
                    'einsaetze' => (int) $r->einsaetze,
                    'einnahmen' => round($einnahmen, 2),
                    'kosten' => round($kosten, 2),
                    'ma_kosten' => round($maKosten, 2),
                    'gewinn' => round($gewinn, 2),
                    'marge' => $einnahmen > 0 ? round(($gewinn / $einnahmen) * 100, 1) : 0,
                    'ist_stunden' => round((float) $r->ist_stunden, 2),
                ];
            }, $rows);

            return ControllingAggregationService::fillMissingBuckets($points, $from, $to, $granularity);
        });

        return ControllingAggregationService::jsonCached([
            'granularity' => $granularity,
            'points' => $points,
        ]);
    }

    /**
     * GET /api/controlling/breakdown/customers?limit=10
     */
    public function breakdownCustomers(Request $request)
    {
        return ControllingAggregationService::jsonCached(
            $this->breakdown($request, 'kunde', 'kunden_id', 'kundenname', 'kunden', 'kunde_id')
        );
    }

    public function breakdownObjects(Request $request)
    {
        return ControllingAggregationService::jsonCached(
            $this->breakdown($request, 'objekt', 'obj_id', 'objektname', 'objekte', 'obj_id')
        );
    }

    public function breakdownSubcontractors(Request $request)
    {
        return ControllingAggregationService::jsonCached(
            $this->breakdown($request, 'nu', 'nu_id', 'firmenname', 'nachunternehmer', 'nu_id')
        );
    }

    /**
     * GET /api/controlling/breakdown/artikel
     * Top articles by revenue inside the period.
     */
    public function breakdownArtikel(Request $request)
    {
        return ControllingAggregationService::jsonCached(
            $this->breakdown($request, 'artikel', 'artikel_id', 'artikel_name', 'artikel', 'artikel_id')
        );
    }

    /**
     * GET /api/controlling/breakdown/mitarbeiter
     *
     * Top INTERNAL Mitarbeiter by hours / generated revenue inside the
     * period. NU einsätze are deliberately excluded.
     */
    public function breakdownMitarbeiter(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $limit = min(100, max(1, (int) ($request->input('limit') ?? 10)));
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);

        $cacheKey = ControllingAggregationService::cacheKey('breakdown_mitarbeiter', compact('from', 'to', 'filters', 'limit'));
        $payload = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($where, $params, $limit) {
            $perWorkerHours = 'COALESCE(NULLIF(em.ist_std, 0), e.ist_std_menge, 0)';
            $customerHours = ControllingAggregationService::customerWorkerHoursExpr('em');
            $billJoin = ControllingAggregationService::billableJoin();
            $workerRevenue = ControllingAggregationService::workerRevenueExpr($customerHours);
            $lohn = ControllingAggregationService::maHourlyLohnExpr('m');
            $workerCost = "({$perWorkerHours} * {$lohn} * (1 + COALESCE(e.zuschlag_pct, 0) / 100))";

            $sql = "
                SELECT em.dienst_nr                                              AS dim_id,
                       COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.vorname,''), ' ', COALESCE(m.name,''))), ''),
                                em.dienst_nr)                                    AS dim_value,
                       COUNT(DISTINCT em.einsatz_id)                              AS einsaetze,
                       COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$perWorkerHours} ELSE 0 END), 0) AS ist_stunden,
                       COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$workerRevenue} ELSE 0 END), 0) AS einnahmen,
                       COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$workerCost} ELSE 0 END), 0) AS ma_kosten,
                       0                                                          AS kosten
                  FROM einsatz_mitarbeiter em
                  JOIN einsaetze     e ON em.einsatz_id  = e.einsatz_id
                  LEFT JOIN artikel  a ON e.artikel_id   = a.artikel_id
                  {$billJoin}
                  LEFT JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
                 WHERE {$where}
                   AND (e.nu_id IS NULL OR e.nu_id = '')
              GROUP BY em.dienst_nr, dim_value
              ORDER BY einnahmen DESC
                 LIMIT {$limit}
            ";
            $rows = DB::connection('tenant')->select($sql, $params);

            return [
                'dimension' => 'mitarbeiter',
                'rows' => ControllingAggregationService::decorateBreakdown($rows),
            ];
        });

        return ControllingAggregationService::jsonCached($payload);
    }

    /**
     * GET /api/controlling/mitarbeiter-options
     *
     * Lightweight list of internal workers for the Controlling filter strip.
     * Lives under controlling.view (not stammdaten.mitarbeiter.view) so a
     * pure controlling user can still scope the dashboard to a worker.
     */
    public function mitarbeiterOptions()
    {
        // Bereich scope: restricted users only pick in-scope workers.
        [$scopeSql, $scopeParams] = BereichScope::workerPredicate(auth()->user(), 'm');
        $rows = DB::connection('tenant')->select("
            SELECT m.dienst_nr,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.vorname,''), ' ', COALESCE(m.name,''))), ''), m.dienst_nr) AS name
              FROM mitarbeiter m
             WHERE COALESCE(m.aktiv, 1) = 1
               AND {$scopeSql}
          ORDER BY m.name, m.vorname
        ", $scopeParams);

        return ControllingAggregationService::jsonCached($rows);
    }

    /**
     * GET /api/controlling/mitarbeiter/{dienstNr}
     *
     * Deep-dive for ONE internal worker inside the period (+ filters):
     *   - kpi        : Einsätze, Stunden, Umsatzanteil, Lohnkosten, Gewinn, Marge
     *   - by_objekt  : where did they work (per Objekt / Kunde)
     *   - by_weekday : hours per weekday (Mo..So)
     *   - by_start   : hours per shift start hour (0..23) — "when" they work
     *   - trend      : hours + Gewinn per bucket
     *   - einsaetze  : the concrete shifts (date, times, place, €), newest first
     *
     * Revenue attribution is proportional to the worker's own hours (same
     * rule as breakdownMitarbeiter), cost = hours × stundenlohn, and
     * Gewinn = Umsatzanteil − Lohnkosten (internal Einsätze only, so no NU cost).
     */
    public function mitarbeiterAnalyse(Request $request, string $dienstNr)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        unset($filters['dienst_nr']); // the path param IS the worker scope
        $granularity = $request->input('granularity', ControllingAggregationService::autoGranularity($from, $to));
        $limit = min(500, max(20, (int) $request->input('limit', 200)));

        $lohnExpr = ControllingAggregationService::maHourlyLohnExpr('m');
        $worker = DB::connection('tenant')->selectOne("
            SELECT m.dienst_nr, m.vorname, m.name, m.stundenlohn,
                   {$lohnExpr} AS stundenlohn_eff,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.vorname,''), ' ', COALESCE(m.name,''))), ''), m.dienst_nr) AS full_name
              FROM mitarbeiter m WHERE m.dienst_nr = ? LIMIT 1
        ", [$dienstNr]);
        if (!$worker || !BereichScope::allowsWorker($request->user(), $dienstNr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // Same calculation as the cockpit: row-exact Zeiterfassung classes,
        // revenue by the row's Kundenstunden, cost incl. Vollkostenfaktor.
        $basis = $request->input('basis') === 'abrechnung' ? 'abrechnung' : 'operativ';
        $settings = Cockpit::settings();
        $today = Cockpit::today();
        $cacheKey = ControllingAggregationService::cacheKey('worker2', compact('dienstNr', 'from', 'to', 'filters', 'granularity', 'limit', 'basis', 'settings', 'today'));
        $payload = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($dienstNr, $from, $to, $filters, $granularity, $limit, $worker, $basis) {
            $ctx = Cockpit::context();
            $st = Cockpit::rowStatus();
            $in = "'" . implode("','", Cockpit::statusesFor($basis)) . "'";
            $F = sprintf('%.6F', $ctx['F']);
            [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);
            $hasTimes = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'ist_start');

            $hours = 'COALESCE(NULLIF(em.ist_std, 0), e.ist_std_menge, 0)';
            $billJoin = Cockpit::billJoinSql();
            $workerRevenue = Cockpit::workerRevenue();
            $rev = "(CASE WHEN {$st} IN ({$in}) THEN {$workerRevenue} ELSE 0 END)";
            $lohn = ControllingAggregationService::maHourlyLohnExpr('m');
            // Personal-Vollkosten (incl. AG-Anteil + Ausfallzeiten) → Gewinn = DB II.
            $cost = "(CASE WHEN {$st} IN ({$in}) THEN {$hours} * {$lohn} * (1 + COALESCE(e.zuschlag_pct, 0) / 100) * {$F} ELSE 0 END)";
            $startExpr = $hasTimes ? 'COALESCE(NULLIF(em.ist_start, \'\'), e.ist_start)' : 'e.ist_start';
            $endExpr = $hasTimes ? 'COALESCE(NULLIF(em.ist_ende, \'\'), e.ist_ende)' : 'e.ist_ende';
            $pauseExpr = $hasTimes ? 'COALESCE(em.pause_minuten, e.pause_minuten)' : 'e.pause_minuten';

            $base = "
                  FROM einsatz_mitarbeiter em
                  JOIN einsaetze        e ON em.einsatz_id = e.einsatz_id
                  LEFT JOIN artikel     a ON e.artikel_id  = a.artikel_id
                  {$billJoin}
                  LEFT JOIN mitarbeiter m ON em.dienst_nr  = m.dienst_nr
                  LEFT JOIN kunden      k ON e.kunden_id   = k.kunde_id
                  LEFT JOIN objekte     o ON e.obj_id      = o.obj_id
                 WHERE em.dienst_nr = ?
                   AND (e.nu_id IS NULL OR e.nu_id = '')
                   AND {$where}";
            $p = array_merge([$dienstNr], $params);
            $db = DB::connection('tenant');

            // --- KPI ---
            $k = $db->selectOne("
                SELECT COUNT(DISTINCT em.einsatz_id)                             AS einsaetze,
                       SUM(CASE WHEN {$st} = 'freigegeben' THEN 1 ELSE 0 END)   AS bestaetigt,
                       COALESCE(SUM(CASE WHEN {$st} IN ({$in}) THEN {$hours} ELSE 0 END), 0)                                 AS stunden,
                       COALESCE(SUM({$rev}), 0)                                   AS einnahmen,
                       COALESCE(SUM({$cost}), 0)                                  AS kosten,
                       COUNT(DISTINCT e.obj_id)                                   AS objekte,
                       COUNT(DISTINCT e.kunden_id)                                AS kunden,
                       COUNT(DISTINCT e.datum)                                    AS arbeitstage
                {$base}
            ", $p);
            $einnahmen = (float) $k->einnahmen;
            $kosten = (float) $k->kosten;
            $gewinn = $einnahmen - $kosten;
            $stunden = (float) $k->stunden;

            // --- Where: per Objekt ---
            $byObjekt = $db->select("
                SELECT e.obj_id AS dim_id,
                       COALESCE(o.objektname, e.obj_id, '–') AS dim_value,
                       COALESCE(k.kundenname, '–')          AS kundenname,
                       COUNT(DISTINCT em.einsatz_id)        AS einsaetze,
                       COALESCE(SUM(CASE WHEN {$st} IN ({$in}) THEN {$hours} ELSE 0 END), 0)           AS ist_stunden,
                       COALESCE(SUM({$rev}), 0)             AS einnahmen,
                       COALESCE(SUM({$cost}), 0)            AS ma_kosten,
                       0                                    AS kosten
                {$base}
              GROUP BY e.obj_id, dim_value, kundenname
              ORDER BY ist_stunden DESC
            ", $p);

            // --- When: hours per weekday (0=Mo..6=So) and per start hour ---
            $byDow = $db->select("
                SELECT MOD(DAYOFWEEK(e.datum) + 5, 7) AS dow,
                       COUNT(DISTINCT em.einsatz_id)  AS einsaetze,
                       COALESCE(SUM(CASE WHEN {$st} IN ({$in}) THEN {$hours} ELSE 0 END), 0)     AS stunden
                {$base}
              GROUP BY dow ORDER BY dow
            ", $p);
            $byStart = $db->select("
                SELECT CAST(SUBSTRING_INDEX({$startExpr}, ':', 1) AS UNSIGNED) AS hour,
                       COUNT(DISTINCT em.einsatz_id)          AS einsaetze,
                       COALESCE(SUM(CASE WHEN {$st} IN ({$in}) THEN {$hours} ELSE 0 END), 0)             AS stunden
                {$base}
                   AND {$startExpr} IS NOT NULL AND {$startExpr} <> ''
              GROUP BY hour ORDER BY hour
            ", $p);

            // --- Trend ---
            $bucketExpr = ControllingAggregationService::bucketExpression($granularity);
            $trendRows = $db->select("
                SELECT {$bucketExpr} AS bucket,
                       COUNT(DISTINCT em.einsatz_id) AS einsaetze,
                       COALESCE(SUM(CASE WHEN {$st} IN ({$in}) THEN {$hours} ELSE 0 END), 0)    AS ist_stunden,
                       COALESCE(SUM({$rev}), 0)      AS einnahmen,
                       COALESCE(SUM({$cost}), 0)     AS ma_kosten
                {$base}
              GROUP BY bucket ORDER BY bucket ASC
            ", $p);
            $trend = array_map(function ($r) {
                $ein = (float) $r->einnahmen;
                $ma = (float) $r->ma_kosten;
                $gw = $ein - $ma;
                return [
                    'bucket' => $r->bucket,
                    'einsaetze' => (int) $r->einsaetze,
                    'einnahmen' => round($ein, 2),
                    'kosten' => 0,
                    'ma_kosten' => round($ma, 2),
                    'gewinn' => round($gw, 2),
                    'marge' => $ein > 0 ? round($gw / $ein * 100, 1) : 0,
                    'ist_stunden' => round((float) $r->ist_stunden, 2),
                ];
            }, $trendRows);
            $trend = ControllingAggregationService::fillMissingBuckets($trend, $from, $to, $granularity);

            // --- The concrete shifts ---
            $einsaetze = $db->select("
                SELECT e.einsatz_id, e.datum, {$st} AS row_status,
                       {$startExpr} AS ist_start, {$endExpr} AS ist_ende, {$pauseExpr} AS pause_minuten,
                       {$hours}     AS stunden,
                       k.kundenname, o.objektname, a.artikel_name,
                       {$rev}       AS einnahmen,
                       {$cost}      AS ma_kosten,
                       ({$rev} - {$cost}) AS gewinn
                {$base}
              ORDER BY e.datum DESC, {$startExpr} DESC
                 LIMIT {$limit}
            ", $p);

            return [
                'mitarbeiter' => [
                    'dienst_nr' => $worker->dienst_nr,
                    'name' => $worker->full_name,
                    'stundenlohn' => $worker->stundenlohn !== null ? round((float) $worker->stundenlohn_eff, 2) : null,
                ],
                'period' => ['from' => $from, 'to' => $to, 'label' => ControllingAggregationService::periodLabel($from, $to)],
                'granularity' => $granularity,
                'kpi' => [
                    'einsaetze' => (int) $k->einsaetze,
                    'bestaetigt' => (int) $k->bestaetigt,
                    'stunden' => round($stunden, 2),
                    'stunden_pro_einsatz' => $k->einsaetze > 0 ? round($stunden / $k->einsaetze, 2) : 0,
                    'arbeitstage' => (int) $k->arbeitstage,
                    'objekte' => (int) $k->objekte,
                    'kunden' => (int) $k->kunden,
                    'einnahmen' => round($einnahmen, 2),
                    'ma_kosten' => round($kosten, 2),
                    'gewinn' => round($gewinn, 2),
                    'marge' => $einnahmen > 0 ? round($gewinn / $einnahmen * 100, 1) : 0,
                    'gewinn_pro_stunde' => $stunden > 0 ? round($gewinn / $stunden, 2) : 0,
                ],
                'by_objekt' => array_map(function ($r) {
                    $row = ControllingAggregationService::decorateBreakdown([$r])[0];
                    $row['kundenname'] = $r->kundenname;
                    return $row;
                }, $byObjekt),
                'by_weekday' => array_map(fn ($r) => [
                    'dow' => (int) $r->dow, 'einsaetze' => (int) $r->einsaetze, 'stunden' => round((float) $r->stunden, 2),
                ], $byDow),
                'by_start_hour' => array_map(fn ($r) => [
                    'hour' => (int) $r->hour, 'einsaetze' => (int) $r->einsaetze, 'stunden' => round((float) $r->stunden, 2),
                ], array_values(array_filter($byStart, fn ($r) => $r->hour !== null))),
                'trend' => $trend,
                'einsaetze' => array_map(fn ($r) => [
                    'einsatz_id' => $r->einsatz_id,
                    'datum' => $r->datum,
                    'ist_start' => $r->ist_start,
                    'ist_ende' => $r->ist_ende,
                    'pause_minuten' => $r->pause_minuten !== null ? (int) $r->pause_minuten : null,
                    'stunden' => round((float) $r->stunden, 2),
                    'kundenname' => $r->kundenname,
                    'objektname' => $r->objektname,
                    'artikel_name' => $r->artikel_name,
                    'einnahmen' => round((float) $r->einnahmen, 2),
                    'ma_kosten' => round((float) $r->ma_kosten, 2),
                    'gewinn' => round((float) $r->gewinn, 2),
                    'hms_bestaetigt' => $r->row_status,
                ], $einsaetze),
                'einsaetze_limit' => $limit,
            ];
        });

        return ControllingAggregationService::jsonCached($payload);
    }

    /**
     * GET /api/controlling/breakdown/schichtart
     *
     * Aggregates Einsätze and revenue by Schichtart (Tagdienst, Nachtdienst,
     * Sonntag, Feiertag, Nacht+Sonntag, Nacht+Feiertag).
     */
    public function breakdownSchichtart(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);

        if (!Schema::connection('tenant')->hasColumn('einsaetze', 'schichtart')) {
            return ControllingAggregationService::jsonCached([]);
        }

        $cacheKey = ControllingAggregationService::cacheKey('schichtart', compact('from', 'to', 'filters'));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ControllingAggregationService::jsonCached($cached);
        }

        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $surcharge = ControllingAggregationService::surchargeExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();

        $sql = "
            SELECT COALESCE(NULLIF(e.schichtart, ''), 'tagdienst')                       AS dim_value,
                   COUNT(*)                                                              AS einsaetze,
                   AVG(NULLIF(e.zuschlag_pct, 0))                                        AS avg_pct,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$surcharge} ELSE 0 END), 0) AS aufschlag,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END), 0) AS kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END), 0)                                            AS ma_kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$bill} ELSE 0 END), 0)                                              AS ist_stunden
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
              {$maJoin}
             WHERE {$where}
          GROUP BY dim_value
          ORDER BY FIELD(dim_value,
                         'tagdienst', 'nachtdienst', 'sonntag', 'feiertag',
                         'nacht_sonntag', 'nacht_feiertag')
        ";

        $rows = DB::connection('tenant')->select($sql, $params);

        $labels = [
            'tagdienst' => 'Tagdienst (Mo–Sa)',
            'nachtdienst' => 'Nachtdienst',
            'sonntag' => 'Sonntag',
            'feiertag' => 'Feiertag',
            'nacht_sonntag' => 'Nacht + Sonntag',
            'nacht_feiertag' => 'Nacht + Feiertag',
        ];

        $out = array_map(function ($r) use ($labels) {
            $einnahmen = (float) $r->einnahmen;
            $kosten = (float) $r->kosten;
            $maKosten = (float) ($r->ma_kosten ?? 0);
            $gewinn = $einnahmen - $kosten - $maKosten;
            $code = $r->dim_value;

            return [
                'code' => $code,
                'dim_value' => $labels[$code] ?? ucfirst((string) $code),
                'einsaetze' => (int) $r->einsaetze,
                'avg_pct' => $r->avg_pct !== null ? round((float) $r->avg_pct, 2) : 0,
                'einnahmen' => round($einnahmen, 2),
                'aufschlag' => round((float) $r->aufschlag, 2),
                'kosten' => round($kosten, 2),
                'ma_kosten' => round($maKosten, 2),
                'gewinn' => round($gewinn, 2),
                'marge' => $einnahmen > 0 ? round(($gewinn / $einnahmen) * 100, 1) : 0,
                'ist_stunden' => round((float) $r->ist_stunden, 2),
            ];
        }, $rows);

        Cache::put($cacheKey, $out, ControllingAggregationService::CACHE_TTL);

        return ControllingAggregationService::jsonCached($out);
    }

    /**
     * GET /api/controlling/breakdown/bereich
     * Aggregates by customer.bereich (cleaning area / category).
     */
    public function breakdownBereich(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);

        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();

        // Effective Bereich (artikel → kunde) — the same expression the filter
        // and the Bereich scope use, so breakdown and filter never disagree.
        $bExpr = BereichScope::derivedExprSub('e');
        $sql = "
            SELECT COALESCE({$bExpr}, '–') AS dim_value,
                   COUNT(*)                                                                  AS einsaetze,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END), 0) AS kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END), 0)                                               AS ma_kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$bill} ELSE 0 END), 0)                                                 AS ist_stunden
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              LEFT JOIN kunden  k ON e.kunden_id = k.kunde_id
              {$billJoin}
              {$maJoin}
             WHERE {$where}
          GROUP BY dim_value
          ORDER BY einnahmen DESC
        ";

        $cacheKey = ControllingAggregationService::cacheKey('bereich', compact('from', 'to', 'filters'));
        $payload = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($sql, $params) {
            $rows = DB::connection('tenant')->select($sql, $params);

            return [
                'dimension' => 'bereich',
                'rows' => ControllingAggregationService::decorateBreakdown($rows),
            ];
        });

        return ControllingAggregationService::jsonCached($payload);
    }

    /**
     * GET /api/controlling/heatmap
     * Returns a 7×12 grid (weekday × month) of Einsätze counts and revenue.
     */
    public function heatmap(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);

        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $revenue = ControllingAggregationService::revenueExpr($bill);

        $sql = "
            SELECT MOD(DAYOFWEEK(e.datum) + 5, 7) AS dow,
                   MONTH(e.datum)                 AS mon,
                   COUNT(*)                       AS einsaetze,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
             WHERE {$where}
          GROUP BY dow, mon
        ";

        $cacheKey = ControllingAggregationService::cacheKey('heatmap', compact('from', 'to', 'filters'));
        $payload = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($sql, $params, $from, $to) {
            $rows = DB::connection('tenant')->select($sql, $params);
            $grid = [];
            foreach ($rows as $r) {
                $grid[(int) $r->dow][(int) $r->mon] = [
                    'einsaetze' => (int) $r->einsaetze,
                    'einnahmen' => round((float) $r->einnahmen, 2),
                ];
            }

            $yFrom = (int) date('Y', strtotime($from));
            $yTo = (int) date('Y', strtotime($to));
            $years = range($yFrom, $yTo);

            return [
                'grid' => $grid,
                'period' => ['from' => $from, 'to' => $to],
                'years' => $years,
                'aggregated_years' => count($years) > 1,
            ];
        });

        return ControllingAggregationService::jsonCached($payload);
    }

    /**
     * GET /api/controlling/forecast
     *
     * Simple linear regression on the last N completed periods of the same
     * granularity, projecting the next M periods. Conservative — no ML.
     */
    public function forecast(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $granularity = $request->input('granularity', ControllingAggregationService::autoGranularity($from, $to));
        $horizon = max(1, min(12, (int) $request->input('horizon', 3)));
        $historyN = ControllingAggregationService::forecastHistoryCount($granularity);

        $histFrom = ControllingAggregationService::shiftBack($to, $granularity, $historyN - 1);
        [$where, $params] = ControllingAggregationService::whereClause($histFrom, $to, $filters);
        $bucketExpr = ControllingAggregationService::bucketExpression($granularity);
        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();

        $sql = "
            SELECT {$bucketExpr} AS bucket,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} - {$nuCost} - {$maCost} ELSE 0 END), 0) AS gewinn
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
              {$maJoin}
             WHERE {$where}
          GROUP BY bucket
            HAVING SUM(CASE WHEN e.hms_bestaetigt <> 'ja' THEN 1 ELSE 0 END) = 0
          ORDER BY bucket ASC
        ";

        $cacheKey = ControllingAggregationService::cacheKey('forecast', compact('histFrom', 'to', 'filters', 'granularity', 'horizon', 'historyN'));
        $payload = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($sql, $params, $granularity, $horizon, $historyN) {
            $rows = DB::connection('tenant')->select($sql, $params);

            $einnahmenSeries = array_map(fn ($r) => (float) $r->einnahmen, $rows);
            $gewinnSeries = array_map(fn ($r) => (float) $r->gewinn, $rows);

            return [
                'granularity' => $granularity,
                'horizon' => $horizon,
                'history_n' => count($rows),
                'history' => array_map(fn ($r) => [
                    'bucket' => $r->bucket,
                    'einnahmen' => round((float) $r->einnahmen, 2),
                    'gewinn' => round((float) $r->gewinn, 2),
                ], $rows),
                'forecast' => [
                    'einnahmen' => ControllingAggregationService::linearForecast($einnahmenSeries, $horizon),
                    'gewinn' => ControllingAggregationService::linearForecast($gewinnSeries, $horizon),
                ],
            ];
        });

        return ControllingAggregationService::jsonCached($payload);
    }

    /**
     * GET /api/controlling/details
     * Paginated list of Einsätze in the period, ready for table display
     * or CSV export.
     */
    public function details(Request $request)
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $basis = $request->input('basis') === 'abrechnung' ? 'abrechnung' : 'operativ';
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(500, max(10, (int) $request->input('per_page', 50)));
        $sortKey = ControllingAggregationService::whitelist($request->input('sort', 'datum'), [
            'datum', 'einnahmen_detail', 'nu_kosten_detail', 'ma_kosten_detail', 'gewinn_detail', 'ist_std_menge',
            'kundenname', 'objektname', 'nu_name', 'artikel_name',
        ]);
        $dir = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Money columns come from the cockpit calculation (aliases of einsatzCols()).
        $sortExprMap = [
            'datum' => 'e.datum',
            'einnahmen_detail' => 'einnahmen',
            'nu_kosten_detail' => 'nu_kosten',
            'ma_kosten_detail' => 'ma_kosten',
            'gewinn_detail' => 'gewinn',
            'ist_std_menge' => 'e.ist_std_menge',
            'kundenname' => 'k.kundenname',
            'objektname' => 'o.objektname',
            'nu_name' => 'n.firmenname',
            'artikel_name' => 'a.artikel_name',
        ];
        $sortExpr = $sortExprMap[$sortKey] ?? 'e.datum';
        $settings = Cockpit::settings();
        $today = Cockpit::today();

        $cacheKey = ControllingAggregationService::cacheKey('details2', compact('from', 'to', 'filters', 'page', 'perPage', 'sortKey', 'dir', 'basis', 'settings', 'today'));
        $payload = Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL,
            function () use ($from, $to, $filters, $page, $perPage, $sortExpr, $dir, $basis) {
                return $this->detailsUncached($from, $to, $filters, $page, $perPage, $sortExpr, $dir, $basis);
            });

        return ControllingAggregationService::jsonCached($payload);
    }

    /**
     * Rows for the Excel/CSV export — same calculation as the cockpit:
     * Umsatz from Kundenstunden (Zeiterfassung), Personal-Vollkosten, DB II.
     */
    private function exportRows(Request $request): array
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $basis = $request->input('basis') === 'abrechnung' ? 'abrechnung' : 'operativ';
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);
        $ctx = Cockpit::context();
        $maxDetails = ControllingAggregationService::MAX_DETAILS;

        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, k.kundenname, o.objektname, a.artikel_name, n.firmenname AS nu_name,
                   e.ist_std_menge,
                   " . ControllingAggregationService::customerPriceExpr() . " AS preis_kunde,
                   " . ControllingAggregationService::nuPriceExpr() . " AS nu_preis,
                   " . Cockpit::einsatzCols($ctx, Cockpit::statusesFor($basis)) . "
              " . Cockpit::from() . "
              LEFT JOIN kunden          k ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte         o ON e.obj_id     = o.obj_id
              LEFT JOIN nachunternehmer n ON e.nu_id      = n.nu_id
             WHERE {$where}
          ORDER BY e.datum DESC
             LIMIT {$maxDetails}
        ", $params);

        return [$from, $to, $basis, array_map(fn ($r) => [
            $r->einsatz_id, $r->datum, $r->kundenname, $r->objektname, $r->artikel_name, $r->nu_name,
            (float) $r->ist_std_menge, round((float) $r->billable_std, 2),
            (float) $r->preis_kunde, (float) $r->nu_preis,
            round((float) $r->einnahmen, 2), round((float) $r->nu_kosten, 2),
            round((float) $r->ma_kosten, 2), round((float) $r->gewinn, 2),
            Cockpit::einsatzStatus($r->frei_anteil, $r->rest_status),
        ], $rows)];
    }

    private const EXPORT_HEADERS = ['Einsatz-ID', 'Datum', 'Kunde', 'Objekt', 'Artikel', 'NU', 'Plan-Std', 'Kundenstunden',
        'Preis Kunde', 'NU-Preis', 'Umsatz', 'NU-Kosten', 'Personal (Vollkosten)', 'DB II', 'Status'];

    /**
     * GET /api/controlling/export.csv
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        [$from, $to, , $rows] = $this->exportRows($request);
        $filename = 'controlling_'.$from.'_'.$to.'.csv';

        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::EXPORT_HEADERS, ';');
            foreach ($rows as $r) {
                foreach ([6, 7, 8, 9, 10, 11, 12, 13] as $i) {
                    $r[$i] = number_format((float) $r[$i], 2, ',', '');
                }
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /api/controlling/export.xlsx
     * Same dataset as exportCsv, but a real workbook — typed numeric cells,
     * no delimiter/decimal-locale issues on the user's machine.
     */
    public function exportXlsx(Request $request)
    {
        [$from, $to, $basis, $rows] = $this->exportRows($request);
        $title = 'Controlling ' . $from . '–' . $to . ($basis === 'abrechnung' ? ' (Kunde freigegeben)' : '');
        $bin = XlsxTable::build($title, self::EXPORT_HEADERS, $rows);

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="controlling_'.$from.'_'.$to.'.xlsx"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ============================================================
    // SNAPSHOTS — save / load / list / delete
    // ============================================================

    /**
     * POST /api/controlling/snapshots
     *
     * Freezes the FULL dashboard state (KPI + trend + breakdowns + heatmap +
     * forecast + Schicht-Aufschläge) into a single row in `analytics_snapshots`.
     */
    public function saveSnapshot(Request $request)
    {
        if (!Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return response()->json(['error' => 'analytics_snapshots table not migrated'], 500);
        }

        $title = trim((string) $request->input('title')) ?: ('Snapshot '.now()->format('Y-m-d H:i'));
        $notes = (string) $request->input('notes');
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $periodType = $request->input('period_type', 'custom');

        $kpi = $this->computeKpi($from, $to, $filters);
        $trend = $this->trend($request)->getData(true)['points'] ?? [];
        $unwrapBd = fn ($r) => method_exists($r, 'getData') ? $r->getData(true) : $r;
        $breakdowns = [
            'kunden' => $this->breakdown($request, 'kunde', 'kunden_id', 'kundenname', 'kunden', 'kunde_id', 5),
            'objekte' => $this->breakdown($request, 'objekt', 'obj_id', 'objektname', 'objekte', 'obj_id', 5),
            'nu' => $this->breakdown($request, 'nu', 'nu_id', 'firmenname', 'nachunternehmer', 'nu_id', 5),
            'mitarbeiter' => $unwrapBd($this->breakdownMitarbeiter($request)),
            'artikel' => $unwrapBd($this->breakdownArtikel($request)),
        ];
        $heatmap = $this->heatmap($request)->getData(true);
        $forecast = $this->forecast($request)->getData(true);
        $schichtart = $this->breakdownSchichtart($request)->getData(true);

        $row = [
            'title' => $title,
            'period_type' => $periodType,
            'date_from' => $from,
            'date_bis' => $to,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'kpi' => json_encode($kpi, JSON_UNESCAPED_UNICODE),
            'breakdowns' => json_encode($breakdowns, JSON_UNESCAPED_UNICODE),
            'trend' => json_encode($trend, JSON_UNESCAPED_UNICODE),
            'forecast' => json_encode($forecast, JSON_UNESCAPED_UNICODE),
            'created_by' => optional($request->user())->username ?? optional($request->user())->name,
            'notes' => $notes !== '' ? $notes : null,
            'created_at' => now(),
        ];

        if (Schema::connection('tenant')->hasColumn('analytics_snapshots', 'heatmap')) {
            $row['heatmap'] = json_encode($heatmap, JSON_UNESCAPED_UNICODE);
        }
        if (Schema::connection('tenant')->hasColumn('analytics_snapshots', 'schichtart')) {
            $row['schichtart'] = json_encode($schichtart, JSON_UNESCAPED_UNICODE);
        }

        $id = DB::connection('tenant')->table('analytics_snapshots')->insertGetId($row);

        return response()->json(['id' => $id, 'message' => 'Snapshot gespeichert']);
    }

    public function listSnapshots(Request $request)
    {
        if (!Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return response()->json([]);
        }

        $q = DB::connection('tenant')->table('analytics_snapshots')
            ->select('id', 'title', 'period_type', 'date_from', 'date_bis', 'created_by', 'created_at', 'notes')
            ->orderByDesc('created_at')
            ->limit(200);

        // Bereich-restricted users only ever see snapshots THEY created —
        // a snapshot saved by an unrestricted account contains cross-Bereich
        // aggregates and would leak them. Their own snapshots were computed
        // under their scope, so they are safe.
        $allowed = BereichScope::for($request->user());
        if (is_array($allowed)) {
            $q->where('created_by', $request->user()->username ?? $request->user()->name);
        }

        return response()->json($q->get());
    }

    public function getSnapshot(Request $request, $id)
    {
        if (!Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return response()->json(['error' => 'not found'], 404);
        }

        $row = DB::connection('tenant')->table('analytics_snapshots')->where('id', (int) $id)->first();
        if (!$row || !$this->snapshotVisible($row, $request->user())) {
            return response()->json(['error' => 'not found'], 404);
        }

        $row->filters = json_decode($row->filters ?? 'null', true);
        $row->kpi = json_decode($row->kpi ?? 'null', true);
        $row->breakdowns = json_decode($row->breakdowns ?? 'null', true);
        $row->trend = json_decode($row->trend ?? 'null', true);
        $row->forecast = json_decode($row->forecast ?? 'null', true);
        $row->heatmap = isset($row->heatmap) ? json_decode($row->heatmap ?? 'null', true) : null;
        $row->schichtart = isset($row->schichtart) ? json_decode($row->schichtart ?? 'null', true) : null;

        return response()->json($row);
    }

    public function deleteSnapshot(Request $request, $id)
    {
        if (!Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return response()->json(['error' => 'not found'], 404);
        }

        $row = DB::connection('tenant')->table('analytics_snapshots')->where('id', (int) $id)->first();
        if (!$row || !$this->snapshotVisible($row, $request->user())) {
            return response()->json(['error' => 'not found'], 404);
        }

        DB::connection('tenant')->table('analytics_snapshots')->where('id', (int) $id)->delete();

        return response()->json(['message' => 'Snapshot gelöscht']);
    }

    /**
     * A snapshot is visible to a restricted user only when he created it
     * himself — its aggregates were computed under his Bereich scope.
     * Unrestricted users see every snapshot.
     */
    private function snapshotVisible($row, $user): bool
    {
        if (BereichScope::for($user) === null) {
            return true;
        }
        $me = $user->username ?? $user->name ?? null;
        return $me !== null && ($row->created_by ?? null) === $me;
    }

    // ============================================================
    // CORE COMPUTATIONS
    // ============================================================

    /**
     * Returns the full KPI bag for an arbitrary date range + filters.
     */
    private function computeKpi(string $from, string $to, array $filters): array
    {
        $cacheKey = ControllingAggregationService::cacheKey('kpi', compact('from', 'to', 'filters'));

        return Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($from, $to, $filters) {
            return $this->computeKpiUncached($from, $to, $filters);
        });
    }

    private function computeKpiUncached(string $from, string $to, array $filters): array
    {
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);
        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();

        $row = DB::connection('tenant')->selectOne("
            SELECT
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END), 0) AS kosten,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END), 0) AS ma_kosten,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN {$maCost} ELSE 0 END), 0) AS ma_kosten_offen,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN {$revenue} ELSE 0 END), 0) AS einnahmen_offen,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN {$nuCost} ELSE 0 END), 0) AS kosten_offen,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN {$bill} ELSE 0 END), 0) AS ist_stunden_offen,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja'
                                  THEN {$bill}
                                  ELSE 0 END), 0)                                          AS bestaetigte_menge,
                COUNT(*)                                                                    AS einsaetze_total,
                SUM(CASE WHEN e.hms_bestaetigt = 'ja'         THEN 1 ELSE 0 END)            AS bestaetigt_count,
                SUM(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN 1 ELSE 0 END)            AS ausstehend_count,
                SUM(CASE WHEN e.hms_bestaetigt = 'nein'       THEN 1 ELSE 0 END)            AS nicht_bestaetigt_count,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$bill} ELSE 0 END), 0)                                                   AS ist_stunden,
                COUNT(DISTINCT e.kunden_id)                                                AS kunden_aktiv,
                COUNT(DISTINCT e.obj_id)                                                   AS objekte_aktiv,
                COUNT(DISTINCT e.nu_id)                                                    AS nu_aktiv
              FROM einsaetze e
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
              {$maJoin}
             WHERE {$where}
        ", $params);

        $quality = DB::connection('tenant')->selectOne("
            SELECT COUNT(DISTINCT CASE WHEN e.hms_bestaetigt = 'ja' AND COALESCE(m.stundenlohn, 0) <= 0 THEN em.dienst_nr END) AS ma_ohne_lohn_count,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' AND COALESCE(m.stundenlohn, 0) <= 0
                                     THEN COALESCE(NULLIF(em.ist_std, 0), e.ist_std_menge, 0) ELSE 0 END), 0) AS ma_stunden_ohne_lohn,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' AND em.ist_std > 0 THEN em.ist_std ELSE 0 END), 0) AS ma_stunden_erfasst,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' AND (em.ist_std IS NULL OR em.ist_std <= 0)
                                     THEN COALESCE(e.ist_std_menge, 0) ELSE 0 END), 0) AS ma_stunden_plan_fallback
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON e.einsatz_id = em.einsatz_id
         LEFT JOIN mitarbeiter m ON m.dienst_nr = em.dienst_nr
             WHERE {$where}
        ", $params);
        $objekteOhneBundesland = 0;
        if (Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            $objekteOhneBundesland = (int) DB::connection('tenant')->selectOne("
                SELECT COUNT(DISTINCT e.obj_id) AS c
                  FROM einsaetze e
             LEFT JOIN objekte oQ ON oQ.obj_id = e.obj_id
                 WHERE {$where} AND (oQ.bundesland IS NULL OR oQ.bundesland = '')
            ", $params)->c;
        }

        $einnahmen = (float) $row->einnahmen;
        $kosten = (float) $row->kosten;
        $maKosten = (float) ($row->ma_kosten ?? 0);
        $maKostenOffen = (float) ($row->ma_kosten_offen ?? 0);
        $gewinn = $einnahmen - $kosten - $maKosten;
        $marge = $einnahmen > 0 ? round(($gewinn / $einnahmen) * 100, 2) : 0;
        // Not-yet-counted money from `ausstehend` Einsätze (see drill-down).
        $einOffen = (float) ($row->einnahmen_offen ?? 0);
        $kostOffen = (float) ($row->kosten_offen ?? 0);

        $orderRow = ControllingAggregationService::plannedOrderKpi($from, $to, $filters);

        $istStunden = (float) $row->ist_stunden;
        // Soll-Stunden live on Bestelleingang day rows (not per worker) — when the
        // dashboard is scoped to a single Mitarbeiter, a Soll/Ist comparison
        // would be meaningless, so report 0 Soll (=> Auslastung null).
        $sollStunden = empty($filters['dienst_nr']) ? (float) $orderRow->soll_stunden : 0.0;

        return [
            'einnahmen' => round($einnahmen, 2),
            'kosten' => round($kosten, 2),
            'ma_kosten' => round($maKosten, 2),
            'ma_kosten_offen' => round($maKostenOffen, 2),
            'gewinn' => round($gewinn, 2),
            'marge' => $marge,
            'einnahmen_offen' => round($einOffen, 2),
            'kosten_offen' => round($kostOffen, 2),
            'einnahmen_erwartet' => round($einnahmen + $einOffen, 2),
            'gewinn_erwartet' => round($gewinn + $einOffen - $kostOffen - $maKostenOffen, 2),
            'gewinn_pro_einsatz' => $row->bestaetigt_count > 0
                ? round($gewinn / $row->bestaetigt_count, 2)
                : 0,
            'einsatz_durchschnitt_eur' => $row->bestaetigt_count > 0
                ? round($einnahmen / $row->bestaetigt_count, 2)
                : 0,
            'einsaetze_total' => (int) $row->einsaetze_total,
            'bestaetigt_count' => (int) $row->bestaetigt_count,
            'ausstehend_count' => (int) $row->ausstehend_count,
            'nicht_bestaetigt_count' => (int) $row->nicht_bestaetigt_count,
            'ist_stunden' => round($istStunden, 2),
            'ist_stunden_offen' => round((float) ($row->ist_stunden_offen ?? 0), 2),
            'soll_stunden' => round($sollStunden, 2),
            'delta_stunden' => round($sollStunden - $istStunden, 2),
            'auslastung_pct' => $sollStunden > 0
                ? round(($istStunden / $sollStunden) * 100, 1)
                : null,
            'bestellungen_total' => (int) $orderRow->bestellungen_total,
            'kunden_aktiv' => (int) $row->kunden_aktiv,
            'objekte_aktiv' => (int) $row->objekte_aktiv,
            'nu_aktiv' => (int) $row->nu_aktiv,
            'datenqualitaet' => [
                'ma_ohne_lohn_count' => (int) ($quality->ma_ohne_lohn_count ?? 0),
                'ma_stunden_ohne_lohn' => round((float) ($quality->ma_stunden_ohne_lohn ?? 0), 2),
                'ma_stunden_erfasst' => round((float) ($quality->ma_stunden_erfasst ?? 0), 2),
                'ma_stunden_plan_fallback' => round((float) ($quality->ma_stunden_plan_fallback ?? 0), 2),
                'objekte_ohne_bundesland' => $objekteOhneBundesland,
            ],
        ];
    }

    /**
     * Generic breakdown by dimension (kunde / objekt / nu / artikel).
     */
    private function breakdown(Request $request, string $dim, string $idCol, string $nameCol,
        string $joinTable, string $joinCol, int $defaultLimit = 10): array
    {
        [$from, $to] = ControllingAggregationService::resolvePeriod($request);
        $filters = ControllingAggregationService::resolveFilters($request);
        $limit = min(100, max(1, (int) ($request->input('limit') ?? $defaultLimit)));

        $cacheKey = ControllingAggregationService::cacheKey('breakdown', compact('dim', 'from', 'to', 'filters', 'limit'));

        return Cache::remember($cacheKey, ControllingAggregationService::CACHE_TTL, function () use ($dim, $idCol, $nameCol, $joinTable, $joinCol, $limit, $from, $to, $filters) {
            return $this->breakdownUncached($dim, $idCol, $nameCol, $joinTable, $joinCol, $limit, $from, $to, $filters);
        });
    }

    private function breakdownUncached(string $dim, string $idCol, string $nameCol,
        string $joinTable, string $joinCol, int $limit,
        string $from, string $to, array $filters): array
    {
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);
        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();

        $sql = "
            SELECT e.{$idCol}             AS dim_id,
                   COALESCE(j.{$nameCol}, '–') AS dim_value,
                   COUNT(*)                AS einsaetze,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END), 0) AS kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END), 0)        AS ma_kosten,
                   COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$bill} ELSE 0 END), 0) AS ist_stunden
              FROM einsaetze e
              LEFT JOIN artikel a       ON e.artikel_id = a.artikel_id
              LEFT JOIN {$joinTable} j ON e.{$idCol} = j.{$joinCol}
              {$billJoin}
              {$maJoin}
             WHERE {$where}
          GROUP BY e.{$idCol}, dim_value
          ORDER BY einnahmen DESC
             LIMIT {$limit}
        ";

        $rows = DB::connection('tenant')->select($sql, $params);

        return [
            'dimension' => $dim,
            'rows' => ControllingAggregationService::decorateBreakdown($rows),
        ];
    }

    private function detailsUncached(string $from, string $to, array $filters, int $page, int $perPage, string $sortExpr, string $dir, string $basis = 'operativ'): array
    {
        [$where, $params] = ControllingAggregationService::whereClause($from, $to, $filters);

        $total = (int) DB::connection('tenant')->selectOne(
            "SELECT COUNT(*) AS c
               FROM einsaetze e
              WHERE {$where}",
            $params
        )->c;

        $offset = ($page - 1) * $perPage;
        $ctx = Cockpit::context();

        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, e.hms_bestaetigt, e.ist_std_menge,
                   e.kunden_id, e.obj_id, e.nu_id, e.artikel_id,
                   k.kundenname, o.objektname, n.firmenname AS nu_name,
                   a.artikel_name, a.bereich AS artikel_bereich,
                   " . ControllingAggregationService::customerPriceExpr() . " AS preis_kunde,
                   " . ControllingAggregationService::nuPriceExpr() . " AS nu_preis,
                   " . ControllingAggregationService::workerCountExpr() . " AS workers,
                   " . Cockpit::einsatzCols($ctx, Cockpit::statusesFor($basis)) . "
              " . Cockpit::from() . "
              LEFT JOIN kunden          k ON e.kunden_id  = k.kunde_id
              LEFT JOIN objekte         o ON e.obj_id     = o.obj_id
              LEFT JOIN nachunternehmer n ON e.nu_id      = n.nu_id
             WHERE {$where}
          ORDER BY {$sortExpr} {$dir}, e.id DESC
             LIMIT {$perPage} OFFSET {$offset}
        ", $params);

        // Legacy *_detail keys for the table; ma_kosten = Personal-Vollkosten, gewinn = DB II.
        foreach ($rows as $r) {
            $r->einnahmen_detail = round((float) $r->einnahmen, 2);
            $r->nu_kosten_detail = round((float) $r->nu_kosten, 2);
            $r->ma_kosten_detail = round((float) $r->ma_kosten, 2);
            $r->gewinn_detail = round((float) $r->gewinn, 2);
            $r->billable_std = round((float) $r->billable_std, 2);
            $r->status = Cockpit::einsatzStatus($r->frei_anteil, $r->rest_status);
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
            'basis' => $basis,
            'rows' => $rows,
        ];
    }
}
