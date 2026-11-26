<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Helpers\SchichtartCalculator;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages physical Objects (locations) belonging to customers.
 * Service-specific data (Bereich, Modell, Pricing) lives on the
 * `artikel` table — see ArtikelController.
 *
 * Legacy columns on `objekte` (bereich, modell, preis_kunde, nu_preis, gewinn)
 * are preserved for backward compatibility but no longer authoritative;
 * they reflect the "primary" article for the object.
 */
class ObjectController extends Controller
{
    /**
     * GET /api/objects/contacts?q=Sophie&kunden_id=K001
     *
     * Autocomplete for "Kontakt vor Ort": distinct Ansprechpartner already
     * stored on other Objekte (name + phone + e-mail), so the user can pick a
     * known person and have Telefon/E-Mail filled in automatically.
     * Contacts of the same customer are listed first.
     */
    public function contacts(Request $request)
    {
        if (!Schema::connection('tenant')->hasColumn('objekte', 'ansprechpartner')) {
            return response()->json([]);
        }

        $q = trim((string) $request->input('q', ''));
        $kundenId = trim((string) $request->input('kunden_id', ''));
        $limit = min(20, max(1, (int) $request->input('limit', 8)));

        $rows = DB::connection('tenant')->table('objekte as o')
            ->leftJoin('kunden as k', 'o.kunden_id', '=', 'k.kunde_id')
            ->selectRaw("
                TRIM(o.ansprechpartner)                                   AS name,
                MAX(NULLIF(TRIM(o.kontakt_telefon), ''))                  AS telefon,
                MAX(NULLIF(TRIM(o.kontakt_email), ''))                    AS email,
                COUNT(*)                                                  AS objekte,
                MAX(CASE WHEN o.kunden_id = ? THEN 1 ELSE 0 END)          AS same_kunde,
                GROUP_CONCAT(DISTINCT o.objektname ORDER BY o.objektname SEPARATOR ', ') AS objektnamen,
                GROUP_CONCAT(DISTINCT k.kundenname SEPARATOR ', ')        AS kundennamen
            ", [$kundenId])
            ->whereRaw("TRIM(COALESCE(o.ansprechpartner, '')) <> ''")
            ->whereRaw(... BereichScope::objektPredicate($request->user(), 'o'))
            ->when($q !== '', function ($qb) use ($q) {
                $like = '%'.$q.'%';
                $qb->where(function ($w) use ($like) {
                    $w->where('o.ansprechpartner', 'like', $like)
                      ->orWhere('o.kontakt_telefon', 'like', $like)
                      ->orWhere('o.kontakt_email', 'like', $like);
                });
            })
            ->groupBy('name')
            ->orderByDesc('same_kunde')
            ->orderByDesc('objekte')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json($rows->map(fn ($r) => [
            'name' => $r->name,
            'telefon' => $r->telefon,
            'email' => $r->email,
            'objekte' => (int) $r->objekte,
            'same_kunde' => (bool) $r->same_kunde,
            'objektnamen' => $r->objektnamen,
            'kundennamen' => $r->kundennamen,
        ]));
    }

    /**
     * GET /api/objects/arten?q=Bau
     *
     * Autocomplete for the free-text "Objektart" field: distinct values
     * already typed on this tenant's Objekte, most-used first. Runs on the
     * tenant connection, so every company only ever sees its own vocabulary.
     */
    public function arten(Request $request)
    {
        if (!Schema::connection('tenant')->hasColumn('objekte', 'art')) {
            return response()->json([]);
        }

        // ?subs_for_kunde=K011 — flat Unterbereich list for the Objektart
        // picker: strictly catalog subs under the CUSTOMER's mains
        // (intersected with the caller's whitelist). Legacy art values are
        // NOT merged here — they would leak subs of unrelated mains into
        // the picker. An untagged customer falls back to the caller's scope.
        if ($request->filled('subs_for_kunde')) {
            $kunde = DB::connection('tenant')->table('kunden')
                ->where('kunde_id', $request->input('subs_for_kunde'))
                ->first(['bereich', 'bereiche']);
            $mains = [];
            if ($kunde) {
                $mains = array_merge(
                    $kunde->bereich ? explode(',', (string) $kunde->bereich) : [],
                    json_decode($kunde->bereiche ?? '[]', true) ?: []
                );
                $mains = array_values(array_filter(array_map('trim', $mains)));
            }
            $allowed = BereichScope::for($request->user());
            if ($mains && is_array($allowed)) {
                $mains = array_values(array_filter($mains,
                    fn ($m) => BereichScope::listAllows($allowed, $m)));
            }
            $mainsLower = array_map('mb_strtolower', $mains);
            // ?with_parents=1 — [{name,parent}] so callers can DERIVE the
            // Hauptbereich from a chosen sub (its parent IS the main).
            $withParents = $request->boolean('with_parents');
            $out = [];
            $seen = [];
            if (Schema::connection('tenant')->hasTable('bereiche_katalog')) {
                $subs = DB::connection('tenant')->table('bereiche_katalog as s')
                    ->join('bereiche_katalog as p', 'p.id', '=', 's.parent_id')
                    ->where('s.aktiv', 1)->orderBy('s.sortierung')->orderBy('s.name')
                    ->get(['s.name', 'p.name as parent']);
                foreach ($subs as $s) {
                    $inMains = !$mains || in_array(mb_strtolower($s->parent), $mainsLower, true);
                    $inScope = $allowed === null || BereichScope::listAllows($allowed, $s->parent);
                    $name = trim((string) $s->name);
                    if ($inMains && $inScope && $name !== '' && !isset($seen[$name])) {
                        $seen[$name] = true;
                        $out[] = $withParents
                            ? ['name' => $name, 'parent' => trim((string) $s->parent)]
                            : $name;
                    }
                }
            }
            return response()->json($out);
        }

        $q = trim((string) $request->input('q', ''));
        $kundenId = trim((string) $request->input('kunden_id', ''));
        $limit = min(20, max(1, (int) $request->input('limit', 8)));

        // Strictly per customer: with a kunden_id only that customer's own
        // Objektarten are offered (a Veranstaltungsstätte of customer A must
        // not pop up for customer B). Without a customer → whole company.
        $rows = DB::connection('tenant')->table('objekte')
            ->selectRaw('TRIM(art) AS art, COUNT(*) AS anzahl,
                         SUM(CASE WHEN kunden_id = ? THEN 1 ELSE 0 END) AS anzahl_kunde', [$kundenId])
            ->whereRaw("TRIM(COALESCE(art, '')) <> ''")
            ->whereRaw(... BereichScope::objektPredicate($request->user(), 'objekte'))
            ->when($kundenId !== '', fn ($qb) => $qb->where('kunden_id', $kundenId))
            ->when($q !== '', fn ($qb) => $qb->where('art', 'like', '%'.$q.'%'))
            ->groupBy('art')
            ->orderByDesc('anzahl_kunde')
            ->orderByDesc('anzahl')
            ->orderBy('art')
            ->limit($limit)
            ->get();

        $out = $rows->map(fn ($r) => [
            'art' => $r->art,
            'anzahl' => (int) $r->anzahl,
            'anzahl_kunde' => (int) $r->anzahl_kunde,
            'same_kunde' => (int) $r->anzahl_kunde > 0,
        ]);

        // Managed catalog subs join the suggestion list (scope-filtered —
        // a restricted user only sees subs of his own mains). They carry
        // anzahl=0 so real usage still ranks above managed vocabulary.
        $seen = $out->map(fn ($r) => mb_strtolower($r['art']))->all();
        foreach (BereichScope::subOptionsFor($request->user()) as $sub) {
            if ($q !== '' && mb_stripos($sub, $q) === false) continue;
            if (!in_array(mb_strtolower($sub), $seen, true)) {
                $out->push(['art' => $sub, 'anzahl' => 0, 'anzahl_kunde' => 0, 'same_kunde' => false, 'catalog' => true]);
                $seen[] = mb_strtolower($sub);
            }
        }

        return response()->json($out->values());
    }

    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $query = DB::connection('tenant')
            ->table('objekte as o')
            ->leftJoin('kunden as k', 'o.kunden_id', '=', 'k.kunde_id')
            ->select('o.*', 'k.kundenname');

        if ($request->has('kunden_id')) $query->where('o.kunden_id', $request->kunden_id);
        if ($request->has('aktiv'))     $query->where('o.aktiv', $request->aktiv);
        if ($request->has('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('o.objektname', 'like', $s)
                  ->orWhere('o.obj_id', 'like', $s)
                  ->orWhere('o.obj_nr_kunde', 'like', $s)
                  ->orWhere('o.obj_nr_intern', 'like', $s)
                  ->orWhere('o.adresse', 'like', $s)
                  ->orWhere('o.stadt', 'like', $s);
            });
        }

        // Per-user Bereich restriction (object tag, customer tag or an
        // in-scope artikel make the object visible).
        $query->whereRaw(... BereichScope::objektPredicate($request->user(), 'o'));

        $objekte = $query->orderBy('o.obj_id')->get();

        // Annotate with article counts + primary article summary — counts
        // follow the same Bereich restriction so a mixed object doesn't
        // leak the number of out-of-scope artikel.
        [$aSql, $aParams] = BereichScope::artikelPredicate($request->user(), 'artikel');
        $objIds = $objekte->pluck('obj_id')->toArray();
        if (!empty($objIds)) {
            $articleStats = DB::connection('tenant')->table('artikel')
                ->whereIn('obj_id', $objIds)
                ->whereRaw($aSql, $aParams)
                ->selectRaw('obj_id, COUNT(*) AS total_artikel,
                             SUM(CASE WHEN aktiv = 1 THEN 1 ELSE 0 END) AS aktive_artikel')
                ->groupBy('obj_id')
                ->get()->keyBy('obj_id');

            foreach ($objekte as $o) {
                $o->total_artikel  = (int) ($articleStats[$o->obj_id]->total_artikel ?? 0);
                $o->aktive_artikel = (int) ($articleStats[$o->obj_id]->aktive_artikel ?? 0);
            }
        }

        return response()->json($objekte);
    }

    public function show(string $id)
    {
        $obj = DB::connection('tenant')
            ->table('objekte as o')
            ->leftJoin('kunden as k', 'o.kunden_id', '=', 'k.kunde_id')
            ->select('o.*', 'k.kundenname')
            ->where('o.obj_id', $id)->first();

        if (!$obj || !BereichScope::allowsObjekt(request()->user(), $id)) {
            return response()->json(['error' => 'Objekt nicht gefunden'], 404);
        }

        // Inline articles list — frontend can render them directly; scoped
        // to the user's Bereiche so a shared object doesn't leak other
        // Bereich services.
        [$saSql, $saParams] = BereichScope::artikelPredicate(request()->user(), 'artikel');
        $obj->artikel = DB::connection('tenant')->table('artikel')
            ->where('obj_id', $id)
            ->whereRaw($saSql, $saParams)
            ->orderBy('aktiv', 'desc')
            ->orderBy('artikel_id')
            ->get();

        return response()->json($obj);
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $numericErrors = $this->validateZuschlagFields($data);
        if ($numericErrors) return response()->json(['error' => implode(', ', $numericErrors)], 400);

        $required = [
            'kunden_id'        => 'Kunde',
            'objektname'       => 'Objektname',
            'art'              => 'Objektart',
            'adresse'          => 'Adresse',
            'plz'              => 'PLZ',
            'stadt'            => 'Stadt',
            'ansprechpartner'  => 'Ansprechpartner',
            'kontakt_telefon'  => 'Telefon',
            'kontakt_email'    => 'E-Mail',
            'hinweise'         => 'Bemerkungen',
        ];
        if (Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            $required['bundesland'] = 'Bundesland';
        }
        $missing = [];
        foreach ($required as $field => $label) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $missing[] = $label;
            }
        }
        if (!empty($missing)) {
            return response()->json(['error' => 'Pflichtfelder fehlen: ' . implode(', ', $missing)], 400);
        }

