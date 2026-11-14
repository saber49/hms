<?php

namespace App\Services;

use App\Http\Controllers\Api\WorkerAppZeitController;
use App\Support\BereichScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AbrechnungDataService
{
    public static function confirmedOrderAssignments(string $bestellId): array
    {
        TenantService::assertActive();

        $billJoin = ControllingAggregationService::billableJoin();
        $customerPrice = ControllingAggregationService::customerPriceExpr();
        $billingModel = ControllingAggregationService::billingModelExpr();

        // Kunden-Freigabe (Monatsabschluss, Modus "Kunde") is the gate for
        // every customer-facing document (Leistungsnachweis, order PDFs,
        // order details): only approved rows, with their customer values
        // (kunden_* — snapshotted on approval, independent of the worker's
        // Ist). Worker pay (worker_hours / ma_kosten) stays on the real Ist.
        // Pre-migration tenants: legacy zeit_freigabe gate, NU ungated.
        $hasKunden = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'kunden_freigabe');
        $hasNuKunden = Schema::connection('tenant')->hasColumn('nu_position_zuweisungen', 'kunden_freigabe');
        $freigabeGate = $hasKunden ? ' AND em.kunden_freigabe = 1'
            : (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe') ? ' AND em.zeit_freigabe = 1' : '');
        $nuFreigabeGate = $hasNuKunden ? ' AND npz.kunden_freigabe = 1' : '';

        // Effective customer value of one column: kunden_* first, else the Ist.
        $kv = fn (string $a, bool $on, string $kunden, string $ist) => $on ? "COALESCE({$a}.{$kunden}, {$ist})" : $ist;

        $emStart = $kv('em', $hasKunden, 'kunden_start', 'em.ist_start');
        $emEnde  = $kv('em', $hasKunden, 'kunden_ende', 'em.ist_ende');
        $emStd   = $kv('em', $hasKunden, 'kunden_std', 'em.ist_std');
        $emPause = $kv('em', $hasKunden, 'kunden_pause', 'em.pause_minuten');
        $workerHours = self::customerHoursExpr($emStd, $emPause);
        $workerRevenue = ControllingAggregationService::workerRevenueExpr($workerHours);
        $workerSurcharge = self::workerSurchargeExpr($workerHours);
        $workerPaidHours = 'COALESCE(NULLIF(em.ist_std, 0), e.ist_std_menge, 0)';
        $workerCost = '(' . $workerPaidHours . ' * ' . ControllingAggregationService::maHourlyLohnExpr('m') . ' * (1 + COALESCE(e.zuschlag_pct, 0) / 100))';

        $workers = DB::connection('tenant')->select("
            SELECT m.dienst_nr, m.vorname, m.name, m.foto, m.bereich, m.aktiv AS mitarbeiter_aktiv,
                   e.einsatz_id, e.bestell_id, e.datum, e.schichtart, e.zuschlag_pct,
                   e.artikel_id, a.artikel_name,
                   COALESCE({$emStart}, e.ist_start) AS ist_start,
                   COALESCE({$emEnde}, e.ist_ende) AS ist_ende,
                   {$emStart} AS em_ist_start, {$emEnde} AS em_ist_ende,
                   e.ist_start AS plan_start, e.ist_ende AS plan_end,
                   e.ist_std_menge, {$emStd} AS ist_std,
                   COALESCE({$emPause}, e.pause_minuten, 0) AS pause_minuten,
                   0 AS gesetzl_pause, e.hms_bestaetigt, e.pruefung, o.objektname,
                   {$customerPrice} AS preis_kunde,
                   {$billingModel} AS abrechnungsmodell,
                   {$workerPaidHours} AS worker_hours,
                   {$workerHours} AS kundenstunden,
                   {$workerRevenue} AS einnahmen,
                   {$workerSurcharge} AS aufschlag,
                   {$workerCost} AS ma_kosten,
                   CASE WHEN em.ist_std IS NOT NULL AND em.ist_std > 0 THEN 'actual' ELSE 'planned_fallback' END AS stundenquelle
              FROM einsatz_mitarbeiter em
              INNER JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
              INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
              LEFT JOIN objekte o ON e.obj_id = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
             WHERE e.bestell_id = ? AND e.hms_bestaetigt = 'ja'
                   {$freigabeGate}
             ORDER BY m.vorname ASC, m.name ASC, e.datum ASC
        ", [$bestellId]);

        $nuStart = $kv('npz', $hasNuKunden, 'kunden_start', 'npz.ist_start');
        $nuEnde  = $kv('npz', $hasNuKunden, 'kunden_ende', 'npz.ist_ende');
        $nuStd   = $kv('npz', $hasNuKunden, 'kunden_std', 'npz.ist_std');
        $nuPause = $kv('npz', $hasNuKunden, 'kunden_pause', 'npz.pause_minuten');
        $nuWorkerHours = self::customerHoursExpr($nuStd, $nuPause);
        $nuWorkerRevenue = ControllingAggregationService::workerRevenueExpr($nuWorkerHours);
        $nuWorkerSurcharge = self::workerSurchargeExpr($nuWorkerHours);
        $nuPaidHours = 'COALESCE(NULLIF(npz.ist_std, 0), e.ist_std_menge, 0)';
        $nuWorkerCost = self::nuWorkerCostExpr($nuPaidHours);

        $nuWorkers = DB::connection('tenant')->select("
            SELECT npm.id AS nu_worker_id, npm.vorname, npm.name, npm.personal_nr, npm.foto,
                   npm.nu_id, n.firmenname AS nu_firmenname, nsp.position_name,
                   e.einsatz_id, e.bestell_id, e.datum, e.schichtart, e.zuschlag_pct,
                   e.artikel_id, a.artikel_name,
                   COALESCE({$nuStart}, e.ist_start) AS ist_start,
                   COALESCE({$nuEnde}, e.ist_ende) AS ist_ende,
                   {$nuStart} AS em_ist_start, {$nuEnde} AS em_ist_ende,
                   e.ist_start AS plan_start, e.ist_ende AS plan_end,
                   e.ist_std_menge, {$nuStd} AS ist_std,
                   COALESCE({$nuPause}, e.pause_minuten, 0) AS pause_minuten,
                   0 AS gesetzl_pause, e.hms_bestaetigt, e.pruefung, o.objektname,
                   {$customerPrice} AS preis_kunde,
                   {$billingModel} AS abrechnungsmodell,
                   {$nuPaidHours} AS worker_hours,
                   {$nuWorkerHours} AS kundenstunden,
                   {$nuWorkerRevenue} AS einnahmen,
                   {$nuWorkerSurcharge} AS aufschlag,
                   {$nuWorkerCost} AS nu_kosten,
                   CASE WHEN npz.ist_std IS NOT NULL AND npz.ist_std > 0 THEN 'actual' ELSE 'planned_fallback' END AS stundenquelle
              FROM nu_position_zuweisungen npz
              INNER JOIN nu_portal_mitarbeiter npm ON npz.nu_mitarbeiter_id = npm.id
              INNER JOIN nu_schicht_positionen nsp ON npz.position_id = nsp.id
              INNER JOIN einsaetze e ON nsp.einsatz_id = e.einsatz_id
              LEFT JOIN objekte o ON e.obj_id = o.obj_id
              LEFT JOIN nachunternehmer n ON nsp.nu_id = n.nu_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$billJoin}
             WHERE e.bestell_id = ? AND e.hms_bestaetigt = 'ja'
                   {$nuFreigabeGate}
             ORDER BY n.firmenname ASC, npm.vorname ASC, npm.name ASC, e.datum ASC
        ", [$bestellId]);

        return ['workers' => $workers, 'nu_workers' => $nuWorkers];
    }

    /** Customer-billable hours of one row (same rule as customerWorkerHoursExpr). */
    private static function customerHoursExpr(string $stdExpr, string $pauseExpr): string
    {
        // ONE pause (Planung, einsaetze.pause_minuten): deducted from the
        // worker, paid by the customer — billed = Std + Pause. The former
        // separate Kunden-Pause (gesetzl_pause) is no longer part of it.
        return "GREATEST(0,
            COALESCE(NULLIF({$stdExpr}, 0), e.ist_std_menge, 0)
            + COALESCE({$pauseExpr}, e.pause_minuten, 0) / 60
        )";
    }

    public static function completedAssignments(object $user, string $startDate, string $endDate): array
    {
        TenantService::assertCurrentMatches((int) ($user->tenant_id ?? 0));

        $bereichExpr = "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))";
        $freigabeCol = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')
            ? 'em.zeit_freigabe'
            : '0 AS zeit_freigabe';
        $kundenCols = fn (string $a, string $tbl) => Schema::connection('tenant')->hasColumn($tbl, 'kunden_freigabe')
            ? "{$a}.kunden_start, {$a}.kunden_ende, {$a}.kunden_pause, {$a}.kunden_std, {$a}.kunden_freigabe"
            : 'NULL AS kunden_start, NULL AS kunden_ende, NULL AS kunden_pause, NULL AS kunden_std, 0 AS kunden_freigabe';
        $emKundenCols = $kundenCols('em', 'einsatz_mitarbeiter');
        $nuKundenCols = $kundenCols('npz', 'nu_position_zuweisungen');
        $where = ['e.datum BETWEEN ? AND ?', "e.hms_bestaetigt = 'ja'"];
        $params = [$startDate, $endDate];
        BereichScope::applySql($user, $bereichExpr, $where, $params);

        $sollJoin = self::sollJoin();
        $sollSelect = self::sollSelect();
        $emPauseCol = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'pause_minuten')
            ? 'em.pause_minuten AS em_pause' : 'NULL AS em_pause';
        $realInCol = Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_in_real')
            ? 'z.clock_in_real AS app_clock_in_real' : 'NULL AS app_clock_in_real';
        $realInCol .= Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengerung_gruende')
            ? ', z.verlaengerungen AS app_verlaengerungen, z.verlaengerung_gruende AS app_verlaengerung_gruende'
            : ', NULL AS app_verlaengerungen, NULL AS app_verlaengerung_gruende';
        $realInCol .= Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_out_real')
            ? ', z.clock_out_real AS app_clock_out_real' : ', NULL AS app_clock_out_real';
        $realInCol .= ', z.notiz AS app_notiz';
        // Only for the main query (em is joined there; the orphan query has no em).
        $korrCols = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_korrektur')
            ? 'em.zeit_korrektur_am, em.zeit_korrektur_von' : 'NULL AS zeit_korrektur_am, NULL AS zeit_korrektur_von';

        $rows = DB::connection('tenant')->select("
            SELECT m.dienst_nr, m.vorname, m.name, m.foto, m.bereich, m.aktiv AS mitarbeiter_aktiv,
                   e.einsatz_id, e.bestell_id, e.datum,
                   COALESCE(em.ist_start, e.ist_start) AS ist_start,
                   COALESCE(em.ist_ende, e.ist_ende) AS ist_ende,
                   em.ist_start AS em_ist_start, em.ist_ende AS em_ist_ende,
                   e.ist_start AS plan_start, e.ist_ende AS plan_end,
                   e.ist_std_menge,
                   COALESCE(em.pause_minuten, e.pause_minuten, 0) AS pause_minuten,
                   {$emPauseCol},
                   e.gesetzl_pause, e.pause_minuten AS plan_pause, em.ist_std,
                   e.beschreibung, e.hms_bestaetigt, e.pruefung, k.kundenname, o.objektname,
                   o.adresse AS objekt_adresse, o.plz AS objekt_plz, o.stadt AS objekt_stadt,
                   {$sollSelect},
                   z.clock_in AS app_clock_in, z.clock_out AS app_clock_out,
                   z.pause_total AS app_pause, {$realInCol}, {$korrCols},
                   {$freigabeCol}, {$emKundenCols}
              FROM einsatz_mitarbeiter em
              INNER JOIN mitarbeiter m ON em.dienst_nr = m.dienst_nr
              INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
              LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              LEFT JOIN zeiterfassung z ON z.id = (
                    SELECT z2.id FROM zeiterfassung z2
                     WHERE z2.einsatz_id = em.einsatz_id AND z2.dienst_nr = em.dienst_nr
                       AND z2.status = 'beendet' AND z2.clock_out IS NOT NULL
                     ORDER BY z2.clock_in DESC LIMIT 1)
              {$sollJoin}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY m.vorname ASC, m.name ASC, e.datum ASC, e.ist_start ASC
        ", $params);
        foreach ($rows as $row) {
            $row->ist_quelle = self::istQuelle($row);
        }

        $orphanWhere = ['e.datum BETWEEN ? AND ?', "z.status = 'beendet'", 'z.clock_out IS NOT NULL'];
        $orphanParams = [$startDate, $endDate];
        BereichScope::applySql($user, $bereichExpr, $orphanWhere, $orphanParams);
        $orphans = DB::connection('tenant')->select("
            SELECT m.dienst_nr, m.vorname, m.name, m.foto, m.bereich, m.aktiv AS mitarbeiter_aktiv,
                   e.einsatz_id, e.bestell_id, e.datum,
                   e.ist_start AS plan_start, e.ist_ende AS plan_end,
                   e.pause_minuten AS e_pause, e.gesetzl_pause,
                   e.beschreibung, e.hms_bestaetigt, e.pruefung,
                   k.kundenname, o.objektname,
                   o.adresse AS objekt_adresse, o.plz AS objekt_plz, o.stadt AS objekt_stadt,
                   {$sollSelect},
                   z.clock_in, z.clock_out, z.pause_total, {$realInCol}
              FROM zeiterfassung z
              INNER JOIN einsaetze e ON z.einsatz_id = e.einsatz_id
              INNER JOIN mitarbeiter m ON z.dienst_nr = m.dienst_nr
              LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              {$sollJoin}
             WHERE " . implode(' AND ', $orphanWhere) . "
               AND NOT EXISTS (SELECT 1 FROM einsatz_mitarbeiter em2
                                WHERE em2.einsatz_id = z.einsatz_id
                                  AND em2.dienst_nr = z.dienst_nr)
        ", $orphanParams);

        foreach ($orphans as $orphan) {
            $clockIn = Carbon::parse($orphan->clock_in);
            $clockOut = Carbon::parse($orphan->clock_out);
            $pauseMin = WorkerAppZeitController::deductedPause((int) $orphan->pause_total, (int) ($orphan->e_pause ?? $orphan->gesetzl_pause ?? 0));
            $worked = round(max(0, $clockOut->getTimestamp() - $clockIn->getTimestamp() - $pauseMin * 60) / 3600, 2);
            $inLocal = $clockIn->copy()->setTimezone('Europe/Berlin')->format('H:i');
            $outLocal = $clockOut->copy()->setTimezone('Europe/Berlin')->format('H:i');
            $rows[] = (object) [
                'dienst_nr' => $orphan->dienst_nr, 'vorname' => $orphan->vorname,
                'name' => $orphan->name, 'foto' => $orphan->foto, 'bereich' => $orphan->bereich,
                'mitarbeiter_aktiv' => $orphan->mitarbeiter_aktiv,
                'einsatz_id' => $orphan->einsatz_id, 'bestell_id' => $orphan->bestell_id, 'datum' => $orphan->datum,
                'ist_start' => $inLocal,
                'ist_ende' => $outLocal,
                'em_ist_start' => $inLocal, 'em_ist_ende' => $outLocal,
                'plan_start' => $orphan->plan_start, 'plan_end' => $orphan->plan_end,
                'ist_std_menge' => null,
                'pause_minuten' => $pauseMin, 'em_pause' => $pauseMin, 'gesetzl_pause' => $orphan->gesetzl_pause,
                'plan_pause' => $orphan->e_pause,
                'ist_std' => $worked,
                'beschreibung' => $orphan->beschreibung, 'hms_bestaetigt' => $orphan->hms_bestaetigt,
                'pruefung' => $orphan->pruefung, 'kundenname' => $orphan->kundenname,
                'objektname' => $orphan->objektname, 'zeit_freigabe' => 0,
                'objekt_adresse' => $orphan->objekt_adresse, 'objekt_plz' => $orphan->objekt_plz,
                'objekt_stadt' => $orphan->objekt_stadt,
                'kunden_start' => null, 'kunden_ende' => null, 'kunden_pause' => null,
                'kunden_std' => null, 'kunden_freigabe' => 0,
                'soll_start' => $orphan->soll_start, 'soll_ende' => $orphan->soll_ende,
                'soll_pause' => $orphan->soll_pause, 'soll_std' => $orphan->soll_std,
                'app_clock_in' => $orphan->clock_in, 'app_clock_out' => $orphan->clock_out,
                'app_pause' => $orphan->pause_total, 'app_clock_in_real' => $orphan->app_clock_in_real,
                'app_verlaengerungen' => $orphan->app_verlaengerungen,
                'app_verlaengerung_gruende' => $orphan->app_verlaengerung_gruende,
                'app_clock_out_real' => $orphan->app_clock_out_real, 'app_notiz' => $orphan->app_notiz,
                'ist_quelle' => 'app',
            ];
        }

        $totalStd = array_sum(array_map(fn ($row) => $row->ist_std !== null && $row->ist_std !== '' && (float) $row->ist_std > 0
            ? (float) $row->ist_std
            : ($row->ist_std_menge ? (float) $row->ist_std_menge : 0), $rows));

        $nuWhere = ['e.datum BETWEEN ? AND ?', "e.hms_bestaetigt = 'ja'"];
        $nuParams = [$startDate, $endDate];
        BereichScope::applySql($user, $bereichExpr, $nuWhere, $nuParams);
        $nuRows = DB::connection('tenant')->select("
            SELECT npm.id AS nu_worker_id, npm.vorname, npm.name, npm.personal_nr, npm.foto,
                   npm.nu_id, n.firmenname AS nu_firmenname,
                   e.einsatz_id, e.bestell_id, e.datum,
                   COALESCE(npz.ist_start, e.ist_start) AS ist_start,
                   COALESCE(npz.ist_ende, e.ist_ende) AS ist_ende,
                   npz.ist_start AS em_ist_start, npz.ist_ende AS em_ist_ende,
                   e.ist_start AS plan_start, e.ist_ende AS plan_end,
                   e.ist_std_menge,
                   COALESCE(npz.pause_minuten, e.pause_minuten, 0) AS pause_minuten,
                   e.gesetzl_pause, e.pause_minuten AS plan_pause, npz.ist_std,
                   e.beschreibung, e.hms_bestaetigt, e.pruefung,
                   k.kundenname, o.objektname,
                   o.adresse AS objekt_adresse, o.plz AS objekt_plz, o.stadt AS objekt_stadt,
                   npz.pause_minuten AS em_pause,
                   {$sollSelect},
                   nsp.position_name, nsp.id AS position_id,
                   {$nuKundenCols}
              FROM nu_position_zuweisungen npz
              INNER JOIN nu_portal_mitarbeiter npm ON npz.nu_mitarbeiter_id = npm.id
              INNER JOIN nu_schicht_positionen nsp ON npz.position_id = nsp.id
              INNER JOIN einsaetze e ON nsp.einsatz_id = e.einsatz_id
              LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id = o.obj_id
              LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
              LEFT JOIN nachunternehmer n ON nsp.nu_id = n.nu_id
              {$sollJoin}
             WHERE " . implode(' AND ', $nuWhere) . "
             ORDER BY n.firmenname ASC, npm.vorname ASC, npm.name ASC, e.datum ASC
        ", $nuParams);
        foreach ($nuRows as $row) {
            $row->ist_quelle = ($row->em_ist_start || $row->em_ist_ende || ($row->ist_std !== null && (float) $row->ist_std > 0))
                ? 'manuell' : null;
        }

        $nuTotalStd = array_sum(array_map(fn ($row) => $row->ist_std !== null && $row->ist_std !== '' && (float) $row->ist_std > 0
            ? (float) $row->ist_std
            : ($row->ist_std_menge ? (float) $row->ist_std_menge : 0), $nuRows));

        return [
            'entries' => $rows,
            'nu_entries' => $nuRows,
            'total_workers' => count(array_unique(array_column($rows, 'dienst_nr'))),
            'total_entries' => count($rows),
            'total_std' => round($totalStd, 2),
            'nu_total_workers' => count(array_unique(array_column($nuRows, 'nu_worker_id'))),
            'nu_total_entries' => count($nuRows),
            'nu_total_std' => round($nuTotalStd, 2),
        ];
    }

    /**
     * Join the original order day (bestelleingang_tage) of an einsatz `e` as `bt`:
     * same date, matched by tag_nr (fallback: first day row of that date).
     */
    private static function sollJoin(): string
    {
        return "LEFT JOIN bestellungen sb ON e.bestell_id = sb.bestell_id
              LEFT JOIN bestelleingang_tage bt ON bt.eingang_id = sb.eingang_id AND bt.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND bt.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND bt.tag_nr = (
                        SELECT MIN(bt2.tag_nr) FROM bestelleingang_tage bt2
                         WHERE bt2.eingang_id = sb.eingang_id AND bt2.datum = e.datum))
                )";
    }

    /**
     * Soll = the original plan from the Bestelleingang day row; shifts without
     * an order fall back to the einsatz plan.
     */
    private static function sollSelect(): string
    {
        $pause = Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'pause_minuten')
            ? 'COALESCE(bt.pause_minuten, e.pause_minuten)' : 'e.pause_minuten';
        return "COALESCE(NULLIF(bt.zeit_von, ''), e.ist_start) AS soll_start,
                   COALESCE(NULLIF(bt.zeit_bis, ''), e.ist_ende) AS soll_ende,
                   {$pause} AS soll_pause,
                   COALESCE(bt.soll_std, e.ist_std_menge) AS soll_std";
    }

    /**
     * Where the per-worker Ist comes from: 'app' (exactly the worker's
     * clock record), 'korrigiert' (clocked, then changed by the office),
     * 'manuell' (entered by the office without a clock record) or null.
     */
    private static function istQuelle(object $row): ?string
    {
        $hhmm = fn ($v) => $v ? substr((string) $v, 0, 5) : null;
        if (!empty($row->app_clock_in) && !empty($row->app_clock_out)) {
            $in  = Carbon::parse($row->app_clock_in)->setTimezone('Europe/Berlin')->format('H:i');
            $out = Carbon::parse($row->app_clock_out)->setTimezone('Europe/Berlin')->format('H:i');
            return ($hhmm($row->em_ist_start) === $in && $hhmm($row->em_ist_ende) === $out) ? 'app' : 'korrigiert';
        }
        if ($row->em_ist_start || $row->em_ist_ende || ($row->ist_std !== null && (float) $row->ist_std > 0)) {
            return 'manuell';
        }
        return null;
    }

    private static function workerSurchargeExpr(string $workerHoursExpr): string
    {
        $totalHours = ControllingAggregationService::billableStdExpr();
        $totalSurcharge = ControllingAggregationService::surchargeExpr($totalHours);

        return "(CASE WHEN {$totalHours} > 0
            THEN {$totalSurcharge} * {$workerHoursExpr} / {$totalHours}
            ELSE 0 END)";
    }

    private static function nuWorkerCostExpr(string $workerHoursExpr): string
    {
        $totalHours = ControllingAggregationService::workStdExpr();
        $totalCost = ControllingAggregationService::nuCostExpr();

        return "(CASE WHEN {$totalHours} > 0
            THEN {$totalCost} * {$workerHoursExpr} / {$totalHours}
            ELSE 0 END)";
    }
}
