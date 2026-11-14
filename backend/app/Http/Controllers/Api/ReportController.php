<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControllingAggregationService;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Legacy /api/reports endpoint.
     *
     * Billable-hours helpers now live in ControllingAggregationService so the
     * legacy report and the Controlling dashboard stay numerically consistent.
     */
    public function index(Request $request)
    {
        $where = '1=1';
        $params = [];
        $startDate = '1900-01-01';
        $endDate = '2100-12-31';

        if ($request->filled('kunden_id')) {
            $where .= ' AND e.kunden_id = ?';
            $params[] = $request->kunden_id;
        }
        if ($request->filled('obj_id')) {
            $where .= ' AND e.obj_id = ?';
            $params[] = $request->obj_id;
        }
        if ($request->filled('nu_id')) {
            $where .= ' AND e.nu_id = ?';
            $params[] = $request->nu_id;
        }
        if ($request->filled('monat') && $request->filled('jahr')) {
            // Use a date range so MySQL can leverage idx_einsaetze_datum.
            $monat = max(1, min(12, (int) $request->monat));
            $jahr = max(1900, (int) $request->jahr);
            $startDate = sprintf('%04d-%02d-01', $jahr, $monat);
            $endDate = date('Y-m-t', strtotime($startDate));
            $where .= ' AND e.datum BETWEEN ? AND ?';
            $params[] = $startDate;
            $params[] = $endDate;
        }
        if ($request->filled('status')) {
            $where .= ' AND e.hms_bestaetigt = ?';
            $params[] = $request->status;
        }

        // Bereich scope: effective Bereich = artikel.bereich → kunden.bereich.
        [$scopeSql, $scopeParams] = BereichScope::inList(
            BereichScope::for($request->user()), BereichScope::derivedExprSub('e'));
        $where .= " AND {$scopeSql}";
        foreach ($scopeParams as $p) { $params[] = $p; }

        $bill = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        $maJoin = ControllingAggregationService::maKostenJoin();
        $maCost = ControllingAggregationService::maKostenExpr();
        $revenue = ControllingAggregationService::revenueExpr($bill);
        $nuCost = ControllingAggregationService::nuCostExpr();
        $customerPrice = ControllingAggregationService::customerPriceExpr();
        $nuPrice = ControllingAggregationService::nuPriceExpr();

        $kpi = DB::connection('tenant')->selectOne("
            SELECT
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END), 0) AS einnahmen,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END), 0) AS nu_kosten,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END), 0) AS ma_kosten,
                COUNT(CASE WHEN e.hms_bestaetigt = 'ja' THEN 1 END) AS bestaetigt_count,
                COUNT(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN 1 END) AS ausstehend_count,
                COUNT(CASE WHEN e.hms_bestaetigt = 'nein' THEN 1 END) AS nicht_bestaetigt_count,
                COALESCE(SUM(CASE WHEN e.hms_bestaetigt = 'ja' THEN {$bill} ELSE 0 END), 0) AS ist_stunden_total,
                COUNT(*) AS total_einsaetze
            FROM einsaetze e
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            {$billJoin}
            {$maJoin}
            WHERE $where
        ", $params);

        $kpi->gewinn = $kpi->einnahmen - $kpi->nu_kosten - $kpi->ma_kosten;
        $kpi->marge = $kpi->einnahmen > 0 ? round(($kpi->gewinn / $kpi->einnahmen) * 100, 1) : 0;
        $kpi->gewinn_pro_einsatz = $kpi->bestaetigt_count > 0 ? round($kpi->gewinn / $kpi->bestaetigt_count, 2) : 0;

        // Order stats
        $orderKpi = ControllingAggregationService::plannedOrderKpi($startDate, $endDate, array_filter([
            'kunden_id' => $request->input('kunden_id'),
            'obj_id' => $request->input('obj_id'),
            'nu_id' => $request->input('nu_id'),
        ], fn ($v) => $v !== null && $v !== ''));

        $kpi->bestellungen_total = $orderKpi->bestellungen_total;
        $kpi->soll_stunden = $orderKpi->soll_stunden;
        $kpi->delta_stunden = $kpi->soll_stunden - $kpi->ist_stunden_total;

        $details = DB::connection('tenant')->select("
            SELECT e.datum, k.kundenname, o.objektname, a.artikel_name, n.firmenname AS nu_name, e.ist_std_menge,
                   {$customerPrice} AS preis_kunde, {$nuPrice} AS nu_preis,
                   GREATEST(COALESCE(ew.total_workers, 0), 1) AS workers,
                   {$bill} AS billable_std,
                   CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} ELSE 0 END AS einnahmen_detail,
                   CASE WHEN e.hms_bestaetigt = 'ja' THEN {$nuCost} ELSE 0 END AS nu_kosten_detail,
                   CASE WHEN e.hms_bestaetigt = 'ja' THEN {$maCost} ELSE 0 END AS ma_kosten_detail,
                   CASE WHEN e.hms_bestaetigt = 'ja' THEN {$revenue} - {$nuCost} - {$maCost} ELSE 0 END AS gewinn_detail,
                   e.hms_bestaetigt
            FROM einsaetze e
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            LEFT JOIN nachunternehmer n ON e.nu_id = n.nu_id
            {$billJoin}
            {$maJoin}
            WHERE $where ORDER BY e.datum DESC
        ", $params);

        return response()->json(['kpi' => $kpi, 'details' => $details]);
    }
}