        // Bereich restriction: the target customer must be visible, and a
        // submitted bereich may only come from the user's own whitelist.
        if (!BereichScope::allowsKunde($request->user(), (string) $data['kunden_id'])) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        if (!BereichScope::bereichInputAllowed($request->user(), $data['bereich'] ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        if (!BereichScope::bereicheInputAllowed($request->user(), $data['bereiche'] ?? [])) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }

        $obj_id = IdGenerator::next('objekte', 'obj_id', 'O');
        $obj_nr_intern = !empty($data['obj_nr_intern']) ? $data['obj_nr_intern'] : IdGenerator::objNrIntern();

        // Legacy fields kept nullable/zero for backward compat with old reports
        $insert = [
            'obj_id'           => $obj_id,
            'kunden_id'        => $data['kunden_id'],
            'obj_nr_kunde'     => $data['obj_nr_kunde']  ?? null,
            'obj_nr_intern'    => $obj_nr_intern,
            'objektname'       => $data['objektname'],
            'art'              => $data['art']              ?? null,
            'adresse'          => $data['adresse']          ?? null,
            'plz'              => $data['plz']              ?? null,
            'stadt'            => $data['stadt']            ?? null,
            'ansprechpartner'  => $data['ansprechpartner']  ?? null,
            'kontakt_telefon'  => $data['kontakt_telefon']  ?? null,
            'kontakt_email'    => $data['kontakt_email']    ?? null,
            'hinweise'         => $data['hinweise']         ?? null,
            // Legacy columns — left null/empty by default
            'bereich'          => $data['bereich']          ?? null,
            'modell'           => $data['modell']           ?? 'Pro Einsatz',
            'preis_kunde'      => 0,
            'nu_preis'         => 0,
            'gewinn'           => 0,
            'aktiv'            => $data['aktiv'] ?? 1,
        ];

        // Optional Zuschläge config at the Objekt level (defaults for new artikels)
        $insert += $this->extractZuschlagFields($data, fillDefaults: true);
        if (Schema::connection('tenant')->hasColumn('objekte', 'bestellbeschreibung')) {
            $insert['bestellbeschreibung'] = isset($data['bestellbeschreibung']) && trim((string) $data['bestellbeschreibung']) !== ''
                ? mb_substr((string) $data['bestellbeschreibung'], 0, 5000) : null;
        }
        if (Schema::connection('tenant')->hasColumn('objekte', 'bundesland')) {
            $insert['bundesland'] = !empty($data['bundesland']) ? strtoupper(trim((string) $data['bundesland'])) : null;
        }
        if (Schema::connection('tenant')->hasColumn('objekte', 'pause_berechnen')) {
            $insert['pause_berechnen'] = array_key_exists('pause_berechnen', $data)
                ? (((int) $data['pause_berechnen']) ? 1 : 0) : 1;
        }

        if (Schema::connection('tenant')->hasColumn('objekte', 'bereiche')) {
            $list = BereichScope::bereicheForStorage($data['bereiche'] ?? [], $insert['bereich']);
            $insert['bereiche'] = $list ? json_encode($list) : null;
            // No explicit primary tag? First selected service becomes it.
            if (trim((string) ($insert['bereich'] ?? '')) === '' && $list) {
                $insert['bereich'] = $list[0];
            }
        }

        // Objektarten (multi) — fed by catalog subs; restricted users may
        // only attach subs of their own mains.
        if (Schema::connection('tenant')->hasColumn('objekte', 'arten')
            && array_key_exists('arten', $data)) {
            if (!BereichScope::artenWriteAllowed($request->user(), $data['arten'] ?? [], [])) {
                return response()->json(['error' => 'Kein Zugriff auf diese Objektart'], 403);
            }
            $artList = array_values(array_unique(array_filter(
                array_map(fn ($v) => is_string($v) ? trim($v) : null, (array) $data['arten']))));
            $insert['arten'] = $artList ? json_encode($artList) : null;
            if (trim((string) ($insert['art'] ?? '')) === '' && $artList) {
                $insert['art'] = $artList[0];
            }
        }

        DB::connection('tenant')->table('objekte')->insert($insert);

        return response()->json(['success' => true, 'obj_id' => $obj_id], 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->all();
        $numericErrors = $this->validateZuschlagFields($data);
        if ($numericErrors) return response()->json(['error' => implode(', ', $numericErrors)], 400);

        $existing = DB::connection('tenant')->table('objekte')->where('obj_id', $id)->first();
        if (!$existing || !BereichScope::allowsObjekt($request->user(), $id)) {
            return response()->json(['error' => 'Objekt nicht gefunden'], 404);
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
        if (isset($data['kunden_id']) && $data['kunden_id'] !== $existing->kunden_id
            && !BereichScope::allowsKunde($request->user(), (string) $data['kunden_id'])) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }
        if (array_key_exists('arten', $data)
            && !BereichScope::artenWriteAllowed(
                $request->user(), $data['arten'] ?? [],
                json_decode($existing->arten ?? '[]', true) ?: [])) {
            return response()->json(['error' => 'Kein Zugriff auf diese Objektart'], 403);
        }

        $obj_nr_intern = !empty($data['obj_nr_intern']) ? $data['obj_nr_intern']
            : ($existing->obj_nr_intern ?: IdGenerator::objNrIntern());

        $update = [
            'kunden_id'        => $data['kunden_id']        ?? $existing->kunden_id,
            'obj_nr_kunde'     => $data['obj_nr_kunde']     ?? $existing->obj_nr_kunde,
            'obj_nr_intern'    => $obj_nr_intern,
            'objektname'       => $data['objektname']       ?? $existing->objektname,
            'art'              => array_key_exists('art', $data)             ? $data['art']             : $existing->art,
            'adresse'          => array_key_exists('adresse', $data)         ? $data['adresse']         : $existing->adresse,
            'plz'              => array_key_exists('plz', $data)             ? $data['plz']             : $existing->plz,
            'stadt'            => array_key_exists('stadt', $data)           ? $data['stadt']           : $existing->stadt,
            'ansprechpartner'  => array_key_exists('ansprechpartner', $data) ? $data['ansprechpartner'] : $existing->ansprechpartner,
            'kontakt_telefon'  => array_key_exists('kontakt_telefon', $data) ? $data['kontakt_telefon'] : $existing->kontakt_telefon,
            'kontakt_email'    => array_key_exists('kontakt_email', $data)   ? $data['kontakt_email']   : $existing->kontakt_email,
            'hinweise'         => array_key_exists('hinweise', $data)        ? $data['hinweise']        : $existing->hinweise,
            'bereich'          => array_key_exists('bereich', $data)         ? $data['bereich']         : $existing->bereich,
            'aktiv'            => $data['aktiv'] ?? $existing->aktiv,
        ];

        if (Schema::connection('tenant')->hasColumn('objekte', 'pause_berechnen')
            && array_key_exists('pause_berechnen', $data)) {
            $update['pause_berechnen'] = ((int) $data['pause_berechnen']) ? 1 : 0;
        }

        // Merge Zuschläge updates only for fields the caller actually sent.
        $update += $this->extractZuschlagFields($data, fillDefaults: false);
        if (Schema::connection('tenant')->hasColumn('objekte', 'bestellbeschreibung') && array_key_exists('bestellbeschreibung', $data)) {
            $update['bestellbeschreibung'] = trim((string) $data['bestellbeschreibung']) !== ''
                ? mb_substr((string) $data['bestellbeschreibung'], 0, 5000) : null;
        }
        if (Schema::connection('tenant')->hasColumn('objekte', 'bundesland') && array_key_exists('bundesland', $data)) {
            $update['bundesland'] = !empty($data['bundesland']) ? strtoupper(trim((string) $data['bundesland'])) : null;
        }

        if (Schema::connection('tenant')->hasColumn('objekte', 'bereiche')) {
            $existingList = json_decode($existing->bereiche ?? '[]', true) ?: [];
            $keep = BereichScope::outOfScopeValues($request->user(), $existingList);
            if (array_key_exists('bereiche', $data)) {
                $list = BereichScope::bereicheForStorage(
                    array_merge((array) ($data['bereiche'] ?? []), $keep),
                    $update['bereich'] ?? $existing->bereich);
                $update['bereiche'] = $list ? json_encode($list) : null;
                if (trim((string) ($update['bereich'] ?? $existing->bereich ?? '')) === '' && $list) {
                    $update['bereich'] = $list[0];
                }
            } elseif (array_key_exists('bereich', $data)) {
                $list = BereichScope::bereicheForStorage($existingList, $data['bereich']);
                $update['bereiche'] = $list ? json_encode($list) : null;
            }
        }

        // Objektarten (multi) — catalog subs; out-of-scope stored entries
        // are re-merged so a restricted save never strips them.
        if (Schema::connection('tenant')->hasColumn('objekte', 'arten')) {
            $existingArten = json_decode($existing->arten ?? '[]', true) ?: [];
            if (array_key_exists('arten', $data)) {
                $keepArten = BereichScope::outOfScopeArten($request->user(), $existingArten);
                $artList = array_values(array_unique(array_filter(array_merge(
                    array_map(fn ($v) => is_string($v) ? trim($v) : null, (array) $data['arten']),
                    $keepArten))));
                $update['arten'] = $artList ? json_encode($artList) : null;
                if (trim((string) ($update['art'] ?? '')) === '' && $artList) {
                    $update['art'] = $artList[0];
                }
            } elseif (array_key_exists('art', $data)) {
                $list = array_values(array_unique(array_filter(array_merge(
                    $existingArten, [trim((string) $data['art'])]))));
                $update['arten'] = $list ? json_encode($list) : null;
            }
        }

        DB::connection('tenant')->table('objekte')->where('obj_id', $id)->update($update);
        // Articles without own Einsatzart take their service type from the object.
        if (array_key_exists('arten', $update)) {
            \App\Support\SecurityServices::syncArtikel(
                DB::connection('tenant')->table('artikel')->where('obj_id', $id)->pluck('artikel_id')->all());
        }
        if (array_key_exists('bundesland', $update)
            && strtoupper((string) ($existing->bundesland ?? '')) !== strtoupper((string) ($update['bundesland'] ?? ''))) {
            $this->reclassifyOpenEinsaetzeForObjekt($id);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/objects/{id}/apply-zuschlaege
     *
     * Copies the Objekt-level Zuschläge configuration to every artikel
     * under this objekt. After updating the artikels we reclassify all
     * open einsaetze so the schichtart + zuschlag_pct snapshots match
     * the new rates immediately. Bestätigte einsätze are intentionally
     * left untouched to keep historic Controlling reproducible.
     *
     * Returns counts for UX feedback:
     *   { artikel_updated, einsaetze_reclassified }
     */
    public function applyZuschlaegeToArtikel(Request $request, string $id)
    {
        $obj = DB::connection('tenant')->table('objekte')->where('obj_id', $id)->first();
        if (!$obj || !BereichScope::allowsObjekt($request->user(), $id)) {
            return response()->json(['error' => 'Objekt nicht gefunden'], 404);
        }

        if (!Schema::connection('tenant')->hasColumn('artikel', 'zuschlaege_aktiv')) {
            return response()->json(['error' => 'Zuschläge-System ist auf diesem Tenant noch nicht aktiv. Bitte tenant:migrate ausführen.'], 503);
        }

        // Build the snapshot of zuschlag fields straight from the objekt row.
        $payload = [
            'zuschlaege_aktiv'             => (int) ($obj->zuschlaege_aktiv ?? 0),
            'zuschlag_nacht_pct'           => (float) ($obj->zuschlag_nacht_pct           ?? 10.00),
            'zuschlag_sonntag_pct'         => (float) ($obj->zuschlag_sonntag_pct         ?? 25.00),
            'zuschlag_feiertag_pct'        => (float) ($obj->zuschlag_feiertag_pct        ?? 50.00),
            'zuschlag_nacht_sonntag_pct'   => (float) ($obj->zuschlag_nacht_sonntag_pct   ?? 35.00),
            'zuschlag_nacht_feiertag_pct'  => (float) ($obj->zuschlag_nacht_feiertag_pct  ?? 60.00),
            'nacht_von'                    => (string) ($obj->nacht_von ?? '22:00:00'),
            'nacht_bis'                    => (string) ($obj->nacht_bis ?? '06:00:00'),
            'updated_at'                   => now(),
        ];

        $artikelIds = DB::connection('tenant')->table('artikel')
            ->where('obj_id', $id)->pluck('artikel_id')->all();

        if (empty($artikelIds)) {
            return response()->json([
                'success' => true,
                'artikel_updated' => 0,
                'einsaetze_reclassified' => 0,
            ]);
        }

        DB::connection('tenant')->table('artikel')
            ->whereIn('artikel_id', $artikelIds)
            ->update($payload);

        // Reclassify every OPEN einsatz under any of these artikels.
        $feiertage     = SchichtartCalculator::cachedFeiertage($id);
        $reclassified  = 0;
        $artikelMap    = DB::connection('tenant')->table('artikel')
            ->whereIn('artikel_id', $artikelIds)->get()->keyBy('artikel_id');

        $rows = DB::connection('tenant')->table('einsaetze')
            ->whereIn('artikel_id', $artikelIds)
            ->where('hms_bestaetigt', '!=', 'ja')
            ->select('einsatz_id', 'datum', 'ist_start', 'ist_ende', 'artikel_id')
            ->get();

        foreach ($rows as $r) {
            $artikel = $artikelMap[$r->artikel_id] ?? null;
            if (!$artikel) continue;
            $cls = SchichtartCalculator::compute(
                is_string($r->datum) ? substr($r->datum, 0, 10) : (string) $r->datum,
                $r->ist_start,
                $r->ist_ende,
                $artikel,
                $feiertage
            );
            DB::connection('tenant')->table('einsaetze')
                ->where('einsatz_id', $r->einsatz_id)
                ->update([
                    'schichtart'   => $cls['schichtart'],
                    'zuschlag_pct' => $cls['zuschlag_pct'],
                ]);
            $reclassified++;
        }

        return response()->json([
            'success' => true,
            'artikel_updated' => count($artikelIds),
            'einsaetze_reclassified' => $reclassified,
        ]);
    }

    /**
     * Pull the optional Zuschläge fields out of a request payload.
     * Mirrors ArtikelController::extractZuschlagFields() so semantics
     * stay consistent at both levels.
     *
     * @param bool $fillDefaults true on store(), false on update()
     */
    private function reclassifyOpenEinsaetzeForObjekt(string $objektId): int
    {
        $artikel = DB::connection('tenant')->table('artikel')->where('obj_id', $objektId)->get()->keyBy('artikel_id');
        if ($artikel->isEmpty()) return 0;
        $holidays = SchichtartCalculator::cachedFeiertage($objektId);
        $rows = DB::connection('tenant')->table('einsaetze')
            ->whereIn('artikel_id', $artikel->keys()->all())->where('hms_bestaetigt', '!=', 'ja')
            ->select('einsatz_id', 'datum', 'ist_start', 'ist_ende', 'artikel_id')->get();
        $count = 0;
        foreach ($rows as $row) {
            $service = $artikel->get($row->artikel_id);
            if (!$service) continue;
            $classification = SchichtartCalculator::compute(
                substr((string) $row->datum, 0, 10), $row->ist_start, $row->ist_ende, $service, $holidays
            );
            DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $row->einsatz_id)->update([
                'schichtart' => $classification['schichtart'],
                'zuschlag_pct' => $classification['zuschlag_pct'],
            ]);
            $count++;
        }
        return $count;
    }

    private function validateZuschlagFields(array $data): array
    {
        $errors = [];
        $states = ['BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH'];
        if (!empty($data['bundesland']) && !in_array(strtoupper((string) $data['bundesland']), $states, true)) {
            $errors[] = 'Ungültiges Bundesland';
        }
        foreach (['zuschlag_nacht_pct', 'zuschlag_sonntag_pct', 'zuschlag_feiertag_pct',
                  'zuschlag_nacht_sonntag_pct', 'zuschlag_nacht_feiertag_pct'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') continue;
            if (!is_numeric($data[$field]) || (float) $data[$field] < 0 || (float) $data[$field] > 200) {
                $errors[] = 'Ungültiger Zuschlag (0–200 % erlaubt)';
            }
        }
        foreach (['nacht_von', 'nacht_bis'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') continue;
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $data[$field])) {
                $errors[] = 'Ungültige Nachtzeit';
            }
        }
        return $errors;
    }

    protected function extractZuschlagFields(array $data, bool $fillDefaults): array
    {
        $defaults = [
            'zuschlaege_aktiv'             => 0,
            'zuschlag_nacht_pct'           => 10.00,
            'zuschlag_sonntag_pct'         => 25.00,
            'zuschlag_feiertag_pct'        => 50.00,
            'zuschlag_nacht_sonntag_pct'   => 35.00,
            'zuschlag_nacht_feiertag_pct'  => 60.00,
            'nacht_von'                    => '22:00:00',
            'nacht_bis'                    => '06:00:00',
        ];

        // Skip silently if the migration hasn't run on this tenant yet.
        if (!Schema::connection('tenant')->hasColumn('objekte', 'zuschlaege_aktiv')) {
            return [];
        }

        $result = [];
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $data)) {
                if (in_array($key, ['nacht_von', 'nacht_bis'], true)) {
                    $val = (string) $data[$key];
                    if (preg_match('/^\d{2}:\d{2}$/', $val)) $val .= ':00';
                    $result[$key] = $val;
                } elseif ($key === 'zuschlaege_aktiv') {
                    $result[$key] = (int) (bool) $data[$key];
                } else {
                    $result[$key] = round((float) $data[$key], 2);
                }
            } elseif ($fillDefaults) {
                $result[$key] = $default;
            }
        }
        return $result;
    }

    public function destroy(Request $request, string $id)
    {
        if (!BereichScope::allowsObjekt($request->user(), $id)) {
            return response()->json(['error' => 'Objekt nicht gefunden'], 404);
        }
        if ($request->boolean('permanent')) {
            // Never wipe booked shifts (planned with workers/NU, or confirmed
            // = worker/billing history) through an Objekt delete.
            $blocked = array_column(\App\Support\StaffedShifts::bookedForBestellungen(
                DB::connection('tenant')->table('bestellungen')->where('obj_id', $id)->pluck('bestell_id')->all()
            ), 'label');
            if ($blocked) {
                return response()->json([
                    'error' => 'Das Objekt hat eingeplante bzw. bestätigte Einsätze (' . count($blocked) . ') und kann nicht endgültig gelöscht werden. '
                        . 'Bitte stattdessen deaktivieren. Betroffen u. a.: ' . implode('; ', array_slice($blocked, 0, 5)),
                    'blocked_shifts' => array_slice($blocked, 0, 50),
                ], 409);
            }
            DB::connection('tenant')->beginTransaction();
            try {
                $db = DB::connection('tenant');

                // Find all einsaetze for this object (via bestellungen)
                $einsatzIds = $db->table('einsaetze')
                    ->whereIn('bestell_id', function ($q) use ($id) {
                        $q->select('bestell_id')->from('bestellungen')->where('obj_id', $id);
                    })
                    ->pluck('einsatz_id')->toArray();

                if (!empty($einsatzIds)) {
                    // Clean NU artefacts first (no FK cascade)
                    $posIds = $db->table('nu_schicht_positionen')
                        ->whereIn('einsatz_id', $einsatzIds)->pluck('id')->toArray();
                    if (!empty($posIds)) {
                        $db->table('nu_position_zuweisungen')->whereIn('position_id', $posIds)->delete();
                    }
                    $db->table('nu_schicht_positionen')->whereIn('einsatz_id', $einsatzIds)->delete();
                    $db->table('einsatz_nu_status')->whereIn('einsatz_id', $einsatzIds)->delete();
                    // einsatz_mitarbeiter cascades on einsaetze deletion
                    $db->table('einsaetze')->whereIn('einsatz_id', $einsatzIds)->delete();
                }

                $db->table('bestellungen')->where('obj_id', $id)->delete();

                $db->table('bestelleingang_tage')->whereIn('eingang_id', function ($q) use ($id) {
                    $q->select('eingang_id')->from('bestelleingang')->where('obj_id', $id);
                })->delete();
                $db->table('bestelleingang')->where('obj_id', $id)->delete();

                if ($db->getSchemaBuilder()->hasTable('preishistorie')) {
                    $db->table('preishistorie')->where('obj_id', $id)->delete();
                }

                // Articles (cascades from objekte FK on artikel anyway, but explicit is safer)
                $db->table('artikel')->where('obj_id', $id)->delete();
                $db->table('objekte')->where('obj_id', $id)->delete();

                $db->commit();
                return response()->json(['success' => true]);
            } catch (\Exception $e) {
                DB::connection('tenant')->rollBack();
                return response()->json(['error' => 'Löschen fehlgeschlagen: ' . $e->getMessage()], 500);
            }
        }

        // Soft delete: deactivate object + all its articles
        DB::connection('tenant')->table('objekte')->where('obj_id', $id)->update(['aktiv' => 0]);
        DB::connection('tenant')->table('artikel')->where('obj_id', $id)->update(['aktiv' => 0]);
        return response()->json(['success' => true]);
    }
}
