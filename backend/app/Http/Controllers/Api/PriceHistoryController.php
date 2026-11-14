<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preishistorie — full audit log of every price change for every artikel.
 *
 * Auto-populated from ArtikelController on create/update.
 * Manual entries possible via store() (with reason).
 *
 * Endpoints:
 *  GET  /price-history              -> filterable list
 *  GET  /price-history/stats        -> aggregate KPIs (same filters as the list)
 *  GET  /price-history/article/{id} -> full history for one artikel + current price
 *  POST /price-history              -> manual log entry (no side-effects on artikel/objekte)
 */
class PriceHistoryController extends Controller
{
    /**
     * Base query shared by index() and stats(): joins, Bereich scope and all
     * filters (from, to, kunden_id, obj_id, artikel_id, aenderung_typ,
     * change_type, search). Returns [query, hasArtikelJoin]. No select().
     */
    private function filteredQuery(Request $request): array
    {
        $hasArtikel    = Schema::connection('tenant')->hasTable('artikel');
        $hasArtikelCol = Schema::connection('tenant')->hasColumn('preishistorie', 'artikel_id');
        $hasTypCol     = Schema::connection('tenant')->hasColumn('preishistorie', 'aenderung_typ');
        $withArtikel   = $hasArtikel && $hasArtikelCol;

        $query = DB::connection('tenant')
            ->table('preishistorie as p')
            ->leftJoin('objekte as o', 'p.obj_id', '=', 'o.obj_id')
            ->leftJoin('kunden as k', 'p.kunden_id', '=', 'k.kunde_id');

        // Bereich scope: derived from the artikel, fallback to the customer.
        if ($withArtikel) {
            $query->leftJoin('artikel as a', 'p.artikel_id', '=', 'a.artikel_id');
            $scopeExpr = BereichScope::derivedExpr('a', 'k');
        } else {
            // No artikel join available — fall back to the row's customer.
            $scopeExpr = "NULLIF(TRIM(k.bereich), '')";
        }
        BereichScope::applyBuilder($request->user(), $query, $scopeExpr);

        // Explicit Bereich filter (CSV export / UI): comma list of names,
        // matched against the same derived Bereich as the scope (comma-aware).
        // Runs on top of the user's scope, so it can only narrow.
        if ($request->filled('bereich')) {
            $wanted = array_values(array_filter(array_map(
                fn ($v) => mb_strtolower(trim($v)),
                explode(',', (string) $request->bereich)
            ), fn ($v) => $v !== ''));
            if ($wanted) {
                [$bSql, $bParams] = BereichScope::matchBereichExpr($wanted, $scopeExpr);
                $query->whereRaw($bSql, $bParams);
            }
        }

        // ---- Filters ----
        if ($request->filled('from'))       $query->where('p.aenderungsdatum', '>=', $request->from);
        if ($request->filled('to'))         $query->where('p.aenderungsdatum', '<=', $request->to);
        if ($request->filled('kunden_id'))  $query->where('p.kunden_id',  $request->kunden_id);
        if ($request->filled('obj_id'))     $query->where('p.obj_id',     $request->obj_id);
        if ($request->filled('artikel_id') && $hasArtikelCol) {
            $query->where('p.artikel_id', $request->artikel_id);
        }
        if ($request->filled('aenderung_typ') && $hasTypCol) {
            $query->where('p.aenderung_typ', $request->aenderung_typ);
        }
        if ($request->filled('change_type')) {
            switch ($request->change_type) {
                case 'kunde':
                    $query->whereNotNull('p.neuer_preis_kunde')
                          ->where(function ($q) {
                              $q->whereNull('p.alter_preis_kunde')
                                ->orWhereColumn('p.alter_preis_kunde', '<>', 'p.neuer_preis_kunde');
                          });
                    break;
                case 'nu':
                    $query->whereNotNull('p.neuer_nu_preis')
                          ->where(function ($q) {
                              $q->whereNull('p.alter_nu_preis')
                                ->orWhereColumn('p.alter_nu_preis', '<>', 'p.neuer_nu_preis');
                          });
                    break;
                case 'increase':
                    $query->whereColumn('p.neuer_preis_kunde', '>', 'p.alter_preis_kunde');
                    break;
                case 'decrease':
                    $query->whereColumn('p.neuer_preis_kunde', '<', 'p.alter_preis_kunde');
                    break;
            }
        }
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s, $withArtikel) {
                $q->where('p.grund', 'like', $s)
                  ->orWhere('p.objektname', 'like', $s)
                  ->orWhere('o.objektname', 'like', $s)
                  ->orWhere('k.kundenname', 'like', $s)
                  ->orWhere('p.obj_id', 'like', $s)
                  ->orWhere('p.kunden_id', 'like', $s);
                if ($withArtikel) {
                    $q->orWhere('a.artikel_name', 'like', $s)
                      ->orWhere('p.artikel_id', 'like', $s);
                }
            });
        }

        return [$query, $withArtikel];
    }

    /**
     * Filterable list of price changes.
     * Filters: see filteredQuery(); plus limit (1..5000, default 500).
     */
    public function index(Request $request)
    {
        [$query, $withArtikel] = $this->filteredQuery($request);

        $select = [
            'p.*',
            'o.objektname as o_objektname',
            'o.adresse as o_adresse',
            'o.stadt as o_stadt',
            'k.kundenname as k_kundenname',
        ];
        if ($withArtikel) {
            $select[] = 'a.artikel_name as a_artikel_name';
            $select[] = 'a.bereich as a_bereich';
            $select[] = 'a.modell as a_modell';
            $select[] = 'a.preis_kunde as a_current_preis_kunde';
            $select[] = 'a.nu_preis as a_current_nu_preis';
            $select[] = 'a.aktiv as a_aktiv';
        }
        // Author (user) join — central DB so cross-DB join may not be possible;
        // keep id only and resolve client-side if needed.
        $query->select($select);

        $limit = min(max((int) $request->input('limit', 500), 1), 5000);

        $rows = $query->orderByDesc('p.aenderungsdatum')
                      ->orderByDesc('p.id')
                      ->limit($limit)
                      ->get();

        // Enrich each row with computed deltas
        $rows->transform(function ($r) {
            $r->kundenname = $r->k_kundenname;
            $r->objektname_resolved = $r->o_objektname ?: $r->objektname;
            $r->artikel_name = $r->a_artikel_name ?? null;
            $r->bereich = $r->a_bereich ?? null;
            $r->modell  = $r->a_modell ?? null;
            $r->current_preis_kunde = $r->a_current_preis_kunde ?? null;
            $r->current_nu_preis    = $r->a_current_nu_preis ?? null;
            $r->artikel_aktiv = $r->a_aktiv ?? null;

            $r->delta_kunde     = $this->deltaAbs($r->alter_preis_kunde, $r->neuer_preis_kunde);
            $r->delta_kunde_pct = $this->deltaPct($r->alter_preis_kunde, $r->neuer_preis_kunde);
            $r->delta_nu        = $this->deltaAbs($r->alter_nu_preis, $r->neuer_nu_preis);
            $r->delta_nu_pct    = $this->deltaPct($r->alter_nu_preis, $r->neuer_nu_preis);

            $oldGewinn = $this->safeFloat($r->alter_preis_kunde) - $this->safeFloat($r->alter_nu_preis);
            $newGewinn = $this->safeFloat($r->neuer_preis_kunde) - $this->safeFloat($r->neuer_nu_preis);
            $r->alter_gewinn = ($r->alter_preis_kunde !== null || $r->alter_nu_preis !== null) ? $oldGewinn : null;
            $r->neuer_gewinn = ($r->neuer_preis_kunde !== null || $r->neuer_nu_preis !== null) ? $newGewinn : null;
            $r->delta_gewinn = ($r->alter_gewinn !== null && $r->neuer_gewinn !== null)
                ? round($newGewinn - $oldGewinn, 2) : null;

            // Strip raw join aliases
            unset($r->o_objektname, $r->o_adresse, $r->o_stadt, $r->k_kundenname,
                  $r->a_artikel_name, $r->a_bereich, $r->a_modell,
                  $r->a_current_preis_kunde, $r->a_current_nu_preis, $r->a_aktiv);
            return $r;
        });

        return response()->json($rows);
    }

    /**
     * Aggregate KPIs — computed over exactly the same filtered set as
     * index() (period + Kunde/Objekt/Artikel/Typ/Suche), so the cards always
     * describe what the list shows.
     */
    public function stats(Request $request)
    {
        $now      = now();
        $thisYear = $now->copy()->startOfYear()->toDateString();
        $thisMonth = $now->copy()->startOfMonth()->toDateString();
        $thirtyDaysAgo = $now->copy()->subDays(30)->toDateString();

        [$base, $withArtikel] = $this->filteredQuery($request);

        $total      = (clone $base)->count();
        $thisYearCount  = (clone $base)->where('p.aenderungsdatum', '>=', $thisYear)->count();
        $thisMonthCount = (clone $base)->where('p.aenderungsdatum', '>=', $thisMonth)->count();
        $last30Count    = (clone $base)->where('p.aenderungsdatum', '>=', $thirtyDaysAgo)->count();
        $increases      = (clone $base)->whereColumn('p.neuer_preis_kunde', '>', 'p.alter_preis_kunde')->count();
        $decreases      = (clone $base)->whereColumn('p.neuer_preis_kunde', '<', 'p.alter_preis_kunde')->count();

        $kundenAffected = (clone $base)->whereNotNull('p.kunden_id')->distinct()->count('p.kunden_id');
        $objekteAffected = (clone $base)->whereNotNull('p.obj_id')->distinct()->count('p.obj_id');

        $hasArtikelCol = Schema::connection('tenant')->hasColumn('preishistorie', 'artikel_id');
        $artikelAffected = $hasArtikelCol
            ? (clone $base)->whereNotNull('p.artikel_id')->distinct()->count('p.artikel_id')
            : 0;

        // Average delta % (Kundenpreis only, ignoring zero-old / null)
        $withPct = (clone $base)
            ->whereNotNull('p.alter_preis_kunde')
            ->whereNotNull('p.neuer_preis_kunde')
            ->where('p.alter_preis_kunde', '>', 0);
        $avgDeltaPct = (clone $withPct)
            ->selectRaw('AVG(((p.neuer_preis_kunde - p.alter_preis_kunde) / p.alter_preis_kunde) * 100) AS avg_pct')
            ->value('avg_pct');

        // Biggest increase + biggest decrease (artikel_id included when the
        // column exists so the KPI cards can deep-link into the drill-down)
        $artikelSel = $hasArtikelCol ? ', p.artikel_id' : '';
        $artikelName = $withArtikel ? ', a.artikel_name' : '';
        $pctSel = "p.id, p.obj_id, p.kunden_id, COALESCE(o.objektname, p.objektname) AS objektname, k.kundenname,
                   p.aenderungsdatum, p.alter_preis_kunde, p.neuer_preis_kunde{$artikelSel}{$artikelName},
                   ROUND(((p.neuer_preis_kunde - p.alter_preis_kunde) / p.alter_preis_kunde) * 100, 2) AS pct";
        $biggestIncrease = (clone $withPct)
            ->whereColumn('p.neuer_preis_kunde', '>', 'p.alter_preis_kunde')
            ->selectRaw($pctSel)->orderByDesc('pct')->first();
        $biggestDecrease = (clone $withPct)
            ->whereColumn('p.neuer_preis_kunde', '<', 'p.alter_preis_kunde')
            ->selectRaw($pctSel)->orderBy('pct', 'asc')->first();

        $latestChange = (clone $base)->select('p.*')
            ->orderByDesc('p.aenderungsdatum')->orderByDesc('p.id')->first();

        return response()->json([
            'total'             => $total,
            'this_year'         => $thisYearCount,
            'this_month'        => $thisMonthCount,
            'last_30_days'      => $last30Count,
            'increases'         => $increases,
            'decreases'         => $decreases,
            'kunden_affected'   => $kundenAffected,
            'objekte_affected'  => $objekteAffected,
            'artikel_affected'  => $artikelAffected,
            'avg_delta_pct'     => $avgDeltaPct !== null ? round((float) $avgDeltaPct, 2) : null,
            'biggest_increase'  => $biggestIncrease,
            'biggest_decrease'  => $biggestDecrease,
            'latest_change'     => $latestChange,
        ]);
    }

    /**
     * Full price history of a single artikel + current state.
     */
    public function articleHistory(Request $request, string $artikel_id)
    {
        if (!Schema::connection('tenant')->hasTable('artikel')) {
            return response()->json(['error' => 'Artikel-Tabelle nicht verfügbar'], 404);
        }

        $artikel = DB::connection('tenant')
            ->table('artikel as a')
            ->leftJoin('objekte as o', 'a.obj_id', '=', 'o.obj_id')
            ->leftJoin('kunden as k', 'o.kunden_id', '=', 'k.kunde_id')
            ->select(
                'a.*',
                'o.objektname',
                'o.kunden_id',
                'k.kundenname'
            )
            ->where('a.artikel_id', $artikel_id)
            ->first();

        if (!$artikel || !BereichScope::allowsArtikel($request->user(), $artikel_id)) {
            return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        }

        $history = DB::connection('tenant')
            ->table('preishistorie')
            ->where('artikel_id', $artikel_id)
            ->orderBy('aenderungsdatum')
            ->orderBy('id')
            ->get();

        // Enrich with deltas
        $history->transform(function ($r) {
            $r->delta_kunde     = $this->deltaAbs($r->alter_preis_kunde, $r->neuer_preis_kunde);
            $r->delta_kunde_pct = $this->deltaPct($r->alter_preis_kunde, $r->neuer_preis_kunde);
            $r->delta_nu        = $this->deltaAbs($r->alter_nu_preis, $r->neuer_nu_preis);
            $r->delta_nu_pct    = $this->deltaPct($r->alter_nu_preis, $r->neuer_nu_preis);
            return $r;
        });

        return response()->json([
            'artikel' => $artikel,
            'history' => $history,
            'total_changes' => $history->count(),
        ]);
    }

    /**
     * Manual price history entry — pure log, no side effects on artikel/objekte.
     * Editing prices should be done via ArtikelController which auto-logs.
     */
    public function store(Request $request)
    {
        $data = $request->all();

        if (empty($data['obj_id']) || empty($data['aenderungsdatum'])) {
            return response()->json(['error' => 'obj_id und aenderungsdatum sind erforderlich'], 400);
        }

        // Bereich scope: the target objekt/artikel/kunde must be in-scope.
        $user = $request->user();
        if (!BereichScope::allowsObjekt($user, (string) $data['obj_id'])
            || (!empty($data['artikel_id']) && !BereichScope::allowsArtikel($user, (string) $data['artikel_id']))
            || (!empty($data['kunden_id']) && !BereichScope::allowsKunde($user, (string) $data['kunden_id']))) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }

        $hasArtikelCol = Schema::connection('tenant')->hasColumn('preishistorie', 'artikel_id');
        $hasTypCol     = Schema::connection('tenant')->hasColumn('preishistorie', 'aenderung_typ');
        $hasUserCol    = Schema::connection('tenant')->hasColumn('preishistorie', 'geaendert_von');

        $row = [
            'aenderungsdatum'   => $data['aenderungsdatum'],
            'obj_id'            => $data['obj_id'],
            'objektname'        => $data['objektname'] ?? null,
            'kunden_id'         => $data['kunden_id'] ?? null,
            'alter_preis_kunde' => $this->nullableFloat($data['alter_preis_kunde'] ?? null),
            'neuer_preis_kunde' => $this->nullableFloat($data['neuer_preis_kunde'] ?? null),
            'alter_nu_preis'    => $this->nullableFloat($data['alter_nu_preis'] ?? null),
            'neuer_nu_preis'    => $this->nullableFloat($data['neuer_nu_preis'] ?? null),
            'grund'             => $data['grund'] ?? null,
        ];

        if ($hasArtikelCol && !empty($data['artikel_id'])) {
            $row['artikel_id'] = $data['artikel_id'];
        }
        if ($hasTypCol) {
            $row['aenderung_typ'] = 'manual';
        }
        if ($hasUserCol && $request->user()) {
            $row['geaendert_von'] = $request->user()->id;
        }

        $id = DB::connection('tenant')->table('preishistorie')->insertGetId($row);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    // ---- helpers ----

    private function safeFloat($v): float
    {
        return $v !== null && $v !== '' ? (float) $v : 0.0;
    }

    private function nullableFloat($v): ?float
    {
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (float) $v : null;
    }

    private function deltaAbs($old, $new): ?float
    {
        if ($old === null || $new === null) return null;
        return round((float) $new - (float) $old, 2);
    }

    private function deltaPct($old, $new): ?float
    {
        if ($old === null || $new === null) return null;
        $o = (float) $old;
        if ($o == 0.0) return null;
        return round((((float) $new - $o) / $o) * 100, 2);
    }
}
