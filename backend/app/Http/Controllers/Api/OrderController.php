<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Support\BereichScope;
use App\Services\AbrechnungDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $query = DB::connection('tenant')
            ->table('bestellungen as b')
            ->leftJoin('kunden as k', 'b.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'b.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'b.artikel_id', '=', 'a.artikel_id')
            ->leftJoin('bestelleingang as be', 'b.eingang_id', '=', 'be.eingang_id')
            ->leftJoin('nachunternehmer as n', 'b.nu_id', '=', 'n.nu_id')
            ->select(
                'b.*',
                'k.kundenname', 'k.bereich as kunden_bereich',
                'o.objektname',
                'a.artikel_name', 'a.bereich as artikel_bereich',
                // Aliases kept for backward compat with orders.js (estimated revenue calc)
                DB::raw('COALESCE(be.kundenpreis, a.preis_kunde) as obj_preis_kunde'),
                DB::raw('COALESCE(be.nu_preis, a.nu_preis) as obj_nu_preis'),
                DB::raw('COALESCE(be.modell, a.modell) as abrechnungsmodell'),
                'n.firmenname as nu_name'
            );

        if ($request->filled('kunden_id')) $query->where('b.kunden_id', $request->kunden_id);
        if ($request->filled('obj_id')) $query->where('b.obj_id', $request->obj_id);
        if ($request->filled('monat') && $request->filled('jahr')) {
            $query->where('b.monat', $request->monat)->where('b.jahr', $request->jahr);
        }
        if ($request->filled('status')) $query->where('b.status', $request->status);
        if ($request->has('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('b.bestell_id', 'like', $s)
                  ->orWhere('k.kundenname', 'like', $s)
                  ->orWhere('o.objektname', 'like', $s);
            });
        }

        // Per-user Bereich restriction (article Bereich, customer fallback).
        BereichScope::applyBuilder($request->user(), $query,
            "COALESCE(NULLIF(TRIM(a.bereich), ''), NULLIF(TRIM(k.bereich), ''))");

        $orders = $query->orderByDesc('b.id')->get();

        // Aggregate Einsatz counts in a single GROUP BY query (fixes N+1)
        $einsatzCounts = [];
        if ($orders->isNotEmpty()) {
            $bestellIds = $orders->pluck('bestell_id')->all();
            $counts = DB::connection('tenant')->table('einsaetze')
                ->whereIn('bestell_id', $bestellIds)
                ->selectRaw("
                    bestell_id,
                    COUNT(*) as total,
                    SUM(CASE WHEN hms_bestaetigt = 'ja' THEN 1 ELSE 0 END) as bestaetigt,
                    SUM(CASE WHEN hms_bestaetigt != 'ja' AND hms_bestaetigt != 'nein' AND pruefung = 'geplant' THEN 1 ELSE 0 END) as geplant,
                    SUM(CASE WHEN hms_bestaetigt = 'ausstehend' AND (pruefung IS NULL OR pruefung != 'geplant') THEN 1 ELSE 0 END) as ausstehend,
                    SUM(CASE WHEN hms_bestaetigt = 'nein' THEN 1 ELSE 0 END) as abgelehnt
                ")
                ->groupBy('bestell_id')
                ->get();
            foreach ($counts as $c) {
                $einsatzCounts[$c->bestell_id] = $c;
            }
        }

        // Planned hours per order = Σ(soll_std × menge) across the Bestelleingang
        // day rows (the source of truth). bestellungen.soll_std_betrag is usually
        // NULL for wizard-created orders, which made the Bestellungen KPIs/Analytics
        // (Σ Soll-Std, Umsatz, Top-Listen) render 0. Aggregate it here at read time
        // so existing orders are fixed without needing a re-save.
        $sollByEingang = [];
        if ($orders->isNotEmpty()) {
            $eingangIds = $orders->pluck('eingang_id')->filter()->unique()->values()->all();
            if (!empty($eingangIds)) {
                $sums = DB::connection('tenant')->table('bestelleingang_tage')
                    ->whereIn('eingang_id', $eingangIds)
                    ->selectRaw('eingang_id, SUM(COALESCE(soll_std, 0) * COALESCE(menge, 1)) as total_soll')
                    ->groupBy('eingang_id')
                    ->get();
                foreach ($sums as $s) {
                    $sollByEingang[$s->eingang_id] = (float) $s->total_soll;
                }
            }
        }

        $today = date('Y-m-d');
        foreach ($orders as &$order) {
            $c = $einsatzCounts[$order->bestell_id] ?? null;
            $order->einsatz_total       = (int)($c->total ?? 0);
            $order->einsatz_bestaetigt  = (int)($c->bestaetigt ?? 0);
            $order->einsatz_geplant     = (int)($c->geplant ?? 0);
            $order->einsatz_ausstehend  = (int)($c->ausstehend ?? 0);
            $order->einsatz_abgelehnt   = (int)($c->abgelehnt ?? 0);
            // Backward-compatible aliases for old field names
            $order->einsaetze_total = $order->einsatz_total;
            $order->einsaetze_done  = $order->einsatz_bestaetigt;
            // Planned hours: prefer computed Σ(soll_std × menge) from the day rows;
            // fall back to the stored column for orders without day rows (legacy/AI).
            $computedSoll = $sollByEingang[$order->eingang_id ?? ''] ?? 0;
            if ($computedSoll > 0) {
                $order->soll_std_betrag = round($computedSoll, 2);
            }
            // Overdue flag: open order with datum_bis in the past
            $order->ueberfaellig = ($order->status === 'offen'
                && !empty($order->datum_bis)
                && $order->datum_bis < $today);
        }

        return response()->json($orders);
    }

    public function show(string $id)
    {
        $order = DB::connection('tenant')
            ->table('bestellungen as b')
            ->leftJoin('kunden as k', 'b.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'b.obj_id', '=', 'o.obj_id')
            ->leftJoin('artikel as a', 'b.artikel_id', '=', 'a.artikel_id')
            ->leftJoin('bestelleingang as be', 'b.eingang_id', '=', 'be.eingang_id')
            ->leftJoin('nachunternehmer as n', 'b.nu_id', '=', 'n.nu_id')
            ->select('b.*', 'k.kundenname', 'k.bereich as kunden_bereich',
                     'o.objektname', 'a.bereich as artikel_bereich', 'n.firmenname as nu_name',
                     DB::raw('COALESCE(be.kundenpreis, a.preis_kunde) as preis_kunde'),
                     DB::raw('COALESCE(be.nu_preis, a.nu_preis) as nu_preis'),
                     DB::raw('COALESCE(be.modell, a.modell) as abrechnungsmodell'))
            ->where('b.bestell_id', $id)->first();

        if (!$order) return response()->json(['error' => 'Bestellung nicht gefunden'], 404);

        // Enforce the per-user Bereich restriction on direct access.
        $orderBereich = trim((string) ($order->artikel_bereich ?? '')) !== ''
            ? $order->artikel_bereich
            : ($order->kunden_bereich ?? null);
        if (!BereichScope::userAllows(auth()->user(), $orderBereich)) {
            return response()->json(['error' => 'Bestellung nicht gefunden'], 404);
        }

        // Progress stats — exclusive buckets, same logic as the list endpoint:
        // geplant/ausstehend must not count einsaetze already bestätigt, and a
        // geplant einsatz must not also count as ausstehend (its hms_bestaetigt
        // stays 'ausstehend' until confirmed).
        $progress = DB::connection('tenant')->table('einsaetze')
            ->where('bestell_id', $id)
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN hms_bestaetigt = 'ausstehend' AND (pruefung IS NULL OR pruefung != 'geplant') THEN 1 ELSE 0 END) AS ausstehend,
                SUM(CASE WHEN hms_bestaetigt != 'ja' AND hms_bestaetigt != 'nein' AND pruefung = 'geplant' THEN 1 ELSE 0 END) AS geplant,
                SUM(CASE WHEN hms_bestaetigt = 'ja' THEN 1 ELSE 0 END) AS bestaetigt,
                SUM(CASE WHEN hms_bestaetigt = 'nein' THEN 1 ELSE 0 END) AS abgelehnt
            ")->first();
        $order->progress = $progress;

        // Confirmed worker entries (Monatsabschluss-style)
        $settlement = AbrechnungDataService::confirmedOrderAssignments($id);
        $order->confirmed_workers = $settlement['workers'];

        // NU worker entries
        $order->confirmed_nu_workers = $settlement['nu_workers'];

        return response()->json($order);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        // Bereich restriction: the order's derived Bereich (artikel → kunde)
        // must be inside the caller's whitelist.
        $allowed = BereichScope::for($request->user());
        if (is_array($allowed)) {
            $bArtikel = !empty($data['artikel_id'])
                ? DB::connection('tenant')->table('artikel')->where('artikel_id', $data['artikel_id'])->value('bereich')
                : null;
            $bKunde = !empty($data['kunden_id'])
                ? DB::connection('tenant')->table('kunden')->where('kunde_id', $data['kunden_id'])->value('bereich')
                : null;
            $derived = trim((string) $bArtikel) !== '' ? $bArtikel : $bKunde;
            if (!BereichScope::listAllows($allowed, $derived)) {
                return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
            }
        }

        $bestell_id = IdGenerator::uniqueId('bestellungen', 'bestell_id', 'B-');

        DB::connection('tenant')->table('bestellungen')->insert([
            'bestell_id' => $bestell_id,
            'eingang_id' => $data['eingang_id'] ?? null,
            'kunden_id' => $data['kunden_id'],
            'obj_id' => $data['obj_id'],
            'kunden_obj_nr' => $data['kunden_obj_nr'] ?? null,
            'monat' => $data['monat'] ?? null,
            'jahr' => $data['jahr'] ?? null,
            'datum_von' => $data['datum_von'] ?? null,
            'datum_bis' => $data['datum_bis'] ?? null,
            'zeit_von' => $data['zeit_von'] ?? null,
            'zeit_bis' => $data['zeit_bis'] ?? null,
            'menge_einheit' => $data['menge_einheit'] ?? null,
            'soll_std_betrag' => $data['soll_std_betrag'] ?? null,
            'status' => $data['status'] ?? 'offen',
            'nu_id' => $data['nu_id'] ?? null,
        ]);

        return response()->json(['success' => true, 'bestell_id' => $bestell_id], 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->all();

        if (!BereichScope::allowsBestellung($request->user(), $id)) {
            return response()->json(['error' => 'Bestellung nicht gefunden'], 404);
        }
        if (!empty($data['kunden_id'])
            && !BereichScope::allowsKunde($request->user(), (string) $data['kunden_id'])) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        if (!empty($data['artikel_id'])
            && !BereichScope::allowsArtikel($request->user(), (string) $data['artikel_id'])) {
            return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        }

        // A Bestellung created by the Bestelleingang mirrors it: fields are
        // edited there, and the lifecycle (stornieren / abschließen / wieder
        // öffnen) moves BOTH records and the Einsätze in Dispo together.
        $linkedEingang = DB::connection('tenant')->table('bestellungen as b')
            ->join('bestelleingang as be', 'be.eingang_id', '=', 'b.eingang_id')
            ->where('b.bestell_id', $id)->value('be.eingang_id');
        if ($linkedEingang) {
            if (empty($data['status_only'])) {
                return response()->json([
                    'error' => 'Diese Bestellung wird im Bestelleingang ' . $linkedEingang . ' gepflegt.',
                    'eingang_id' => $linkedEingang,
                ], 409);
            }
            $newStatus = $data['status'] ?? 'offen';
            if (!in_array($newStatus, ['offen', 'abgeschlossen', 'storniert'], true)) {
                return response()->json(['error' => 'Ungültiger Status'], 400);
            }
            $incoming = app(IncomingOrderController::class);
            DB::connection('tenant')->beginTransaction();
            try {
                DB::connection('tenant')->table('bestelleingang')->where('eingang_id', $linkedEingang)->lockForUpdate()->first();
                $res = match ($newStatus) {
                    'storniert' => $incoming->cancelOrder($linkedEingang),
                    'abgeschlossen' => $incoming->closeOrder($linkedEingang),
                    default => $incoming->reopenOrder($linkedEingang),
                };
                if (isset($res['error'])) {
                    DB::connection('tenant')->rollBack();
                    return response()->json(array_diff_key($res, ['status' => 1]), $res['status'] ?? 409);
                }
                DB::connection('tenant')->commit();
            } catch (\Throwable $e) {
                DB::connection('tenant')->rollBack();
                \Log::error('Bestellung status change failed: ' . $e->getMessage(), ['bestell_id' => $id]);
                return response()->json(['error' => 'Statusänderung fehlgeschlagen. Bitte erneut versuchen.'], 500);
            }
            $incoming->flushLifecycleNotifications();
            \App\Helpers\Realtime::changed('eingang');
            \App\Helpers\Realtime::changed('dispo');
            return response()->json(['success' => true] + $res);
        }

        // Status-only update (close / cancel)
        if (!empty($data['status_only'])) {
            $newStatus = $data['status'] ?? 'offen';
            DB::connection('tenant')->table('bestellungen')->where('bestell_id', $id)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            // Cascade to bestelleingang when closing
            if ($newStatus === 'abgeschlossen') {
                $order = DB::connection('tenant')->table('bestellungen')->where('bestell_id', $id)->first();
                if ($order && $order->eingang_id) {
                    DB::connection('tenant')->table('bestelleingang')
                        ->where('eingang_id', $order->eingang_id)
                        ->update(['status' => 'abgeschlossen', 'updated_at' => now()]);
                }
            }

            return response()->json(['success' => true]);
        }

        DB::connection('tenant')->table('bestellungen')->where('bestell_id', $id)->update([
            'kunden_id' => $data['kunden_id'],
            'obj_id' => $data['obj_id'],
            'kunden_obj_nr' => $data['kunden_obj_nr'] ?? null,
            'monat' => $data['monat'] ?? null,
            'jahr' => $data['jahr'] ?? null,
            'datum_von' => $data['datum_von'] ?? null,
            'datum_bis' => $data['datum_bis'] ?? null,
            'zeit_von' => $data['zeit_von'] ?? null,
            'zeit_bis' => $data['zeit_bis'] ?? null,
            'menge_einheit' => $data['menge_einheit'] ?? null,
            'soll_std_betrag' => $data['soll_std_betrag'] ?? null,
            'status' => $data['status'] ?? 'offen',
            'nu_id' => $data['nu_id'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(string $id)
    {
        if (!BereichScope::allowsBestellung(request()->user(), $id)) {
            return response()->json(['error' => 'Bestellung nicht gefunden'], 404);
        }
        // A Bestellung that still has its Bestelleingang order would be
        // re-created by the next order save — delete it there instead (that
        // path keeps confirmed history and checks booked shifts).
        $eingangId = DB::connection('tenant')->table('bestellungen as b')
            ->join('bestelleingang as be', 'be.eingang_id', '=', 'b.eingang_id')
            ->where('b.bestell_id', $id)->value('be.eingang_id');
        if ($eingangId) {
            return response()->json([
                'error' => 'Diese Bestellung gehört zum Bestelleingang ' . $eingangId . ' und wird dort gelöscht.',
                'eingang_id' => $eingangId,
            ], 409);
        }
        // Without an order: never wipe booked (planned+staffed / confirmed) shifts.
        $blocked = array_column(\App\Support\StaffedShifts::bookedForBestellungen([$id]), 'label');
        if ($blocked) {
            return response()->json([
                'error' => 'Die Bestellung hat eingeplante bzw. bestätigte Einsätze und kann nicht gelöscht werden: ' . implode('; ', $blocked),
                'blocked_shifts' => $blocked,
            ], 409);
        }
        DB::connection('tenant')->beginTransaction();
        try {
            $einsatzIds = DB::connection('tenant')->table('einsaetze')
                ->where('bestell_id', $id)->pluck('einsatz_id')->toArray();

            if (!empty($einsatzIds)) {
                $positionIds = DB::connection('tenant')->table('nu_schicht_positionen')
                    ->whereIn('einsatz_id', $einsatzIds)->pluck('id')->toArray();
                if (!empty($positionIds)) {
                    DB::connection('tenant')->table('nu_position_zuweisungen')
                        ->whereIn('position_id', $positionIds)->delete();
                }
                DB::connection('tenant')->table('nu_schicht_positionen')
                    ->whereIn('einsatz_id', $einsatzIds)->delete();
            }

            // Cascade-delete saved PDFs (DB rows + physical files + share links)
            $deletedPdfs = $this->cascadeDeletePdfs($id);

            DB::connection('tenant')->table('einsaetze')->where('bestell_id', $id)->delete();
            DB::connection('tenant')->table('bestellungen')->where('bestell_id', $id)->delete();
            DB::connection('tenant')->commit();
            return response()->json([
                'success' => true,
                'deleted_pdfs' => $deletedPdfs,
            ]);
        } catch (\Throwable $e) {
            DB::connection('tenant')->rollBack();
            \Log::error('Bestellung delete failed: ' . $e->getMessage(), ['bestell_id' => $id]);
            return response()->json(['error' => 'Löschen fehlgeschlagen. Bitte erneut versuchen.'], 500);
        }
    }

    /**
     * Delete all saved PDFs (physical files + DB rows + share links) for a Bestellung.
     * Safe no-op if the tables don't yet exist (pre-PDF-system tenants).
     */
    protected function cascadeDeletePdfs(string $bestellId): int
    {
        if (!Schema::connection('tenant')->hasTable('bestellung_pdfs')) {
            return 0;
        }

        $pdfs = DB::connection('tenant')->table('bestellung_pdfs')
            ->where('bestell_id', $bestellId)->get();
        if ($pdfs->isEmpty()) return 0;

        $pdfIds = $pdfs->pluck('id')->toArray();

        foreach ($pdfs as $pdf) {
            if ($pdf->file_path && Storage::disk('public')->exists($pdf->file_path)) {
                try { Storage::disk('public')->delete($pdf->file_path); } catch (\Throwable $e) {}
            }
        }

        if (Schema::connection('tenant')->hasTable('pdf_share_links')) {
            DB::connection('tenant')->table('pdf_share_links')
                ->whereIn('bestellung_pdf_id', $pdfIds)->delete();
        }
        DB::connection('tenant')->table('bestellung_pdfs')
            ->where('bestell_id', $bestellId)->delete();

        return count($pdfIds);
    }
}
