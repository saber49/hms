<?php

namespace App\Services;

use App\Services\ControllingAggregationService as Agg;
use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Controlling-Cockpit — German market standard (stufenweise Deckungsbeitrags-
 * rechnung + Objektcontrolling), built on the shared SQL expressions of
 * ControllingAggregationService so revenue/hour figures stay identical.
 *
 *   Umsatz − NU-Kosten                         = Rohertrag
 *   − Personal operativ (Lohn inkl. Zuschläge)  = DB I
 *   − Lohnnebenkosten (AG-Anteil × Ausfallzeit) = DB II
 *   − monatliche Fixkosten (Sammelposition)     = Betriebsergebnis
 *
 * Hours come from the Zeiterfassung (Monatsabschluss): Kundenstunden
 * (kunden_std → worker Ist → plan) for revenue, worker Ist for staff cost.
 * Status classes (weighted — a partly released Einsatz is split):
 *   freigegeben  share released for the customer (kunden_freigabe) (Abrechnungssicht)
 *   offen        past, staffed, not (yet) released                (+ = operative Sicht)
 *   unbesetzt    past, nobody assigned                            (not performed)
 *   geplant      in the future                                    (Hochrechnung only)
 *   abgelehnt    hms_bestaetigt = 'nein'
 * Tenants without kunden_* columns fall back to hms_bestaetigt = 'ja'.
 * Storno-locked shifts are excluded in Agg::whereClause().
 */
final class ControllingCockpitService
{
    public const DEFAULTS = [
        'ag_anteil'      => 21.7,   // AG-Anteile SV + Umlagen in %
        'ausfall_faktor' => 1.156,  // bezahlte ÷ produktive Stunden (Urlaub, Feiertag, Krankheit)
        'ampel_rot'      => 10.0,   // DB-II-Marge unter X % = rot
        'ampel_gelb'     => 20.0,   // unter Y % = gelb, sonst grün
    ];

    public const BASIS = [
        'abrechnung' => ['freigegeben'],
        'operativ'   => ['freigegeben', 'offen'],
    ];

    public const STATUSES = ['freigegeben', 'offen', 'unbesetzt', 'geplant', 'abgelehnt'];

    /** Status combinations summed in compute() — need distinct shift counts. */
    private const COUNT_COMBOS = [['freigegeben', 'offen'], ['freigegeben', 'offen', 'geplant']];

    private const EMPTY = ['einsaetze' => 0, 'umsatz' => 0.0, 'nu_kosten' => 0.0, 'personal' => 0.0, 'stunden' => 0.0];

    // ============================================================
    // SETTINGS
    // ============================================================

    public static function settings(): array
    {
        $raw = (array) ((TenantService::current()?->settings ?? [])['controlling'] ?? []);
        $out = [];
        foreach (self::DEFAULTS as $k => $v) {
            $out[$k] = isset($raw[$k]) && is_numeric($raw[$k]) ? (float) $raw[$k] : $v;
        }
        return $out;
    }

    /** Vollkostenfaktor applied to the operative staff cost. */
    public static function factor(array $s): float
    {
        return (1 + $s['ag_anteil'] / 100) * $s['ausfall_faktor'];
    }

    public static function today(): string
    {
        return Carbon::now('Europe/Berlin')->toDateString();
    }

    // ============================================================
    // SQL BUILDING BLOCKS
    // ============================================================

    // ------------------------------------------------------------
    // Hours come from the Zeiterfassung (= Monatsabschluss) data:
    //   Kundenstunden  einsatz_mitarbeiter / nu_position_zuweisungen
    //                  .kunden_std (what is billed) → worker Ist → plan
    //   Personalkosten em.ist_std (clocked / corrected Ist) → plan
    // "freigegeben" = released for the CUSTOMER in the Zeiterfassung
    // (kunden_freigabe), row-exact: a partly released Einsatz counts its
    // released share as freigegeben and the rest in its normal class.
    // Tenants without the kunden_* columns keep hms_bestaetigt.
    // ------------------------------------------------------------

    /** kunden_* columns present on both assignment tables (per tenant DB). */
    private static function hasKunden(): bool
    {
        static $byDb = [];
        $db = (string) config('database.connections.tenant.database', '');
        if (!array_key_exists($db, $byDb)) {
            $s = Schema::connection('tenant');
            $byDb[$db] = $s->hasColumn('einsatz_mitarbeiter', 'kunden_std') && $s->hasColumn('einsatz_mitarbeiter', 'kunden_freigabe')
                && $s->hasColumn('nu_position_zuweisungen', 'kunden_std') && $s->hasColumn('nu_position_zuweisungen', 'kunden_freigabe');
        }
        return $byDb[$db];
    }

    /**
     * Same shape and aliases as Agg::billableJoin() (ew / nz / np / ctrl_*),
     * so every shared revenue/cost expression works unchanged — but the
     * customer hours prefer the Kundenstunden of the Zeiterfassung and the
     * released (kunden_freigabe) share is materialised per Einsatz.
     */
    private static function billJoin(): string
    {
        $k = self::hasKunden();
        $row = fn (string $a, string $e) => "GREATEST(0,
                           COALESCE(NULLIF({$a}.ist_std, 0), {$e}.ist_std_menge, 0)
                           + COALESCE({$a}.pause_minuten, {$e}.pause_minuten, 0) / 60)";
        // Customer hours = Kunden-Std + the one (Planung) pause the customer
        // pays — same rule as the Leistungsnachweis (AbrechnungDataService).
        $cust = fn (string $a, string $e) => $k
            ? "COALESCE({$a}.kunden_std + COALESCE({$a}.kunden_pause, {$e}.pause_minuten, 0) / 60, {$row($a, $e)})"
            : $row($a, $e);
        $frei = fn (string $a, string $e) => $k ? "CASE WHEN {$a}.kunden_freigabe = 1 THEN {$cust($a, $e)} ELSE 0 END" : '0';
        $freiRows = fn (string $a) => $k ? "SUM(CASE WHEN {$a}.kunden_freigabe = 1 THEN 1 ELSE 0 END)" : '0';

