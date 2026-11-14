<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\IdGenerator;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages services (Artikel/Leistungen) tied to a physical Objekt.
 * One Objekt can have many Artikel (e.g. Sicherheit, Reinigung, Reparatur)
 * each with their own pricing model and rates.
 */
class ArtikelController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('id')) {
            return $this->show($request->id);
        }

        $query = DB::connection('tenant')
            ->table('artikel as a')
            ->leftJoin('objekte as o', 'a.obj_id', '=', 'o.obj_id')
            ->leftJoin('kunden as k', 'o.kunden_id', '=', 'k.kunde_id')
            ->select(
                'a.*',
                'o.objektname', 'o.kunden_id',
                'k.kundenname'
            );
        if (\App\Support\SecurityServices::ready()) {
            $query->leftJoin('sicherheitsleistungen as sl', 'sl.id', '=', 'a.leistungsart_id')
                ->addSelect('sl.name as leistungsart_name');
        }

        if ($request->filled('obj_id'))    $query->where('a.obj_id', $request->obj_id);
        if ($request->filled('kunden_id')) $query->where('o.kunden_id', $request->kunden_id);
        if ($request->filled('aktiv'))     $query->where('a.aktiv', $request->aktiv);
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('a.artikel_name', 'like', $s)
                  ->orWhere('a.artikel_id', 'like', $s)
                  ->orWhere('a.bereich', 'like', $s);
            });
        }

        // Per-user Bereich restriction — artikel.bereich is authoritative,
        // untagged articles derive from their object's customer.
        $query->whereRaw(... BereichScope::artikelPredicate($request->user(), 'a'));

        return response()->json($query->orderBy('a.artikel_id')->get());
    }

    public function show(string $id)
    {
        $artikel = DB::connection('tenant')
            ->table('artikel as a')
            ->leftJoin('objekte as o', 'a.obj_id', '=', 'o.obj_id')
            ->leftJoin('kunden as k', 'o.kunden_id', '=', 'k.kunde_id')
            ->select('a.*', 'o.objektname', 'o.kunden_id', 'k.kundenname')
            ->when(\App\Support\SecurityServices::ready(), fn ($q) => $q
                ->leftJoin('sicherheitsleistungen as sl', 'sl.id', '=', 'a.leistungsart_id')
                ->addSelect('sl.name as leistungsart_name'))
            ->where('a.artikel_id', $id)->first();

        if (!$artikel || !BereichScope::allowsArtikel(request()->user(), $id)) {
            return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        }
        return response()->json($artikel);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        $errors = $this->validateData($data);
        if (!empty($errors)) {
            return response()->json(['error' => implode(', ', $errors)], 400);
        }

        // Verify object exists and is inside the caller's Bereich scope
        $objExists = DB::connection('tenant')->table('objekte')
            ->where('obj_id', $data['obj_id'])->exists();
        if (!$objExists || !BereichScope::allowsObjekt($request->user(), (string) $data['obj_id'])) {
            return response()->json(['error' => 'Objekt nicht gefunden: ' . $data['obj_id']], 400);
        }
        if (!BereichScope::bereichInputAllowed($request->user(), $data['bereich'] ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        $hasArten = Schema::connection('tenant')->hasColumn('artikel', 'arten');
        $artenIn = $data['arten'] ?? [];
        if (!is_array($artenIn)
            || !BereichScope::artenWriteAllowed($request->user(), $artenIn, [])) {
            return response()->json(['error' => 'Kein Zugriff auf diese Objektart'], 403);
        }

        $artikel_id = IdGenerator::next('artikel', 'artikel_id', 'A-');
        $gewinn = floatval($data['preis_kunde'] ?? 0) - floatval($data['nu_preis'] ?? 0);

        $insert = [
            'artikel_id'   => $artikel_id,
            'obj_id'       => $data['obj_id'],
            'artikel_name' => $data['artikel_name'],
            'bereich'      => $data['bereich'] ?? null,
            'modell'       => $data['modell'] ?? 'Pro Einsatz',
            'preis_kunde'  => floatval($data['preis_kunde'] ?? 0),
            'nu_preis'     => floatval($data['nu_preis'] ?? 0),
            'gewinn'       => $gewinn,
            'einheit'      => $data['einheit'] ?? null,
            'aktiv'        => $data['aktiv'] ?? 1,
            'created_at'   => now(),
        ];
        if ($hasArten) {
            $insert['arten'] = $artenIn ? json_encode(array_values($artenIn)) : null;
        }
        // New positions name at least one Einsatzart (when the company has any);
        // the platform service type is derived from it, never picked separately.
        if (\App\Support\SecurityServices::mappingReady()) {
            if (!$artenIn && DB::connection('tenant')->table('bereiche_katalog')->whereNotNull('parent_id')->where('aktiv', 1)->exists()) {
                return response()->json(['error' => 'Bitte mindestens eine Einsatzart wählen'], 422);
            }
            $objArten = json_decode((string) DB::connection('tenant')->table('objekte')->where('obj_id', $data['obj_id'])->value('arten'), true);
            $insert['leistungsart_id'] = \App\Support\SecurityServices::derive(array_values($artenIn), is_array($objArten) ? $objArten : []);
        }

        // Optional Zuschläge fields (German wage surcharges).
        //
        // Resolution order (caller wins on conflict):
        //   1. Fields explicitly sent in the request → use those.
        //   2. Otherwise inherit from the parent Objekt (so a user who
        //      configured rates once at the Objekt level doesn't have to
        //      retype them for every new artikel).
        //   3. Fall back to migration defaults (10/25/50/35/60 + 22-06).
        $zuschlagFromRequest = $this->extractZuschlagFields($data, fillDefaults: false);
        $zuschlagInherited   = $this->inheritZuschlagFromObjekt($data['obj_id']);
        $zuschlagDefaults    = $this->extractZuschlagFields([], fillDefaults: true);
        // PHP `+` keeps the LEFT side on key collision — request > objekt > defaults.
        $insert += $zuschlagFromRequest + $zuschlagInherited + $zuschlagDefaults;

        DB::connection('tenant')->table('artikel')->insert($insert);

        // Track initial price as a history entry (per-artikel)
        if (DB::connection('tenant')->getSchemaBuilder()->hasTable('preishistorie')) {
            DB::connection('tenant')->table('preishistorie')->insert([
                'aenderungsdatum'  => now()->toDateString(),
                'obj_id'           => $data['obj_id'],
                'artikel_id'       => $artikel_id,
                'objektname'       => $data['artikel_name'],
                'kunden_id'        => DB::connection('tenant')->table('objekte')
                                        ->where('obj_id', $data['obj_id'])->value('kunden_id'),
                'neuer_preis_kunde'=> floatval($data['preis_kunde'] ?? 0),
                'neuer_nu_preis'   => floatval($data['nu_preis'] ?? 0),
                'grund'            => 'Startpreis – Artikel angelegt ' . now()->format('F Y'),
            ]);
        }

        return response()->json(['success' => true, 'artikel_id' => $artikel_id], 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->all();

        $existing = DB::connection('tenant')->table('artikel')->where('artikel_id', $id)->first();
        if (!$existing || !BereichScope::allowsArtikel($request->user(), $id)) {
            return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        }
        if (array_key_exists('bereich', $data)
            && !BereichScope::bereichWriteAllowed($request->user(), $data['bereich'], $existing->bereich ?? null)) {
            return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        $hasArten = Schema::connection('tenant')->hasColumn('artikel', 'arten');
        $exArten = $hasArten ? (json_decode($existing->arten ?? '[]', true) ?: []) : [];
        if (array_key_exists('arten', $data)
            && (!is_array($data['arten'])
                || !BereichScope::artenWriteAllowed($request->user(), $data['arten'], $exArten))) {
            return response()->json(['error' => 'Kein Zugriff auf diese Objektart'], 403);
        }

        $errors = $this->validateData($data, isUpdate: true);
        if (!empty($errors)) {
            return response()->json(['error' => implode(', ', $errors)], 400);
        }

        $newPreisKunde = isset($data['preis_kunde']) ? floatval($data['preis_kunde']) : floatval($existing->preis_kunde);
        $newNuPreis    = isset($data['nu_preis'])    ? floatval($data['nu_preis'])    : floatval($existing->nu_preis);
        $gewinn = $newPreisKunde - $newNuPreis;

        $update = [
            'artikel_name' => $data['artikel_name'] ?? $existing->artikel_name,
            'bereich'      => $data['bereich']      ?? $existing->bereich,
            'modell'       => $data['modell']       ?? $existing->modell,
            'preis_kunde'  => $newPreisKunde,
            'nu_preis'     => $newNuPreis,
            'gewinn'       => $gewinn,
            'einheit'      => array_key_exists('einheit', $data) ? $data['einheit'] : $existing->einheit,
            'aktiv'        => array_key_exists('aktiv', $data) ? $data['aktiv'] : $existing->aktiv,
            'updated_at'   => now(),
        ];
        // arten: caller's list + stored values outside his scope re-merged
        // (he cannot see them in the picker — dropping them here would
        // silently strip data he never edited).
        if ($hasArten && array_key_exists('arten', $data)) {
            $mergedArten = array_values(array_unique(array_merge(
                $data['arten'],
                BereichScope::outOfScopeArten($request->user(), $exArten)
            )));
            $update['arten'] = $mergedArten ? json_encode($mergedArten) : null;
        }

        // Merge Zuschläge updates only for fields the caller actually sent.
        $update += $this->extractZuschlagFields($data, fillDefaults: false);

        DB::connection('tenant')->table('artikel')->where('artikel_id', $id)->update($update);
        // Platform service type follows the (possibly changed) Einsatzarten.
        \App\Support\SecurityServices::syncArtikel([$id]);

        // If Zuschläge config changed, reclassify all OPEN einsätze for this artikel.
        // Bestätigte einsätze keep their snapshotted zuschlag_pct so historic
        // numbers in Controlling stay reproducible.
        if ($this->zuschlagConfigChanged($existing, $update)) {
            $this->reclassifyOpenEinsaetze($id);
        }

        // Log price change
        $priceChanged = floatval($existing->preis_kunde) !== $newPreisKunde
                     || floatval($existing->nu_preis)   !== $newNuPreis;
        if ($priceChanged && DB::connection('tenant')->getSchemaBuilder()->hasTable('preishistorie')) {
            DB::connection('tenant')->table('preishistorie')->insert([
                'aenderungsdatum'   => now()->toDateString(),
                'obj_id'            => $existing->obj_id,
                'artikel_id'        => $id,
                'objektname'        => $data['artikel_name'] ?? $existing->artikel_name,
                'kunden_id'         => DB::connection('tenant')->table('objekte')
                                          ->where('obj_id', $existing->obj_id)->value('kunden_id'),
                'alter_preis_kunde' => $existing->preis_kunde,
                'neuer_preis_kunde' => $newPreisKunde,
                'alter_nu_preis'    => $existing->nu_preis,
                'neuer_nu_preis'    => $newNuPreis,
                'grund'             => $data['grund'] ?? 'Preisänderung',
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, string $id)
    {
        $existing = DB::connection('tenant')->table('artikel')->where('artikel_id', $id)->first();
        if (!$existing || !BereichScope::allowsArtikel($request->user(), $id)) {
            return response()->json(['error' => 'Artikel nicht gefunden'], 404);
        }

        // Permanent delete blocked if there are historic transactions tied to this artikel
        if ($request->boolean('permanent')) {
            $hasUsage = DB::connection('tenant')->table('bestelleingang')->where('artikel_id', $id)->exists()
                     || DB::connection('tenant')->table('bestellungen')->where('artikel_id', $id)->exists()
                     || DB::connection('tenant')->table('einsaetze')->where('artikel_id', $id)->exists();
            if ($hasUsage) {
                return response()->json([
                    'error' => 'Artikel hat Bestellungen/Einsätze und kann nicht endgültig gelöscht werden. Bitte deaktivieren.'
                ], 409);
            }
            DB::connection('tenant')->table('artikel')->where('artikel_id', $id)->delete();
            return response()->json(['success' => true, 'mode' => 'deleted']);
        }

        // Soft delete: deactivate
        DB::connection('tenant')->table('artikel')->where('artikel_id', $id)->update([
            'aktiv' => 0,
            'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'mode' => 'deactivated']);
    }

    protected function validateData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate) {
            if (empty($data['obj_id'])) $errors[] = 'Objekt ist erforderlich';
            if (empty($data['artikel_name'])) $errors[] = 'Artikel-Name ist erforderlich';
        }

        if (isset($data['modell']) && $data['modell'] !== '' &&
            !in_array($data['modell'], ['Standard', 'Pro Einsatz', 'Pro Stunde', 'Monatspauschale'])) {
            $errors[] = 'Ungültiges Modell';
        }

        foreach (['preis_kunde', 'nu_preis'] as $f) {
            if (isset($data[$f]) && $data[$f] !== '' && (!is_numeric($data[$f]) || floatval($data[$f]) < 0 || floatval($data[$f]) > 99999999.99)) {
                $errors[] = "Ungültiger Wert für $f";
            }
        }

        // Zuschläge percentages must be 0..200 (200% covers extreme cases like
        // doubled holiday pay; matches collective agreements in cleaning &
        // security industries).
        $pctFields = [
            'zuschlag_tag_pct',
            'zuschlag_nacht_pct',
            'zuschlag_sonntag_pct',
            'zuschlag_feiertag_pct',
            'zuschlag_nacht_sonntag_pct',
            'zuschlag_nacht_feiertag_pct',
        ];
        foreach ($pctFields as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === '' || $data[$f] === null) continue;
            if (!is_numeric($data[$f]) || floatval($data[$f]) < 0 || floatval($data[$f]) > 200) {
                $errors[] = "Ungültiger Zuschlag für $f (0–200 % erlaubt)";
            }
        }

        // nacht_von / nacht_bis must be valid 'HH:MM' or 'HH:MM:SS'.
        foreach (['nacht_von', 'nacht_bis'] as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === '' || $data[$f] === null) continue;
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $data[$f])) {
                $errors[] = "Ungültiges Zeitformat für $f (erwartet HH:MM)";
            }
        }

        return $errors;
    }

    /**
     * Extract the optional Zuschläge fields from the request payload.
     *
     * @param array $data        Request data
     * @param bool  $fillDefaults If true, set safe defaults for fields the
     *                            caller omitted (used on `store`). If false,
     *                            only include fields actually present (used
     *                            on `update` so legacy callers don't reset
     *                            stored values to 0).
     */
    protected function extractZuschlagFields(array $data, bool $fillDefaults): array
    {
        $defaults = [
            'zuschlaege_aktiv'             => 0,
            'zuschlag_tag_pct'             => 0.00,
            'zuschlag_nacht_pct'           => 10.00,
            'zuschlag_sonntag_pct'         => 25.00,
            'zuschlag_feiertag_pct'        => 50.00,
            'zuschlag_nacht_sonntag_pct'   => 35.00,
            'zuschlag_nacht_feiertag_pct'  => 60.00,
            'nacht_von'                    => '22:00:00',
            'nacht_bis'                    => '06:00:00',
        ];

        // Guard for tenants whose artikel migration hasn't run yet — without
        // the column an UPDATE carrying the key would 500.
        if (!Schema::connection('tenant')->hasColumn('artikel', 'zuschlag_tag_pct')) {
            unset($defaults['zuschlag_tag_pct']);
        }

        $result = [];
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $data)) {
                if (in_array($key, ['nacht_von', 'nacht_bis'], true)) {
                    $val = (string) $data[$key];
                    // Normalize HH:MM → HH:MM:00
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

    /**
     * Read the parent Objekt's Zuschläge config (for inheritance on new
     * Artikel). Returns an empty array if the objekte table doesn't have
     * the columns yet (migration not run) or the objekt is missing.
     */
    protected function inheritZuschlagFromObjekt(?string $objId): array
    {
        if (!$objId) return [];
        if (!Schema::connection('tenant')->hasColumn('objekte', 'zuschlaege_aktiv')) return [];

        $obj = DB::connection('tenant')->table('objekte')->where('obj_id', $objId)->first();
        if (!$obj) return [];

        $inherit = [
            'zuschlaege_aktiv'             => (int) ($obj->zuschlaege_aktiv ?? 0),
            'zuschlag_nacht_pct'           => (float) ($obj->zuschlag_nacht_pct           ?? 10.00),
            'zuschlag_sonntag_pct'         => (float) ($obj->zuschlag_sonntag_pct         ?? 25.00),
            'zuschlag_feiertag_pct'        => (float) ($obj->zuschlag_feiertag_pct        ?? 50.00),
            'zuschlag_nacht_sonntag_pct'   => (float) ($obj->zuschlag_nacht_sonntag_pct   ?? 35.00),
            'zuschlag_nacht_feiertag_pct'  => (float) ($obj->zuschlag_nacht_feiertag_pct  ?? 60.00),
            'nacht_von'                    => (string) ($obj->nacht_von ?? '22:00:00'),
            'nacht_bis'                    => (string) ($obj->nacht_bis ?? '06:00:00'),
        ];
        if (Schema::connection('tenant')->hasColumn('artikel', 'zuschlag_tag_pct')) {
            $inherit['zuschlag_tag_pct'] = (float) ($obj->zuschlag_tag_pct ?? 0.00);
        }
        return $inherit;
    }

    /**
     * Did the user actually change a Zuschläge-relevant field?
     */
    protected function zuschlagConfigChanged($existing, array $update): bool
    {
        $watched = [
            'zuschlaege_aktiv',
            'zuschlag_tag_pct',
            'zuschlag_nacht_pct',
            'zuschlag_sonntag_pct',
            'zuschlag_feiertag_pct',
            'zuschlag_nacht_sonntag_pct',
            'zuschlag_nacht_feiertag_pct',
            'nacht_von',
            'nacht_bis',
        ];
        foreach ($watched as $f) {
            if (!array_key_exists($f, $update)) continue;
            $oldVal = $existing->{$f} ?? null;
            $newVal = $update[$f];
            // Loose comparison so '10.00' === 10 when reading from MySQL.
            if ((string) $oldVal !== (string) $newVal) return true;
        }
        return false;
    }

    /**
     * Recompute schichtart + zuschlag_pct for every still-open Einsatz that
     * uses this artikel. We deliberately leave bestätigte einsätze alone:
     * their zuschlag_pct was snapshotted at confirmation time and
     * Controlling needs that snapshot to stay stable.
     */
    protected function reclassifyOpenEinsaetze(string $artikelId): void
    {
        $artikel = DB::connection('tenant')->table('artikel')->where('artikel_id', $artikelId)->first();
        if (!$artikel) return;

        $feiertage = \App\Helpers\SchichtartCalculator::cachedFeiertage($artikel->obj_id ?? null);

        $rows = DB::connection('tenant')->table('einsaetze')
            ->where('artikel_id', $artikelId)
            ->where('hms_bestaetigt', '!=', 'ja')
            ->select('einsatz_id', 'datum', 'ist_start', 'ist_ende')
            ->get();

        foreach ($rows as $r) {
            $cls = \App\Helpers\SchichtartCalculator::compute(
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
        }
    }
}
