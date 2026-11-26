<?php

namespace App\Services;

use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Controlling / Analytics Aggregation Service
 * ---------------------------------------------
 * Centralises the SQL helpers, period resolution, bucketing, forecasting
 * and response-cache logic used by ControllingController,
 * TenantDashboardController and the legacy ReportController.
 *
 * Keeping these in one place guarantees that revenue/cost/profit figures
 * stay numerically identical across every dashboard, report and AI tool.
 */
class ControllingAggregationService
{
    /** Cache TTL for aggregation queries (seconds). */
    public const CACHE_TTL = 30;

    /** Bump whenever the aggregation SQL shape changes.
     *   v6 → `dienst_nr` filter (per-worker scope) added to whereClause().
     *   v7 → /details rows expose kunden_id/obj_id/nu_id/artikel_id (drill-down links).
     *   v8 → KPI exposes einnahmen_offen / kosten_offen / *_erwartet (unbestätigt-Hinweis). */
    public const CACHE_VERSION = 'v13';

    /** Soft cap so a single request can never serialize > 50k rows. */
    public const MAX_DETAILS = 5000;

    /**
     * Build a tenant-scoped cache key for aggregation results.
     */
    public static function cacheKey(string $bucket, array $parts): string
    {
        $tenant = TenantService::current();
        $slug = $tenant ? $tenant->slug : 'default';

        // Include the caller's Bereich restriction so two users with different
        // allowed Bereiche never share a cached aggregation (cross-user leak).
        $parts['__bereich_scope'] = BereichScope::for(Auth::user());
        ksort($parts);

        return 'ctrl:'.self::CACHE_VERSION.':'.$slug.':'.$bucket.':'.md5(json_encode($parts));
    }