        return "
            LEFT JOIN (
                SELECT em.einsatz_id,
                       SUM({$cust('em', 'eW')}) AS customer_bill_hours,
                       SUM({$frei('em', 'eW')}) AS frei_hours,
                       {$freiRows('em')} AS frei_rows,
                       SUM(COALESCE(NULLIF(em.ist_std, 0), eW.ist_std_menge, 0)) AS worker_hours,
                       COUNT(*) AS total_workers
                  FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze eW ON eW.einsatz_id = em.einsatz_id
              GROUP BY em.einsatz_id
            ) ew ON ew.einsatz_id = e.einsatz_id

            LEFT JOIN (
                SELECT nsp.einsatz_id,
                       SUM({$cust('npz', 'eN')}) AS customer_bill_hours,
                       SUM({$frei('npz', 'eN')}) AS frei_hours,
                       {$freiRows('npz')} AS frei_rows,
                       SUM(COALESCE(NULLIF(npz.ist_std, 0), eN.ist_std_menge, 0)) AS worker_hours,
                       COUNT(npz.id) AS nu_total_zuweisungen
                  FROM nu_schicht_positionen nsp
            INNER JOIN einsaetze eN ON eN.einsatz_id = nsp.einsatz_id
             LEFT JOIN nu_position_zuweisungen npz ON npz.position_id = nsp.id
              GROUP BY nsp.einsatz_id
            ) nz ON nz.einsatz_id = e.einsatz_id

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