    /**
     * Standard JSON response with browser-cache headers.
     */
    public static function jsonCached($payload)
    {
        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'private, max-age='.self::CACHE_TTL,
        ]);
    }

    // ============================================================
    // BILLABLE-HOURS HELPERS
    // ============================================================

    /**
     * SQL JOIN that materialises per-Einsatz worker counts + summed times,
     * for BOTH internal (einsatz_mitarbeiter) and NU
     * (nu_position_zuweisungen / nu_schicht_positionen) sides.
     */
    public static function billableJoin(): string
    {
        return "
            -- INTERNAL workers
            LEFT JOIN (
                SELECT em.einsatz_id,
                       SUM(GREATEST(0,
                           COALESCE(NULLIF(em.ist_std, 0), eW.ist_std_menge, 0)
                           + COALESCE(em.pause_minuten, eW.pause_minuten, 0) / 60
                       )) AS customer_bill_hours,
                       SUM(COALESCE(NULLIF(em.ist_std, 0), eW.ist_std_menge, 0)) AS worker_hours,
                       COUNT(*) AS total_workers
                  FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze eW ON eW.einsatz_id = em.einsatz_id
              GROUP BY em.einsatz_id
            ) ew ON ew.einsatz_id = e.einsatz_id

            -- NU workers: actually assigned
            LEFT JOIN (
                SELECT nsp.einsatz_id,
                       SUM(GREATEST(0,
                           COALESCE(NULLIF(npz.ist_std, 0), eN.ist_std_menge, 0)
                           + COALESCE(npz.pause_minuten, eN.pause_minuten, 0) / 60
                       )) AS customer_bill_hours,
                       SUM(COALESCE(NULLIF(npz.ist_std, 0), eN.ist_std_menge, 0)) AS worker_hours,
                       COUNT(npz.id) AS nu_total_zuweisungen
                  FROM nu_schicht_positionen nsp
            INNER JOIN einsaetze eN ON eN.einsatz_id = nsp.einsatz_id
             LEFT JOIN nu_position_zuweisungen npz ON npz.position_id = nsp.id
              GROUP BY nsp.einsatz_id
            ) nz ON nz.einsatz_id = e.einsatz_id

            -- NU workers: declared headcount (fallback when no zuweisungen yet)
            LEFT JOIN (
                SELECT nsp.einsatz_id,
                       SUM(COALESCE(nsp.anzahl, 0)) AS nu_total_anzahl
                  FROM nu_schicht_positionen nsp
              GROUP BY nsp.einsatz_id
            ) np ON np.einsatz_id = e.einsatz_id

            LEFT JOIN bestellungen ctrl_b ON ctrl_b.bestell_id = e.bestell_id
            LEFT JOIN bestelleingang ctrl_be ON ctrl_be.eingang_id = ctrl_b.eingang_id
            LEFT JOIN (
                SELECT bestell_id, artikel_id, YEAR(datum) AS jahr, MONTH(datum) AS monat,
                       SUM(CASE WHEN hms_bestaetigt <> 'nein' THEN 1 ELSE 0 END) AS einsaetze
                  FROM einsaetze
              GROUP BY bestell_id, artikel_id, YEAR(datum), MONTH(datum)
            ) ctrl_pm ON ctrl_pm.bestell_id = e.bestell_id
                     AND ctrl_pm.artikel_id = e.artikel_id
                     AND ctrl_pm.jahr = YEAR(e.datum)
                     AND ctrl_pm.monat = MONTH(e.datum)
        ";
    }

    /**
     * Effective billable hours per Einsatz, as a SQL expression usable inside
     * any SUM/SELECT after billableJoin() has been added to the FROM clause.
     */
    public static function billableStdExpr(): string
    {
        $planned = self::plannedCustomerHoursExpr();

        return "(
            CASE
                WHEN e.nu_id IS NOT NULL AND e.nu_id <> '' THEN
                    CASE
                        WHEN COALESCE(nz.nu_total_zuweisungen, 0) > 0
                            THEN COALESCE(nz.customer_bill_hours, 0)
                        WHEN COALESCE(np.nu_total_anzahl, 0) > 0
                            THEN np.nu_total_anzahl * {$planned}
                        ELSE {$planned}
                    END
                ELSE
                    CASE
                        WHEN COALESCE(ew.total_workers, 0) = 0 THEN {$planned}
                        ELSE COALESCE(ew.customer_bill_hours, 0)
                    END
            END
        )";
    }

    public static function workStdExpr(): string
    {
        return "(
            CASE
                WHEN e.nu_id IS NOT NULL AND e.nu_id <> '' THEN
                    CASE
                        WHEN COALESCE(nz.nu_total_zuweisungen, 0) > 0 THEN COALESCE(nz.worker_hours, 0)
                        WHEN COALESCE(np.nu_total_anzahl, 0) > 0 THEN np.nu_total_anzahl * COALESCE(e.ist_std_menge, 0)
                        ELSE COALESCE(e.ist_std_menge, 1)
                    END
                ELSE
                    CASE WHEN COALESCE(ew.total_workers, 0) = 0 THEN COALESCE(e.ist_std_menge, 1)
                         ELSE COALESCE(ew.worker_hours, 0) END
            END
        )";
    }

    /**
     * Billable pause fraction of one row. Whether the customer pays the
     * pause is an Objekt-level contract term (objekte.pause_berechnen):
     * 1 = customer pays the pause (full post coverage), 0 = net billing.
     * Resolved through a correlated lookup so every query that has the
     * einsaetze alias `e` can use it without an extra join. Pre-migration
     * tenants keep the legacy rule (pause always billed).
     */
    public static function customerPauseHoursExpr(string $pauseMinutesExpr): string
    {
        if (!\Illuminate\Support\Facades\Schema::connection('tenant')->hasColumn('objekte', 'pause_berechnen')) {
            return "({$pauseMinutesExpr}) / 60";
        }
        return "(CASE WHEN COALESCE((SELECT pb.pause_berechnen FROM objekte pb WHERE pb.obj_id = e.obj_id LIMIT 1), 1) = 1"
            . " THEN ({$pauseMinutesExpr}) / 60 ELSE 0 END)";
    }

    public static function plannedCustomerHoursExpr(): string
    {
        return 'GREATEST(0,
            COALESCE(e.ist_std_menge, 1)
            + ' . self::customerPauseHoursExpr('COALESCE(e.pause_minuten, 0)') . '
        )';
    }

    public static function customerWorkerHoursExpr(string $alias = 'em'): string
    {
        return "GREATEST(0,
            COALESCE(NULLIF({$alias}.ist_std, 0), e.ist_std_menge, 0)
            + " . self::customerPauseHoursExpr("COALESCE({$alias}.pause_minuten, e.pause_minuten, 0)") . "
        )";
    }

    /**
     * Worker count per Einsatz (with fallback of 1 for legacy rows).
     */
    public static function workerCountExpr(): string
    {
        return "(
            CASE
                WHEN e.nu_id IS NOT NULL AND e.nu_id <> '' THEN
                    GREATEST(
                        COALESCE(nz.nu_total_zuweisungen, 0),
                        COALESCE(np.nu_total_anzahl, 0),
                        1
                    )
                ELSE GREATEST(COALESCE(ew.total_workers, 0), 1)
            END
        )";
    }

    public static function billingModelExpr(): string
    {
        return "COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ctrl_be.artikel_preise, CONCAT('$.\"', e.artikel_id, '\".modell'))), 'null'),
            CASE WHEN ctrl_be.artikel_id = e.artikel_id THEN NULLIF(ctrl_be.modell, '') END,
            NULLIF(a.modell, ''),
            'Pro Stunde'
        )";
    }

    public static function customerPriceExpr(): string
    {
        return "COALESCE(
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ctrl_be.artikel_preise, CONCAT('$.\"', e.artikel_id, '\".kundenpreis'))), 'null') AS DECIMAL(12,4)),
            CASE WHEN ctrl_be.artikel_id = e.artikel_id THEN ctrl_be.kundenpreis END,
            a.preis_kunde,
            0
        )";
    }

    public static function nuPriceExpr(): string
    {
        return "COALESCE(
            CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ctrl_be.artikel_preise, CONCAT('$.\"', e.artikel_id, '\".nu_preis'))), 'null') AS DECIMAL(12,4)),
            CASE WHEN ctrl_be.artikel_id = e.artikel_id THEN ctrl_be.nu_preis END,
            a.nu_preis,
            0
        )";
    }

    public static function billingUnitsExpr(?string $hoursExpr = null): string
    {
        $hours = $hoursExpr ?? self::billableStdExpr();
        $model = self::billingModelExpr();

        return "(
            CASE {$model}
                WHEN 'Pro Einsatz' THEN 1
                WHEN 'Monatspauschale' THEN 1 / GREATEST(COALESCE(ctrl_pm.einsaetze, 0), 1)
                ELSE {$hours}
            END
        )";
    }

    public static function revenueBaseExpr(?string $hoursExpr = null): string
    {
        $price = self::customerPriceExpr();
        $units = self::billingUnitsExpr($hoursExpr);

        return "COALESCE(e.ist_betrag, {$price} * {$units})";
    }

    public static function revenueExpr(?string $hoursExpr = null): string
    {
        $base = self::revenueBaseExpr($hoursExpr);

        return "({$base} * (1 + COALESCE(e.zuschlag_pct, 0) / 100))";
    }

    public static function surchargeExpr(?string $hoursExpr = null): string
    {
        $base = self::revenueBaseExpr($hoursExpr);

        return "({$base} * COALESCE(e.zuschlag_pct, 0) / 100)";
    }

    public static function nuCostExpr(?string $hoursExpr = null): string
    {
        $price = self::nuPriceExpr();
        $units = self::billingUnitsExpr($hoursExpr ?? self::workStdExpr());

        return "(CASE WHEN e.nu_id IS NOT NULL AND e.nu_id <> ''
            THEN {$price} * {$units} * (1 + COALESCE(e.zuschlag_pct, 0) / 100)
            ELSE 0 END)";
    }

    public static function workerRevenueExpr(string $workerHoursExpr): string
    {
        $totalHours = self::billableStdExpr();
        $totalRevenue = self::revenueExpr($totalHours);

        return "(CASE WHEN {$totalHours} > 0
            THEN {$totalRevenue} * {$workerHoursExpr} / {$totalHours}
            ELSE 0 END)";
    }

    // ============================================================
    // INTERNAL WORKER COSTS (MA-KOSTEN)
    // ============================================================

    /**
     * Derived join that materialises MA-Kosten per Einsatz_id.
     */
    public static function maKostenJoin(): string
    {
        $lohn = self::maHourlyLohnExpr('m');

        return "
            LEFT JOIN (
                SELECT em.einsatz_id,
                       SUM(
                           COALESCE(NULLIF(em.ist_std, 0), eX.ist_std_menge)
                           * {$lohn}
                       ) AS ma_kosten
                  FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze   eX ON em.einsatz_id = eX.einsatz_id
             LEFT JOIN mitarbeiter m  ON em.dienst_nr  = m.dienst_nr
              GROUP BY em.einsatz_id
            ) mw ON mw.einsatz_id = e.einsatz_id
        ";
    }

    /** SQL expression usable in SUM/SELECT after maKostenJoin() is in FROM. */
    public static function maKostenExpr(): string
    {
        return '(COALESCE(mw.ma_kosten, 0) * (1 + COALESCE(e.zuschlag_pct, 0) / 100))';
    }

    /**
     * Effective HOURLY cost of a mitarbeiter row (default alias "m") as a
     * SQL expression usable inside any SUM/SELECT.
     *
     * The workers form stores the numeric pay amount in `stundenlohn` for
     * BOTH pay models — `lohn_typ` decides what that number means:
     *   'lohn' / NULL → Stundenlohn, used as-is.
     *   'gehalt'      → MONTHLY salary; converted to an hourly equivalent
     *                   via the contractual hours on the record:
     *                     arbeitsstunden_typ='monatlich'   → gehalt / monatl. Std
     *                     otherwise (wöchentlich / unset)  → gehalt / (Wochenstd × 13/3)
     *                   (13/3 ≈ 4,333 Wochen je Monat; 40 h Woche = 173,33 h).
     *   Missing contract hours degrade to a 40 h week rather than to 0 €.
     */
    public static function maHourlyLohnExpr(string $alias = 'm'): string
    {
        static $hasColsByDatabase = [];
        $database = (string) config('database.connections.tenant.database', '');
        if (!array_key_exists($database, $hasColsByDatabase)) {
            $schema = \Illuminate\Support\Facades\Schema::connection('tenant');
            $hasColsByDatabase[$database] = $schema->hasColumn('mitarbeiter', 'lohn_typ')
                && $schema->hasColumn('mitarbeiter', 'arbeitsstunden')
                && $schema->hasColumn('mitarbeiter', 'arbeitsstunden_typ');
        }
        if (!$hasColsByDatabase[$database]) {
            return "COALESCE({$alias}.stundenlohn, 0)";
        }

        return "(
            CASE
                WHEN {$alias}.lohn_typ = 'gehalt' THEN
                    COALESCE({$alias}.stundenlohn, 0) / (
                        CASE
                            WHEN {$alias}.arbeitsstunden_typ = 'monatlich'
                                THEN COALESCE(NULLIF({$alias}.arbeitsstunden, 0), 173.33)
                            ELSE COALESCE(NULLIF({$alias}.arbeitsstunden, 0), 40) * 13 / 3
                        END
                    )
                ELSE COALESCE({$alias}.stundenlohn, 0)
            END
        )";
    }

    // ============================================================
    // PERIOD, FILTERS & WHERE CLAUSE
    // ============================================================

    /**
     * Accepts either explicit ?from=YYYY-MM-DD&to=YYYY-MM-DD
     * or a ?period= short-code (today/week/month/quarter/year/ytd/last_month).
     */
    public static function resolvePeriod(Request $request): array
    {
        $from = $request->input('from');
        $to = $request->input('to');

        if ($from && $to && self::isDate($from) && self::isDate($to)) {
            return [$from, $to];
        }

        $period = strtolower((string) $request->input('period', 'month'));
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->toDateString(), $now->toDateString()],
            'week' => [$now->copy()->startOfWeek()->toDateString(), $now->copy()->endOfWeek()->toDateString()],
            'quarter' => [$now->copy()->firstOfQuarter()->toDateString(), $now->copy()->lastOfQuarter()->toDateString()],
            'year' => [$now->copy()->startOfYear()->toDateString(), $now->copy()->endOfYear()->toDateString()],
            'ytd' => [$now->copy()->startOfYear()->toDateString(), $now->toDateString()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ],
        };
    }

    public static function resolveFilters(Request $request): array
    {
        return array_filter([
            'kunden_id' => $request->input('kunden_id'),
            'obj_id' => $request->input('obj_id'),
            'nu_id' => $request->input('nu_id'),
            'bereich' => $request->input('bereich'),
            'dienst_nr' => $request->input('dienst_nr'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Builds the parameterised WHERE clause shared by every aggregation.
     */
    public static function whereClause(string $from, string $to, array $filters): array
    {
        $where = 'e.datum BETWEEN ? AND ?';
        $params = [$from, $to];

        // Storno-locked shifts (day removed from the Bestelleingang) are not
        // performed work — never count them.
        static $hasStornoByDatabase = [];
        $database = (string) config('database.connections.tenant.database', '');
        if (!array_key_exists($database, $hasStornoByDatabase)) {
            $hasStornoByDatabase[$database] = \Illuminate\Support\Facades\Schema::connection('tenant')
                ->hasColumn('einsaetze', 'bestell_storno_at');
        }
        if ($hasStornoByDatabase[$database]) {
            $where .= ' AND e.bestell_storno_at IS NULL';
        }

        if (!empty($filters['kunden_id'])) {
            $where .= ' AND e.kunden_id = ?';
            $params[] = $filters['kunden_id'];
        }
        if (!empty($filters['obj_id'])) {
            $where .= ' AND e.obj_id    = ?';
            $params[] = $filters['obj_id'];
        }
        if (!empty($filters['nu_id'])) {
            $where .= ' AND e.nu_id     = ?';
            $params[] = $filters['nu_id'];
        }
        if (!empty($filters['bereich'])) {
            // Filter on the EFFECTIVE Bereich (artikel → kunde), comma-aware.
            [$fSql, $fP] = BereichScope::matchBereichExpr(
                [mb_strtolower(trim((string) $filters['bereich']))],
                BereichScope::derivedExprSub('e'));
            $where .= " AND {$fSql}";
            foreach ($fP as $p) { $params[] = $p; }
        }
        if (!empty($filters['dienst_nr'])) {
            // Scope every widget to Einsätze this internal worker took part in.
            $where .= ' AND e.einsatz_id IN (SELECT em0.einsatz_id FROM einsatz_mitarbeiter em0 WHERE em0.dienst_nr = ?)';
            $params[] = $filters['dienst_nr'];
        }

        // Per-user Bereich restriction (effective Bereich, comma-aware).
        [$sSql, $sP] = BereichScope::inList(
            BereichScope::for(Auth::user()), BereichScope::derivedExprSub('e'));
        $where .= " AND {$sSql}";
        foreach ($sP as $p) { $params[] = $p; }

        return [$where, $params];
    }

    public static function plannedOrderKpi(string $from, string $to, array $filters = []): object
    {
        $where = "(
            d.id IS NOT NULL
            OR (
                NOT EXISTS (
                    SELECT 1 FROM bestelleingang_tage dx WHERE dx.eingang_id = b.eingang_id
                )
                AND b.datum_von <= ?
                AND COALESCE(b.datum_bis, b.datum_von) >= ?
            )
        )";
        $params = [$from, $to, $to, $from];

        foreach (['kunden_id', 'obj_id', 'nu_id'] as $filter) {
            if (!empty($filters[$filter])) {
                $where .= " AND b.{$filter} = ?";
                $params[] = $filters[$filter];
            }
        }
        if (!empty($filters['bereich'])) {
            [$fSql, $fP] = BereichScope::matchBereichExpr(
                [mb_strtolower(trim((string) $filters['bereich']))],
                BereichScope::derivedExprSub('b')
            );
            $where .= " AND {$fSql}";
            foreach ($fP as $p) { $params[] = $p; }
        }

        [$scopeSql, $scopeParams] = BereichScope::inList(
            BereichScope::for(Auth::user()), BereichScope::derivedExprSub('b')
        );
        $where .= " AND {$scopeSql}";
        foreach ($scopeParams as $p) { $params[] = $p; }

        return DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS bestellungen_total,
                   COALESCE(SUM(q.soll_stunden), 0) AS soll_stunden
              FROM (
                    SELECT b.id,
                           CASE WHEN COUNT(d.id) > 0
                                THEN SUM(GREATEST(0,
                                    COALESCE(d.soll_std, 0)
                                    + COALESCE(d.pause_minuten, 0) / 60
                                ) * COALESCE(d.menge, 1))
                                ELSE COALESCE(b.soll_std_betrag, 0)
                           END AS soll_stunden
                      FROM bestellungen b
                 LEFT JOIN bestelleingang_tage d
                        ON d.eingang_id = b.eingang_id
                       AND d.datum BETWEEN ? AND ?
                     WHERE {$where}
                  GROUP BY b.id, b.soll_std_betrag
              ) q
        ", $params);
    }

    public static function comparisonRange(string $from, string $to, string $mode): array
    {
        $f = strtotime($from);
        $t = strtotime($to);
        $days = max(1, (int) (($t - $f) / 86400) + 1);

        if ($mode === 'year') {
            return [
                date('Y-m-d', strtotime('-1 year', $f)),
                date('Y-m-d', strtotime('-1 year', $t)),
            ];
        }

        // 'prev' — immediately preceding window of the same length
        return [
            date('Y-m-d', strtotime('-'.$days.' days', $f)),
            date('Y-m-d', strtotime('-'.$days.' days', $t)),
        ];
    }

    // ============================================================
    // BUCKETING & GRANULARITY
    // ============================================================

    public static function bucketExpression(string $granularity): string
    {
        return match ($granularity) {
            'day' => "DATE_FORMAT(e.datum, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(e.datum, '%x-W%v')",
            'quarter' => "CONCAT(YEAR(e.datum),'-Q',QUARTER(e.datum))",
            'year' => "DATE_FORMAT(e.datum, '%Y')",
            default => "DATE_FORMAT(e.datum, '%Y-%m')",
        };
    }

    public static function autoGranularity(string $from, string $to): string
    {
        $days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            $days <= 730 => 'month',
            default => 'quarter',
        };
    }

    /**
     * Ensures every period bucket exists (with zeros) so the line chart is
     * continuous instead of skipping months with no Einsätze.
     */
    public static function fillMissingBuckets(array $points, string $from, string $to, string $granularity): array
    {
        $byBucket = [];
        foreach ($points as $p) {
            $byBucket[$p['bucket']] = $p;
        }

        $expected = self::enumerateBuckets($from, $to, $granularity);
        $filled = [];
        foreach ($expected as $b) {
            $filled[] = $byBucket[$b] ?? [
                'bucket' => $b,
                'einsaetze' => 0,
                'einnahmen' => 0,
                'kosten' => 0,
                'ma_kosten' => 0,
                'gewinn' => 0,
                'marge' => 0,
                'ist_stunden' => 0,
            ];
        }

        return $filled;
    }

    public static function enumerateBuckets(string $from, string $to, string $granularity): array
    {
        $out = [];
        $cur = strtotime($from);
        $end = strtotime($to);

        $i = 0;
        while ($cur <= $end && $i++ < 800) {
            $out[] = self::formatBucket($cur, $granularity);
            $cur = self::advance($cur, $granularity);
        }

        return array_values(array_unique($out));
    }

    public static function formatBucket(int $ts, string $granularity): string
    {
        return match ($granularity) {
            'day' => date('Y-m-d', $ts),
            'week' => date('o-\WW', $ts),
            'quarter' => date('Y', $ts).'-Q'.(int) ceil(((int) date('n', $ts)) / 3),
            'year' => date('Y', $ts),
            default => date('Y-m', $ts),
        };
    }

    public static function advance(int $ts, string $granularity): int
    {
        return match ($granularity) {
            'day' => strtotime('+1 day', $ts),
            'week' => strtotime('+1 week', $ts),
            'quarter' => strtotime('+3 months', $ts),
            'year' => strtotime('+1 year', $ts),
            default => strtotime('+1 month', $ts),
        };
    }

    public static function shiftBack(string $date, string $granularity, int $n): string
    {
        $unit = match ($granularity) {
            'day' => 'days',
            'week' => 'weeks',
            'quarter' => 'months',
            'year' => 'years',
            default => 'months',
        };
        $mult = $granularity === 'quarter' ? 3 : 1;

        return date('Y-m-d', strtotime('-'.($n * $mult).' '.$unit, strtotime($date)));
    }

    public static function periodLabel(string $from, string $to): string
    {
        if ($from === $to) {
            return date('d.m.Y', strtotime($from));
        }

        return date('d.m.Y', strtotime($from)).' – '.date('d.m.Y', strtotime($to));
    }

    public static function isDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
    }

    // ============================================================
    // FORECAST & MATH
    // ============================================================

    /**
     * Number of historical buckets to use as regression input.
     */
    public static function forecastHistoryCount(string $granularity): int
    {
        return match ($granularity) {
            'day' => 30,
            'week' => 24,
            'month' => 18,
            'quarter' => 12,
            'year' => 8,
            default => 12,
        };
    }

    /**
     * Ordinary least-squares linear regression.
     * Returns $horizon projected values (forced non-negative).
     */
    public static function linearForecast(array $series, int $horizon): array
    {
        $n = count($series);
        if ($n < 2) {
            $last = $n === 1 ? (float) $series[0] : 0.0;

            return array_fill(0, $horizon, round(max(0, $last), 2));
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $y = (float) $series[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);
        if (abs($denom) < 1e-9) {
            $avg = $sumY / $n;

            return array_fill(0, $horizon, round(max(0, $avg), 2));
        }

        $slope = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
        $intercept = ($sumY - $slope * $sumX) / $n;

        $out = [];
        for ($k = 1; $k <= $horizon; $k++) {
            $val = $intercept + $slope * ($n + $k);
            $out[] = round(max(0, $val), 2);
        }

        return $out;
    }

    public static function delta(float $curr, float $prev): ?float
    {
        if ($prev == 0.0) {
            return $curr == 0.0 ? 0.0 : null;
        }

        return round((($curr - $prev) / abs($prev)) * 100, 1);
    }

    // ============================================================
    // DECORATORS
    // ============================================================

    public static function decorateBreakdown(array $rows): array
    {
        return array_map(function ($r) {
            $einnahmen = (float) ($r->einnahmen ?? 0);
            $kosten = (float) ($r->kosten ?? 0);
            $maKosten = (float) ($r->ma_kosten ?? 0);
            $gewinn = $einnahmen - $kosten - $maKosten;

            return [
                'dim_id' => $r->dim_id ?? null,
                'dim_value' => $r->dim_value,
                'einsaetze' => (int) ($r->einsaetze ?? 0),
                'einnahmen' => round($einnahmen, 2),
                'kosten' => round($kosten, 2),
                'ma_kosten' => round($maKosten, 2),
                'gewinn' => round($gewinn, 2),
                'marge' => $einnahmen > 0 ? round(($gewinn / $einnahmen) * 100, 1) : 0,
                'ist_stunden' => round((float) ($r->ist_stunden ?? 0), 2),
            ];
        }, $rows);
    }

    public static function whitelist(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }
}