    /** Released-for-the-customer share of an Einsatz (0..1). */
    private static function freiAnteil(): string
    {
        if (!self::hasKunden()) {
            return "(CASE WHEN e.hms_bestaetigt = 'ja' THEN 1 ELSE 0 END)";
        }
        $tot = '(COALESCE(ew.customer_bill_hours, 0) + COALESCE(nz.customer_bill_hours, 0))';
        $rows = '(COALESCE(ew.total_workers, 0) + COALESCE(nz.nu_total_zuweisungen, 0))';
        return "(CASE WHEN e.hms_bestaetigt = 'nein' THEN 0
                      WHEN {$tot} > 0 THEN LEAST(1, (COALESCE(ew.frei_hours, 0) + COALESCE(nz.frei_hours, 0)) / {$tot})
                      WHEN {$rows} > 0 AND (COALESCE(ew.frei_rows, 0) + COALESCE(nz.frei_rows, 0)) = {$rows} THEN 1
                      ELSE 0 END)";
    }

    /** Class of the NOT released part of an Einsatz. */
    private static function restStatusExpr(): string
    {
        $today = self::today(); // server-generated Y-m-d, safe to inline
        return "(CASE WHEN e.hms_bestaetigt = 'nein' THEN 'abgelehnt'
                      WHEN e.datum > '{$today}' THEN 'geplant'
                      WHEN COALESCE(ew.total_workers, 0) > 0 OR (e.nu_id IS NOT NULL AND e.nu_id <> '') THEN 'offen'
                      ELSE 'unbesetzt' END)";
    }

    /** Weight (0..1) of an Einsatz inside the given status classes. */
    private static function weight(array $statuses): string
    {
        $f = self::freiAnteil();
        $parts = [];
        if (in_array('freigegeben', $statuses, true)) $parts[] = $f;
        $rest = array_values(array_diff($statuses, ['freigegeben']));
        if ($rest) $parts[] = '(CASE WHEN ' . self::restStatusExpr() . ' IN (' . self::inList($rest) . ") THEN 1 - {$f} ELSE 0 END)";
        return $parts ? '(' . implode(' + ', $parts) . ')' : '0';
    }

    /** Row-exact class of one internal assignment row (alias em). */
    private static function rowStatusExpr(): string
    {
        $rest = self::restStatusExpr();
        if (!self::hasKunden()) {
            return "(CASE WHEN e.hms_bestaetigt = 'ja' THEN 'freigegeben' ELSE {$rest} END)";
        }
        return "(CASE WHEN e.hms_bestaetigt <> 'nein' AND em.kunden_freigabe = 1 THEN 'freigegeben' ELSE {$rest} END)";
    }

    /** Revenue share of one internal row (alias em) by its own Kundenstunden. */
    private static function workerRevenueExpr(): string
    {
        $own = self::hasKunden()
            ? 'COALESCE(em.kunden_std, ' . Agg::customerWorkerHoursExpr('em') . ')'
            : Agg::customerWorkerHoursExpr('em');
        $tot = Agg::billableStdExpr();
        return "(CASE WHEN {$tot} > 0 THEN " . Agg::revenueExpr($tot) . " * {$own} / {$tot} ELSE 0 END)";
    }

    private static function inList(array $statuses): string
    {
        return "'" . implode("','", array_values(array_intersect($statuses, self::STATUSES))) . "'";
    }

    /** Average effective hourly wage — cost estimate for shifts nobody is planned on yet. */
    private static function avgLohn(): float
    {
        $lohn = Agg::maHourlyLohnExpr('m');
        $v = DB::connection('tenant')->selectOne(
            "SELECT AVG(NULLIF({$lohn}, 0)) AS v FROM mitarbeiter m WHERE COALESCE(m.aktiv, 1) = 1"
        )->v ?? 0;
        return (float) $v;
    }

    /** Operative staff cost; unstaffed internal shifts are estimated with the average wage. */
    private static function personalExpr(float $avgLohn): string
    {
        $ma = Agg::maKostenExpr();
        $work = Agg::workStdExpr();
        $avg = sprintf('%.4F', $avgLohn);
        return "(CASE WHEN COALESCE(ew.total_workers, 0) = 0 AND (e.nu_id IS NULL OR e.nu_id = '')
                      THEN {$work} * {$avg} * (1 + COALESCE(e.zuschlag_pct, 0) / 100)
                      ELSE {$ma} END)";
    }

    private static function fromSql(): string
    {
        return 'FROM einsaetze e LEFT JOIN artikel a ON e.artikel_id = a.artikel_id '
            . self::billJoin() . ' ' . Agg::maKostenJoin();
    }

    /** Standard metric columns weighted by the given status classes. */
    private static function metricCols(array $statuses, float $avgLohn): string
    {
        $w = self::weight($statuses);
        $bill = Agg::billableStdExpr();
        $sum = fn (string $x) => "COALESCE(SUM(({$x}) * {$w}), 0)";

        return "SUM(CASE WHEN {$w} > 0 THEN 1 ELSE 0 END) AS einsaetze, "
            . $sum(Agg::revenueExpr($bill)) . ' AS umsatz, '
            . $sum(Agg::nuCostExpr()) . ' AS nu_kosten, '
            . $sum(self::personalExpr($avgLohn)) . ' AS personal, '
            . $sum($bill) . ' AS stunden';
    }

    // ============================================================
    // CALCULATION
    // ============================================================

    /** Staged contribution margin for one metric bag. */
    public static function stufen(array $r, float $F, array $s): array
    {
        $u = (float) ($r['umsatz'] ?? 0);
        $nu = (float) ($r['nu_kosten'] ?? 0);
        $p = (float) ($r['personal'] ?? 0);
        $h = (float) ($r['stunden'] ?? 0);
        $roh = $u - $nu;
        $db1 = $roh - $p;
        $lnk = $p * ($F - 1);
        $db2 = $db1 - $lnk;
        $pct = fn (float $x) => $u > 0 ? round($x / $u * 100, 1) : null;

        return [
            'einsaetze'       => (int) ($r['einsaetze'] ?? 0),
            'umsatz'          => round($u, 2),
            'nu_kosten'       => round($nu, 2),
            'rohertrag'       => round($roh, 2),
            'personal'        => round($p, 2),
            'db1'             => round($db1, 2),
            'lohnnebenkosten' => round($lnk, 2),
            'db2'             => round($db2, 2),
            'marge_db1'       => $pct($db1),
            'marge_db2'       => $pct($db2),
            'stunden'         => round($h, 2),
            'erloes_h'        => $h > 0 ? round($u / $h, 2) : null,
            'kosten_h'        => $h > 0 ? round(($nu + $p * $F) / $h, 2) : null,
            'ampel'           => self::ampel($u, $nu + $p, $pct($db2), $s),
        ];
    }

    /** null = keine Daten (never a false red light). */
    public static function ampel(float $umsatz, float $kosten, ?float $marge, array $s): ?string
    {
        if ($umsatz <= 0 && $kosten <= 0) return null;
        if ($umsatz <= 0 || $marge === null) return 'rot';
        return $marge < $s['ampel_rot'] ? 'rot' : ($marge < $s['ampel_gelb'] ? 'gelb' : 'gruen');
    }

    /** Metric bags per status class (+ count of shifts without staff, oldest date, missing prices). */
    public static function statusTotals(string $from, string $to, array $filters, float $avgLohn,
        string $extraWhere = '', array $extraParams = []): array
    {
        [$where, $params] = Agg::whereClause($from, $to, $filters);
        $where .= $extraWhere !== '' ? " {$extraWhere}" : '';
        $params = array_merge($params, $extraParams);
        $bill = Agg::billableStdExpr();
        $price = Agg::customerPriceExpr();
        $rev = Agg::revenueExpr($bill);
        $nu = Agg::nuCostExpr();
        $pers = self::personalExpr($avgLohn);
        $f = self::freiAnteil();
        $unstaffed = "(COALESCE(ew.total_workers, 0) = 0 AND (e.nu_id IS NULL OR e.nu_id = ''))";

        // One row, one weighted column block per status class. A partly
        // released Einsatz sits in two classes, so combined views get their
        // own DISTINCT counts (a plain sum would count it twice).
        $cols = ["SUM(CASE WHEN {$f} > 0 AND {$f} < 1 THEN 1 ELSE 0 END) AS teilweise"];
        foreach (self::COUNT_COMBOS as $i => $combo) {
            $cols[] = 'SUM(CASE WHEN ' . self::weight($combo) . " > 0 THEN 1 ELSE 0 END) AS combo{$i}";
        }
        foreach (self::STATUSES as $s) {
            $w = self::weight([$s]);
            $cols[] = "SUM(CASE WHEN {$w} > 0 THEN 1 ELSE 0 END) AS {$s}__einsaetze";
            $cols[] = "COALESCE(SUM(({$rev}) * {$w}), 0) AS {$s}__umsatz";
            $cols[] = "COALESCE(SUM(({$nu}) * {$w}), 0) AS {$s}__nu_kosten";
            $cols[] = "COALESCE(SUM(({$pers}) * {$w}), 0) AS {$s}__personal";
            $cols[] = "COALESCE(SUM(({$bill}) * {$w}), 0) AS {$s}__stunden";
            $cols[] = "SUM(CASE WHEN {$w} > 0 AND {$unstaffed} THEN 1 ELSE 0 END) AS {$s}__ohne_personal";
            $cols[] = "SUM(CASE WHEN {$w} > 0 AND e.ist_betrag IS NULL AND {$price} <= 0 THEN 1 ELSE 0 END) AS {$s}__ohne_preis";
            $cols[] = "MIN(CASE WHEN {$w} > 0 THEN e.datum END) AS {$s}__min_datum";
        }

        $r = (array) DB::connection('tenant')->selectOne(
            'SELECT ' . implode(",\n", $cols) . ' ' . self::fromSql() . " WHERE {$where}", $params);

        $out = ['_teilweise' => (int) ($r['teilweise'] ?? 0), '_count' => []];
        foreach (self::COUNT_COMBOS as $i => $combo) {
            $out['_count'][implode(',', $combo)] = (int) ($r["combo{$i}"] ?? 0);
        }
        foreach (self::STATUSES as $s) {
            $out[$s] = [
                'einsaetze'     => (int) ($r["{$s}__einsaetze"] ?? 0),
                'umsatz'        => (float) ($r["{$s}__umsatz"] ?? 0),
                'nu_kosten'     => (float) ($r["{$s}__nu_kosten"] ?? 0),
                'personal'      => (float) ($r["{$s}__personal"] ?? 0),
                'stunden'       => (float) ($r["{$s}__stunden"] ?? 0),
                'ohne_personal' => (int) ($r["{$s}__ohne_personal"] ?? 0),
                'ohne_preis'    => (int) ($r["{$s}__ohne_preis"] ?? 0),
                'min_datum'     => $r["{$s}__min_datum"] ?? null,
            ];
        }
        return $out;
    }

    private static function sumStatuses(array $totals, array $statuses): array
    {
        $acc = self::EMPTY;
        foreach ($statuses as $s) {
            foreach ($acc as $k => $_) {
                $acc[$k] += $totals[$s][$k] ?? 0;
            }
        }
        $key = implode(',', $statuses);
        if (count($statuses) > 1 && isset($totals['_count'][$key])) {
            $acc['einsaetze'] = $totals['_count'][$key];
        }
        return $acc;
    }

    /** Generic Top-N style breakdown (all rows, sorted later). */
    private static function breakdown(string $dimSelect, string $groupBy, string $joins, string $extraWhere,
        string $from, string $to, array $filters, array $statuses, float $avgLohn, string $extraCols = '',
        array $extraParams = []): array
    {
        [$where, $params] = Agg::whereClause($from, $to, $filters);
        $params = array_merge($params, $extraParams);
        $sql = "SELECT {$dimSelect}, " . self::metricCols($statuses, $avgLohn) . ($extraCols ? ", {$extraCols}" : '')
            . ' ' . self::fromSql() . " {$joins}
             WHERE {$where} {$extraWhere}
          GROUP BY {$groupBy}
            HAVING einsaetze > 0";

        return array_map(fn ($r) => (array) $r, DB::connection('tenant')->select($sql, $params));
    }

    private static function decorate(array $rows, float $F, array $s, array $keep = ['dim_id', 'name']): array
    {
        return array_map(function ($r) use ($F, $s, $keep) {
            $out = array_intersect_key($r, array_flip($keep));
            return $out + self::stufen($r, $F, $s);
        }, $rows);
    }

    /** Internal workers: revenue share by own hours, cost = hours × wage × Vollkostenfaktor. */
    private static function mitarbeiter(string $from, string $to, array $filters, array $statuses, float $F, array $s,
        string $extraWhere = '', array $extraParams = []): array
    {
        [$where, $params] = Agg::whereClause($from, $to, $filters);
        $where .= $extraWhere !== '' ? " {$extraWhere}" : '';
        $params = array_merge($params, $extraParams);
        $st = self::rowStatusExpr();
        $in = self::inList($statuses);
        $hours = 'COALESCE(NULLIF(em.ist_std, 0), e.ist_std_menge, 0)';
        $rev = self::workerRevenueExpr();
        $lohn = Agg::maHourlyLohnExpr('m');
        $cost = "({$hours} * {$lohn} * (1 + COALESCE(e.zuschlag_pct, 0) / 100))";
        $c = fn (string $x) => "COALESCE(SUM(CASE WHEN {$st} IN ({$in}) THEN {$x} ELSE 0 END), 0)";

        $rows = DB::connection('tenant')->select("
            SELECT em.dienst_nr AS dim_id,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(MAX(m.vorname), ''), ' ', COALESCE(MAX(m.name), ''))), ''), em.dienst_nr) AS name,
                   SUM(CASE WHEN {$st} IN ({$in}) THEN 1 ELSE 0 END) AS einsaetze,
                   {$c($rev)} AS umsatz, 0 AS nu_kosten, {$c($cost)} AS personal, {$c($hours)} AS stunden,
                   MAX(CASE WHEN {$lohn} <= 0 THEN 1 ELSE 0 END) AS ohne_lohn
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON e.einsatz_id = em.einsatz_id
              LEFT JOIN artikel a ON a.artikel_id = e.artikel_id
              " . self::billJoin() . "
              LEFT JOIN mitarbeiter m ON m.dienst_nr = em.dienst_nr
             WHERE {$where} AND (e.nu_id IS NULL OR e.nu_id = '')
          GROUP BY em.dienst_nr
            HAVING einsaetze > 0
        ", $params);

        return array_map(function ($r) use ($F, $s) {
            $r = (array) $r;
            return ['dim_id' => $r['dim_id'], 'name' => $r['name'], 'ohne_lohn' => (bool) $r['ohne_lohn']]
                + self::stufen($r, $F, $s);
        }, $rows);
    }

    /**
     * Where do the hours of the performed shifts (up to today) come from?
     * Personal side: app clock (Zeiterfassung) / entered by the office / plan only.
     * Kunden side:   share of customer hours already released.
     */
    private static function datenbasis(string $from, string $to, array $filters, array $totals): ?array
    {
        $toCut = min($to, self::today());
        if ($toCut < $from) return null;
        [$where, $params] = Agg::whereClause($from, $toCut, $filters);
        $h = 'COALESCE(NULLIF(em.ist_std, 0), e.ist_std_menge, 0)';
        $app = Schema::connection('tenant')->hasTable('zeiterfassung')
            ? "EXISTS (SELECT 1 FROM zeiterfassung z WHERE z.einsatz_id = em.einsatz_id AND z.dienst_nr = em.dienst_nr
                         AND z.status = 'beendet' AND z.clock_out IS NOT NULL)"
            : '0';
        $manual = "(em.ist_start IS NOT NULL AND em.ist_start <> '') OR COALESCE(em.ist_std, 0) > 0";

        $ma = DB::connection('tenant')->selectOne("
            SELECT COALESCE(SUM(CASE WHEN {$app} THEN {$h} ELSE 0 END), 0) AS app,
                   COALESCE(SUM(CASE WHEN NOT ({$app}) AND ({$manual}) THEN {$h} ELSE 0 END), 0) AS manuell,
                   COALESCE(SUM(CASE WHEN NOT ({$app}) AND NOT ({$manual}) THEN {$h} ELSE 0 END), 0) AS plan
              FROM einsatz_mitarbeiter em
              JOIN einsaetze e ON e.einsatz_id = em.einsatz_id
             WHERE {$where} AND e.hms_bestaetigt <> 'nein'
        ", $params);
        $nu = DB::connection('tenant')->selectOne("
            SELECT COALESCE(SUM(CASE WHEN (npz.ist_start IS NOT NULL AND npz.ist_start <> '') OR COALESCE(npz.ist_std, 0) > 0
                                     THEN COALESCE(NULLIF(npz.ist_std, 0), e.ist_std_menge, 0) ELSE 0 END), 0) AS manuell,
                   COALESCE(SUM(CASE WHEN (npz.ist_start IS NULL OR npz.ist_start = '') AND COALESCE(npz.ist_std, 0) = 0
                                     THEN COALESCE(e.ist_std_menge, 0) ELSE 0 END), 0) AS plan
              FROM nu_position_zuweisungen npz
              JOIN nu_schicht_positionen nsp ON nsp.id = npz.position_id
              JOIN einsaetze e ON e.einsatz_id = nsp.einsatz_id
             WHERE {$where} AND e.hms_bestaetigt <> 'nein'
        ", $params);

        $app = (float) $ma->app;
        $man = (float) $ma->manuell + (float) $nu->manuell;
        $plan = (float) $ma->plan + (float) $nu->plan;
        $sum = $app + $man + $plan;
        $pct = fn (float $x, float $t) => $t > 0 ? round($x / $t * 100, 1) : null;
        $kFrei = $totals['freigegeben']['stunden'];
        $kAll = $kFrei + $totals['offen']['stunden'];

        return [
            'stunden'         => round($sum, 1),
            'app_pct'         => $pct($app, $sum),
            'manuell_pct'     => $pct($man, $sum),
            'plan_pct'        => $pct($plan, $sum),
            'kunden_frei_pct' => $pct($kFrei, $kAll),
            'kunden_freigabe' => self::hasKunden(),
            'teilweise'       => $totals['_teilweise'] ?? 0,
        ];
    }

    /** Soll-Stunden per Objekt from the Bestelleingang day rows (same rule as plannedOrderKpi). */
    private static function sollByObjekt(string $from, string $to, array $filters): array
    {
        if (!empty($filters['dienst_nr']) || $to < $from) return [];

        $where = '1=1';
        $params = [$from, $to];
        foreach (['kunden_id', 'obj_id', 'nu_id'] as $f) {
            if (!empty($filters[$f])) {
                $where .= " AND b.{$f} = ?";
                $params[] = $filters[$f];
            }
        }
        if (!empty($filters['bereich'])) {
            [$fSql, $fP] = BereichScope::matchBereichExpr(
                [mb_strtolower(trim((string) $filters['bereich']))], BereichScope::derivedExprSub('b'));
            $where .= " AND {$fSql}";
            array_push($params, ...$fP);
        }
        [$sSql, $sP] = BereichScope::inList(BereichScope::for(auth()->user()), BereichScope::derivedExprSub('b'));
        $where .= " AND {$sSql}";
        array_push($params, ...$sP);

        $rows = DB::connection('tenant')->select("
            SELECT b.obj_id,
                   SUM(GREATEST(0, COALESCE(d.soll_std, 0) + COALESCE(d.pause_minuten, 0) / 60) * COALESCE(d.menge, 1)) AS soll
              FROM bestelleingang_tage d
              JOIN bestellungen b ON b.eingang_id = d.eingang_id
             WHERE d.datum BETWEEN ? AND ? AND {$where}
          GROUP BY b.obj_id
        ", $params);

        $out = [];
        foreach ($rows as $r) $out[(string) $r->obj_id] = (float) $r->soll;
        return $out;
    }

    // ============================================================
    // FIXKOSTEN
    // ============================================================

    /** Fixed costs only make sense company-wide: no entity filter, unrestricted user. */
    public static function fixApplies(array $filters): bool
    {
        return BereichScope::for(auth()->user()) === null
            && empty(array_intersect_key($filters, array_flip(['kunden_id', 'obj_id', 'nu_id', 'bereich', 'dienst_nr'])));
    }

    /** Fixed costs pro rata (by days) for a period: total + per month. */
    public static function fixkosten(string $from, string $to): array
    {
        $out = ['total' => 0.0, 'by_month' => []];
        if ($to < $from || !Schema::connection('tenant')->hasTable('controlling_fixkosten')) return $out;

        $rows = DB::connection('tenant')->table('controlling_fixkosten')
            ->where('ab_monat', '<=', $to)
            ->where(fn ($q) => $q->whereNull('bis_monat')->orWhere('bis_monat', '>=', substr($from, 0, 7) . '-01'))
            ->get(['betrag', 'ab_monat', 'bis_monat']);
        if ($rows->isEmpty()) return $out;

        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        for ($m = $f->copy()->startOfMonth(); $m->lte($t); $m->addMonthNoOverflow()) {
            $mStart = $m->toDateString();
            $mEnd = $m->copy()->endOfMonth()->startOfDay();
            $start = $f->gt($m) ? $f : $m;
            $end = $t->lt($mEnd) ? $t : $mEnd;
            // Carbon 3 returns float day diffs — round to whole days.
            $share = ((int) round($start->diffInDays($end)) + 1) / $m->daysInMonth;
            $sum = 0.0;
            foreach ($rows as $r) {
                if ($r->ab_monat <= $mStart && ($r->bis_monat === null || $r->bis_monat >= $mStart)) {
                    $sum += (float) $r->betrag * $share;
                }
            }
            $out['by_month'][$m->format('Y-m')] = round($sum, 2);
            $out['total'] += $sum;
        }
        $out['total'] = round($out['total'], 2);
        return $out;
    }

    private static function fixSignature(): string
    {
        if (!Schema::connection('tenant')->hasTable('controlling_fixkosten')) return '0';
        $r = DB::connection('tenant')->selectOne('SELECT COUNT(*) c, MAX(updated_at) u, SUM(betrag) s FROM controlling_fixkosten');
        return "{$r->c}|{$r->u}|{$r->s}";
    }

    // ============================================================
    // COMPARISON
    // ============================================================

    /**
     * Like-for-like comparison window. Full month/quarter/year → the previous
     * one (or the same one a year earlier); free ranges → same length before.
     * When the current period reaches into the future both sides are cut at
     * the equivalent of today, so a running month never looks like a crash.
     */
    public static function comparison(string $from, string $to, string $mode): ?array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        $today = Carbon::parse(self::today());
        if ($f->gt($today)) return null;

        $isMonth = $f->day === 1 && $t->isSameDay($f->copy()->endOfMonth());
        $isQuarter = $f->day === 1 && $f->month % 3 === 1 && $t->isSameDay($f->copy()->addMonthsNoOverflow(2)->endOfMonth());
        $isYear = $f->isSameDay($f->copy()->startOfYear()) && $t->isSameDay($f->copy()->endOfYear());

        $shift = function (Carbon $d) use ($mode, $isMonth, $isQuarter, $isYear, $f, $t): Carbon {
            if ($mode === 'year' || $isYear) return $d->copy()->subYearNoOverflow();
            if ($isQuarter) return $d->copy()->subMonthsNoOverflow(3);
            if ($isMonth) return $d->copy()->subMonthNoOverflow();
            return $d->copy()->subDays((int) round($f->diffInDays($t)) + 1);
        };

        $cFrom = $shift($f);
        $cTo = ($isMonth && $mode !== 'year') ? $cFrom->copy()->endOfMonth()
            : (($isQuarter && $mode !== 'year') ? $cFrom->copy()->addMonthsNoOverflow(2)->endOfMonth() : $shift($t));
        $fullTo = $cTo->toDateString();
        $curTo = $t;
        $cut = $t->gt($today);
        if ($cut) {
            $curTo = $today;
            $eq = $shift($today);
            if ($eq->lt($cTo)) $cTo = $eq;
        }

        return [
            'from'   => $cFrom->toDateString(),
            'to'     => $cTo->toDateString(),
            'full_to' => $fullTo,
            'cur_to' => $curTo->toDateString(),
            'cut'    => $cut,
            'mode'   => $mode,
            'label'  => Agg::periodLabel($cFrom->toDateString(), $cTo->toDateString()),
        ];
    }

    private static function delta(array $cur, array $prev): array
    {
        $out = [];
        foreach (['umsatz', 'rohertrag', 'db1', 'db2', 'stunden', 'einsaetze', 'betriebsergebnis'] as $k) {
            $c = $cur[$k] ?? null;
            $p = $prev[$k] ?? null;
            $out[$k] = ($c === null || $p === null) ? null : Agg::delta((float) $c, (float) $p);
        }
        // Margin difference in percentage points, not percent of percent.
        $out['marge_db2_pp'] = ($cur['marge_db2'] ?? null) !== null && ($prev['marge_db2'] ?? null) !== null
            ? round($cur['marge_db2'] - $prev['marge_db2'], 1) : null;
        return $out;
    }

    // ============================================================
    // PUBLIC FACADE — the ONE calculation for every screen that shows
    // money: Übersicht, Drill-down, Einsatzliste, Exports, Mitarbeiter-
    // Analyse and the KI-Assistent. "ma_kosten" in legacy payloads is the
    // Personal-VOLLKOSTEN (operativ × Vollkostenfaktor) so that
    // einnahmen − kosten − ma_kosten = DB II everywhere.
    // Extra predicates use the alias `e` and must start with "AND".
    // ============================================================

    /** Settings, Vollkostenfaktor and average wage for one request. */
    public static function context(): array
    {
        TenantService::assertActive();
        $s = self::settings();
        return ['s' => $s, 'F' => self::factor($s), 'avg' => self::avgLohn()];
    }

    public static function statusesFor(?string $basis): array
    {
        return self::BASIS[$basis] ?? self::BASIS['operativ'];
    }

    /** Status bags for a period (+ optional extra predicate). */
    public static function totals(array $ctx, string $from, string $to, array $filters = [],
        string $extraWhere = '', array $extraParams = []): array
    {
        return self::statusTotals($from, $to, $filters, $ctx['avg'], $extraWhere, $extraParams);
    }

    /** Staged DB for the given classes out of totals(). */
    public static function result(array $ctx, array $totals, array $statuses): array
    {
        return self::stufen(self::sumStatuses($totals, $statuses), $ctx['F'], $ctx['s']);
    }

    /** Grouped rows; $dimSelect aliases its group key(s), $keep lists the columns to pass through. */
    public static function rows(array $ctx, string $dimSelect, string $groupBy, string $joins,
        string $from, string $to, array $filters, array $statuses,
        string $extraWhere = '', array $extraParams = [], array $keep = ['dim_id', 'name']): array
    {
        return self::decorate(self::breakdown($dimSelect, $groupBy, $joins, $extraWhere, $from, $to, $filters,
            $statuses, $ctx['avg'], '', $extraParams), $ctx['F'], $ctx['s'], $keep);
    }

    /** Internal workers (row-exact classes), cost incl. Vollkostenfaktor. */
    public static function mitarbeiterRows(array $ctx, string $from, string $to, array $filters, array $statuses,
        string $extraWhere = '', array $extraParams = []): array
    {
        return self::mitarbeiter($from, $to, $filters, $statuses, $ctx['F'], $ctx['s'], $extraWhere, $extraParams);
    }

    /** FROM einsaetze e + artikel a + Zeiterfassung-aware joins (ew, nz, np, ctrl_b/be/pm, mw). */
    public static function from(): string
    {
        return self::fromSql();
    }

    /** Joins only (for queries that start FROM einsatz_mitarbeiter em JOIN einsaetze e). */
    public static function billJoinSql(): string
    {
        return self::billJoin();
    }

    /**
     * Per-Einsatz value columns weighted by the given classes — for lists
     * and exports. Aliases: einnahmen, nu_kosten, ma_kosten (Vollkosten),
     * gewinn (= DB II), billable_std, frei_anteil, rest_status.
     */
    public static function einsatzCols(array $ctx, array $statuses): string
    {
        $w = self::weight($statuses);
        $bill = Agg::billableStdExpr();
        $rev = Agg::revenueExpr($bill);
        $nu = Agg::nuCostExpr();
        $p = self::personalExpr($ctx['avg']);
        $F = sprintf('%.6F', $ctx['F']);

        return "({$rev}) * {$w} AS einnahmen,
                ({$nu}) * {$w} AS nu_kosten,
                ({$p}) * {$F} * {$w} AS ma_kosten,
                (({$rev}) - ({$nu}) - ({$p}) * {$F}) * {$w} AS gewinn,
                {$bill} AS billable_std,
                " . self::freiAnteil() . ' AS frei_anteil,
                ' . self::restStatusExpr() . ' AS rest_status';
    }

    /** SQL weight (0..1) of an Einsatz inside the given classes — needs from(). */
    public static function weightSql(array $statuses): string
    {
        return self::weight($statuses);
    }

    /** Display status of one Einsatz out of einsatzCols(). */
    public static function einsatzStatus($freiAnteil, ?string $rest): string
    {
        $f = (float) $freiAnteil;
        return $f >= 0.999 ? 'freigegeben' : ($f > 0.001 ? 'teilweise' : (string) $rest);
    }

    /** Row-exact class of one internal assignment row (alias em). */
    public static function rowStatus(): string
    {
        return self::rowStatusExpr();
    }

    /** Revenue share of one internal row (alias em) by its Kundenstunden. */
    public static function workerRevenue(): string
    {
        return self::workerRevenueExpr();
    }

    /** Legacy breakdown shape (einnahmen/kosten/ma_kosten/gewinn/marge/ist_stunden) from stufen(). */
    public static function legacyRow(array $st): array
    {
        return [
            'einsaetze'       => $st['einsaetze'],
            'einnahmen'       => $st['umsatz'],
            'kosten'          => $st['nu_kosten'],
            'ma_kosten'       => round($st['personal'] + $st['lohnnebenkosten'], 2),
            'gewinn'          => $st['db2'],
            'marge'           => $st['marge_db2'] ?? 0,
            'ist_stunden'     => $st['stunden'],
            'db1'             => $st['db1'],
            'lohnnebenkosten' => $st['lohnnebenkosten'],
            'ampel'           => $st['ampel'],
        ];
    }

    // ============================================================
    // ENTRY POINT
    // ============================================================

    public static function cockpit(string $from, string $to, array $filters, string $basis, string $compare): array
    {
        TenantService::assertActive();
        $s = self::settings();
        $basis = isset(self::BASIS[$basis]) ? $basis : 'operativ';
        $key = Agg::cacheKey('cockpit', [
            'from' => $from, 'to' => $to, 'filters' => $filters, 'basis' => $basis, 'compare' => $compare,
            'settings' => $s, 'today' => self::today(), 'fix' => self::fixSignature(),
        ]);

        return Cache::remember($key, Agg::CACHE_TTL, fn () => self::compute($from, $to, $filters, $basis, $compare, $s));
    }

    private static function compute(string $from, string $to, array $filters, string $basis, string $compare, array $s): array
    {
        $F = self::factor($s);
        $avg = self::avgLohn();
        $statuses = self::BASIS[$basis];
        $today = self::today();
        $fixOn = self::fixApplies($filters);

        $withFix = function (array $st, string $f, string $t) use ($fixOn): array {
            $fix = $fixOn ? self::fixkosten($f, $t)['total'] : null;
            $st['fixkosten'] = $fix;
            $st['betriebsergebnis'] = $fix === null ? null : round($st['db2'] - $fix, 2);
            return $st;
        };

        // ---- Ergebnis + status classes ----
        $totals = self::statusTotals($from, $to, $filters, $avg);
        $ergebnis = $withFix(self::stufen(self::sumStatuses($totals, $statuses), $F, $s), $from, min($to, $today) >= $from ? min($to, $today) : $to);
        $status = [];
        foreach (self::STATUSES as $k) {
            $v = $totals[$k];
            $status[$k] = [
                'einsaetze'     => $v['einsaetze'],
                'umsatz'        => round($v['umsatz'], 2),
                'stunden'       => round($v['stunden'], 2),
                'ohne_personal' => $v['ohne_personal'],
                'min_datum'     => $v['min_datum'],
            ];
        }

        // ---- Vergleich (like-for-like) ----
        $vergleich = null;
        if ($cmp = self::comparison($from, $to, $compare)) {
            $curT = $cmp['cur_to'] === $to ? $totals : self::statusTotals($from, $cmp['cur_to'], $filters, $avg);
            $cur = $withFix(self::stufen(self::sumStatuses($curT, $statuses), $F, $s), $from, $cmp['cur_to']);
            $prev = $withFix(self::stufen(self::sumStatuses(self::statusTotals($cmp['from'], $cmp['to'], $filters, $avg), $statuses), $F, $s), $cmp['from'], $cmp['to']);
            $vergleich = $cmp + ['aktuell' => $cur, 'vorher' => $prev, 'delta' => self::delta($cur, $prev)];
        }

        // ---- Hochrechnung (only when the period contains today) ----
        $hochrechnung = null;
        if ($from <= $today && $today <= $to) {
            $erwartet = $withFix(self::stufen(self::sumStatuses($totals, ['freigegeben', 'offen', 'geplant']), $F, $s), $from, $to);
            $hochrechnung = [
                'erwartet'                => $erwartet,
                'davon_freigegeben'       => round($totals['freigegeben']['umsatz'], 2),
                'davon_offen'             => round($totals['offen']['umsatz'], 2),
                'davon_geplant'           => round($totals['geplant']['umsatz'], 2),
                'geplant_ohne_personal'   => $totals['geplant']['ohne_personal'],
                // Full previous period (not cut) — what the projection has to beat.
                'vergleich'               => $vergleich ? $withFix(self::stufen(self::sumStatuses(
                    self::statusTotals($vergleich['from'], $vergleich['full_to'], $filters, $avg),
                    ['freigegeben', 'offen']), $F, $s), $vergleich['from'], $vergleich['full_to']) : null,
                'vergleich_label'         => $vergleich ? Agg::periodLabel($vergleich['from'], $vergleich['full_to']) : null,
            ];
        }

        // ---- Breakdowns ----
        $objektRows = self::breakdown(
            "e.obj_id AS dim_id, COALESCE(MAX(o.objektname), e.obj_id, '–') AS name, MAX(k.kundenname) AS kunde, MAX(e.kunden_id) AS kunden_id",
            'e.obj_id', 'LEFT JOIN objekte o ON o.obj_id = e.obj_id LEFT JOIN kunden k ON k.kunde_id = e.kunden_id', '',
            $from, $to, $filters, $statuses, $avg,
            'COALESCE(SUM((' . Agg::billableStdExpr() . ') * ' . self::weight(['freigegeben', 'offen']) . '), 0) AS ist_std'
        );
        $sollTo = min($to, $today);
        $soll = self::sollByObjekt($from, $sollTo, $filters);
        $objekte = array_map(function ($r) use ($F, $s, $soll) {
            $row = ['dim_id' => $r['dim_id'], 'name' => $r['name'], 'kunde' => $r['kunde'], 'kunden_id' => $r['kunden_id']]
                + self::stufen($r, $F, $s);
            $sl = $soll[(string) $r['dim_id']] ?? null;
            $ist = round((float) $r['ist_std'], 2);
            $row['soll_std'] = $sl !== null ? round($sl, 2) : null;
            $row['ist_std'] = $ist;
            $row['abweichung_std'] = $sl !== null ? round($ist - $sl, 2) : null;
            $row['abweichung_pct'] = $sl ? round(($ist - $sl) / $sl * 100, 1) : null;
            return $row;
        }, $objektRows);

        // ABC by DB II: A = top 80 % of the positive contribution, B = next 15 %, C = rest / negative.
        usort($objekte, fn ($a, $b) => $b['db2'] <=> $a['db2']);
        $pos = array_sum(array_map(fn ($o) => max(0, $o['db2']), $objekte));
        $cum = 0.0;
        foreach ($objekte as &$o) {
            if ($o['db2'] <= 0 || $pos <= 0) { $o['abc'] = 'C'; continue; }
            $cum += $o['db2'];
            $share = $cum / $pos;
            $o['abc'] = $share <= 0.8 ? 'A' : ($share <= 0.95 ? 'B' : 'C');
        }
        unset($o);
        // Worst margin first (rows without data at the end).
        usort($objekte, fn ($a, $b) => [$a['marge_db2'] === null, $a['marge_db2'] ?? 0] <=> [$b['marge_db2'] === null, $b['marge_db2'] ?? 0]);

        $byWorst = function (array $rows): array {
            usort($rows, fn ($a, $b) => [$a['marge_db2'] === null, $a['marge_db2'] ?? 0] <=> [$b['marge_db2'] === null, $b['marge_db2'] ?? 0]);
            return $rows;
        };

        $kunden = $byWorst(self::decorate(self::breakdown(
            "e.kunden_id AS dim_id, COALESCE(MAX(k.kundenname), e.kunden_id, '–') AS name",
            'e.kunden_id', 'LEFT JOIN kunden k ON k.kunde_id = e.kunden_id', '',
            $from, $to, $filters, $statuses, $avg), $F, $s));

        $artikel = self::decorate(self::breakdown(
            "e.artikel_id AS dim_id, COALESCE(MAX(a.artikel_name), e.artikel_id, '–') AS name",
            'e.artikel_id', '', '', $from, $to, $filters, $statuses, $avg), $F, $s);
        // Preis-Check: lowest contribution per hour first.
        usort($artikel, function ($a, $b) {
            $ka = $a['erloes_h'] !== null && $a['kosten_h'] !== null ? $a['erloes_h'] - $a['kosten_h'] : INF;
            $kb = $b['erloes_h'] !== null && $b['kosten_h'] !== null ? $b['erloes_h'] - $b['kosten_h'] : INF;
            return $ka <=> $kb;
        });

        $nu = $byWorst(self::decorate(self::breakdown(
            "e.nu_id AS dim_id, COALESCE(MAX(n.firmenname), e.nu_id) AS name",
            'e.nu_id', 'LEFT JOIN nachunternehmer n ON n.nu_id = e.nu_id', "AND e.nu_id IS NOT NULL AND e.nu_id <> ''",
            $from, $to, $filters, $statuses, $avg), $F, $s));

        $bExpr = 'COALESCE(' . BereichScope::derivedExprSub('e') . ", '–')";
        $bereiche = array_map(fn ($r) => ['name' => $r['dim_id']] + $r, self::decorate(self::breakdown(
            "{$bExpr} AS dim_id", 'dim_id', '', '',
            $from, $to, $filters, $statuses, $avg), $F, $s, ['dim_id']));
        usort($bereiche, fn ($a, $b) => $b['umsatz'] <=> $a['umsatz']);

        $mitarbeiter = $byWorst(self::mitarbeiter($from, $to, $filters, $statuses, $F, $s));

        // ---- Trend: 12 months ending with the period's last month ----
        $tEnd = Carbon::parse($to)->startOfMonth();
        $tStart = $tEnd->copy()->subMonthsNoOverflow(11);
        [$tw, $tp] = Agg::whereClause($tStart->toDateString(), $tEnd->copy()->endOfMonth()->toDateString(), $filters);
        $revT = Agg::revenueExpr(Agg::billableStdExpr());
        $trendRows = DB::connection('tenant')->select("
            SELECT DATE_FORMAT(e.datum, '%Y-%m') AS ym, " . self::metricCols($statuses, $avg) . ",
                   COALESCE(SUM(({$revT}) * " . self::weight(['geplant']) . "), 0) AS umsatz_geplant,
                   COALESCE(SUM(({$revT}) * " . self::weight(['offen']) . "), 0) AS umsatz_offen
            " . self::fromSql() . " WHERE {$tw} GROUP BY ym ORDER BY ym
        ", $tp);
        $byYm = [];
        foreach ($trendRows as $r) $byYm[$r->ym] = (array) $r;
        $fixByMonth = $fixOn ? self::fixkosten($tStart->toDateString(), $tEnd->copy()->endOfMonth()->toDateString())['by_month'] : [];
        $trend = [];
        for ($m = $tStart->copy(); $m->lte($tEnd); $m->addMonthNoOverflow()) {
            $ym = $m->format('Y-m');
            $r = $byYm[$ym] ?? self::EMPTY;
            $row = ['monat' => $ym] + self::stufen($r, $F, $s);
            $row['umsatz_geplant'] = round((float) ($r['umsatz_geplant'] ?? 0), 2);
            $row['umsatz_offen'] = round((float) ($r['umsatz_offen'] ?? 0), 2);
            $row['fixkosten'] = $fixOn ? ($fixByMonth[$ym] ?? 0.0) : null;
            $row['betriebsergebnis'] = $fixOn ? round($row['db2'] - $row['fixkosten'], 2) : null;
            $trend[] = $row;
        }

        // ---- Datenqualität ----
        $dq = [
            'einsaetze_ohne_preis' => array_sum(array_map(fn ($k) => $totals[$k]['ohne_preis'], $statuses)),
            'mitarbeiter_ohne_lohn' => count(array_filter($mitarbeiter, fn ($m) => $m['ohne_lohn'])),
        ];

        return [
            'period'       => ['from' => $from, 'to' => $to, 'label' => Agg::periodLabel($from, $to), 'today' => $today],
            'basis'        => $basis,
            'settings'     => $s + ['vollkostenfaktor' => round($F, 4), 'avg_stundenlohn' => round($avg, 2)],
            'fix_aktiv'    => $fixOn,
            'ergebnis'     => $ergebnis,
            'status'       => $status,
            'vergleich'    => $vergleich,
            'hochrechnung' => $hochrechnung,
            'objekte'      => $objekte,
            'kunden'       => $kunden,
            'artikel'      => $artikel,
            'nu'           => $nu,
            'mitarbeiter'  => $mitarbeiter,
            'bereiche'     => $bereiche,
            'trend'        => $trend,
            'datenqualitaet' => $dq,
            'datenbasis'   => self::datenbasis($from, $to, $filters, $totals),
        ];
    }
}
