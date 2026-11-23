<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use App\Support\Branchen;
use App\Support\TenantProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Managed Bereich catalog (tenant table `bereiche_katalog`):
 *   mains (parent_id NULL)  → feed the `bereiche` multi-select on
 *                             kunden / objekte / mitarbeiter / nachunternehmer
 *   subs  (parent_id = main)→ feed the objekte Objektart (`arten`) picker.
 *
 * Read: every authenticated user (restricted users get scope-filtered rows).
 * Write: admins/owners + any stammdaten editor (canManage()).
 */
class BereichCatalogController extends Controller
{
    /** Tables whose `bereich`/`bereiche` hold MAIN names. */
    private const TAGGED_TABLES = ['artikel', 'kunden', 'objekte', 'mitarbeiter', 'nachunternehmer'];

    private const MAIN_LOCKED = 'Clood-X ist auf Sicherheitsdienste ausgerichtet: Der Sicherheitsbereich bleibt bestehen und es können keine weiteren Hauptbereiche angelegt werden. Einsatzarten, Qualifikationen und Dienstkleidung lassen sich frei ergänzen.';

    public function index(Request $request)
    {
        if (!$this->hasTable()) {
            return response()->json(['mains' => [], 'managed' => false]);
        }

        $rows = DB::connection('tenant')->table('bereiche_katalog')
            ->orderBy('parent_id')->orderBy('sortierung')->orderBy('name')->get();

        $allowed = BereichScope::for($request->user());
        $mains = [];
        $subs  = [];
        foreach ($rows as $r) {
            if ($r->parent_id === null) {
                $mains[$r->id] = ['id' => $r->id, 'name' => $r->name, 'farbe' => $r->farbe ?? null, 'aktiv' => (int) $r->aktiv,
                    // Security switches (2026_10_19_110000): Sicherheit tab / stadium fields in the worker form.
                    'bewachung' => (int) ($r->bewachung ?? 0), 'stadiondienst' => (int) ($r->stadiondienst ?? 0),
                    // Industry of the main (2026_10_21_200000) — keys of Branchen::REGISTRY.
                    'branche' => $r->branche ?? null,
                    'subs' => []];
            } else {
                $subs[] = $r;
            }
        }
        foreach ($subs as $s) {
            if (isset($mains[$s->parent_id])) {
                // Subs carry their main's colour (one colour per service area).
                $mains[$s->parent_id]['subs'][] = ['id' => $s->id, 'name' => $s->name, 'farbe' => $mains[$s->parent_id]['farbe'], 'aktiv' => (int) $s->aktiv,
                    // Platform service type of this Einsatzart (2026_10_28_120000).
                    'leistungsart_id' => isset($s->leistungsart_id) ? (int) $s->leistungsart_id : null];
            }
        }
        // Qualifikationen lists (manually maintained) ride on their main.
        $quals = BereichQualifikationController::listsByMain();
        // Dienstkleidung lists: Bestelleingang only, never on workers.
        $kleidung = BereichQualifikationController::listsByMain(BereichQualifikationController::TYP_KLEIDUNG);
        foreach ($mains as $mid => &$m) {
            $m['quals'] = $quals[$mid] ?? [];
            $m['kleidung'] = $kleidung[$mid] ?? [];
        }
        unset($m);
        $mains = array_values($mains);

        // Restricted users see only their own mains (+ their subs).
        if (is_array($allowed)) {
            $mains = array_values(array_filter(
                $mains,
                fn ($m) => BereichScope::listAllows($allowed, $m['name'])
            ));
        }
        // ?subs=1 — flat sub list for the Objektart picker (already filtered).
        if ($request->boolean('subs')) {
            $flat = [];
            foreach ($mains as $m) {
                foreach ($m['subs'] as $s) {
                    if ($s['aktiv']) {
                        $flat[] = $s['name'];
                    }
                }
            }
            return response()->json(array_values(array_unique($flat)));
        }

        $branchen = array_values(array_filter(Branchen::forUi(), fn ($b) => TenantProfile::isSecurityProfile($b['key'])));
        return response()->json(['mains' => $mains, 'managed' => true, 'branchen' => $branchen, 'branche_ok' => Branchen::hasColumn()]);
    }

    /** GET /sicherheitsleistungen — active security service types (artikel picker). */
    public function leistungen()
    {
        return response()->json(\App\Support\SecurityServices::active());
    }

    public function store(Request $request)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        $name = trim((string) $request->input('name'));
        if ($name === '') {
            return response()->json(['error' => 'Name ist erforderlich'], 422);
        }
        if (str_contains($name, ',') || strlen($name) > 190) {
            return response()->json(['error' => 'Ungültiger Name (kein Komma, max. 190 Zeichen)'], 422);
        }
        $parentId = $request->input('parent_id');
        if ($parentId !== null && !$this->rowExists((int) $parentId)) {
            return response()->json(['error' => 'Hauptbereich nicht gefunden'], 404);
        }
        // Security-only product: exactly one main (the security area). A new
        // main is only possible while the company has none at all.
        if ($parentId === null && DB::connection('tenant')->table('bereiche_katalog')->whereNull('parent_id')->exists()) {
            return response()->json(['error' => self::MAIN_LOCKED], 409);
        }
        // Restricted users may only manage subs under their own mains —
        // a brand-new main is by definition outside their whitelist.
        $allowed = BereichScope::for($request->user());
        if (is_array($allowed)) {
            if ($parentId === null) {
                return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
            }
            $parent = DB::connection('tenant')->table('bereiche_katalog')->find((int) $parentId);
            if (!$parent || !BereichScope::listAllows($allowed, $parent->name)) {
                return response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
            }
        }
        $dup = DB::connection('tenant')->table('bereiche_katalog')
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($dup) {
            return response()->json(['error' => 'Name existiert bereits'], 422);
        }

        $insert = [
            'name'       => $name,
            'parent_id'  => $parentId,
            'aktiv'      => 1,
            'sortierung' => (int) $request->input('sortierung', 0),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $farbe = $this->farbeInput($request);
        if ($farbe === false) {
            return response()->json(['error' => 'Ungültige Farbe (#RRGGBB)'], 422);
        }
        if ($farbe !== null && $parentId === null && $this->hasFarbeColumn()) {
            $insert['farbe'] = $farbe;
        }
        if ($parentId === null && Schema::connection('tenant')->hasColumn('bereiche_katalog', 'bewachung')) {
            $insert['bewachung'] = 1;
        }
        // Einsatzart → platform service type: explicit choice, else a name-based suggestion.
        if ($parentId !== null && \App\Support\SecurityServices::mappingReady()) {
            $lid = $request->input('leistungsart_id');
            if ($lid !== null && $lid !== '' && !\App\Support\SecurityServices::isActiveId($lid)) {
                return response()->json(['error' => 'Ungültige Leistungsart'], 422);
            }
            $insert['leistungsart_id'] = ($lid !== null && $lid !== '') ? (int) $lid
                : \App\Support\SecurityServices::idForKey(\App\Support\SecurityServices::guess($name));
        }
        if ($parentId === null || $request->exists('branche')) {
            $branche = $request->input('branche', TenantProfile::INDUSTRY);
            if ($parentId !== null || !TenantProfile::isSecurityProfile($branche)) {
                return response()->json(['error' => 'Nur Sicherheitsdienst und Veranstaltungsdienst sind verfügbar'], 422);
            }
            if (Branchen::hasColumn()) $insert['branche'] = $branche;
        }
        $id = DB::connection('tenant')->table('bereiche_katalog')->insertGetId($insert);
        if ($parentId === null) \App\Support\SecurityServices::seedStarterEinsatzarten((int) $id);
        BereichScope::invalidateStaticCache();
        \App\Helpers\Realtime::changed('bereiche');
        return response()->json(['success' => true, 'id' => $id], 201);
    }

    public function update(Request $request, int $id)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        $row = DB::connection('tenant')->table('bereiche_katalog')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        if ($resp = $this->scopeCheckRow($request->user(), $row)) return $resp;

        // The security main keeps its name, stays active and stays a §34a area.
        if ($row->parent_id === null) {
            $in = $request->all();
            if ((array_key_exists('name', $in) && trim((string) $in['name']) !== $row->name)
                || (array_key_exists('aktiv', $in) && !(int) $in['aktiv'] && $this->isLastMain($id))
                || (array_key_exists('bewachung', $in) && !$request->boolean('bewachung'))) {
                return response()->json(['error' => self::MAIN_LOCKED], 409);
            }
        }

        $update = [];
        if (array_key_exists('name', $request->all())) {
            $name = trim((string) $request->input('name'));
            if ($name === '' || str_contains($name, ',') || strlen($name) > 190) {
                return response()->json(['error' => 'Ungültiger Name'], 422);
            }
            $dup = DB::connection('tenant')->table('bereiche_katalog')
                ->where('parent_id', $row->parent_id)->where('id', '!=', $id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
            if ($dup) {
                return response()->json(['error' => 'Name existiert bereits'], 422);
            }
            $update['name'] = $name;
        }
        foreach (['aktiv', 'sortierung'] as $f) {
            if (array_key_exists($f, $request->all())) {
                $update[$f] = $request->input($f);
            }
        }
        // Security switches live on the MAIN only.
        foreach (['bewachung', 'stadiondienst'] as $f) {
            if (!array_key_exists($f, $request->all())) continue;
            if (!Schema::connection('tenant')->hasColumn('bereiche_katalog', $f)) {
                return response()->json(['error' => 'Noch nicht verfügbar (Migration ausstehend)'], 409);
            }
            if ($row->parent_id !== null) {
                return response()->json(['error' => 'Nur für Hauptbereiche'], 422);
            }
            $update[$f] = $request->boolean($f) ? 1 : 0;
        }
        // Only security industries may be selected on a main Bereich.
        if (array_key_exists('branche', $request->all())) {
            if (!Branchen::hasColumn()) {
                return response()->json(['error' => 'Noch nicht verfügbar (Migration ausstehend)'], 409);
            }
            if ($row->parent_id !== null) {
                return response()->json(['error' => 'Nur für Hauptbereiche'], 422);
            }
            $b = $request->input('branche');
            if (!TenantProfile::isSecurityProfile($b)) {
                return response()->json(['error' => 'Nur Sicherheitsdienst und Veranstaltungsdienst sind verfügbar'], 422);
            }
            $update['branche'] = $b;
        }
        // Stadium duty implies a security area.
        if (!empty($update['stadiondienst'])) $update['bewachung'] = 1;
        if (array_key_exists('bewachung', $update) && $update['bewachung'] === 0) $update['stadiondienst'] = 0;
        // Colour lives on the MAIN only — subs inherit it.
        if (array_key_exists('farbe', $request->all())) {
            if (!$this->hasFarbeColumn()) {
                return response()->json(['error' => 'Bereichsfarben noch nicht verfügbar (Migration ausstehend)'], 409);
            }
            if ($row->parent_id !== null) {
                return response()->json(['error' => 'Unterbereiche übernehmen die Farbe ihres Hauptbereichs'], 422);
            }
            $farbe = $this->farbeInput($request);
            if ($farbe === false) {
                return response()->json(['error' => 'Ungültige Farbe (#RRGGBB)'], 422);
            }
            $update['farbe'] = $farbe;
        }
        // Platform service type lives on the Einsatzart (sub) only; null = not mapped.
        if (array_key_exists('leistungsart_id', $request->all())) {
            if (!\App\Support\SecurityServices::mappingReady()) {
                return response()->json(['error' => 'Noch nicht verfügbar (Migration ausstehend)'], 409);
            }
            if ($row->parent_id === null) {
                return response()->json(['error' => 'Nur für Einsatzarten'], 422);
            }
            $lid = $request->input('leistungsart_id');
            if ($lid !== null && $lid !== '' && !\App\Support\SecurityServices::isActiveId($lid)) {
                return response()->json(['error' => 'Ungültige Leistungsart'], 422);
            }
            $update['leistungsart_id'] = ($lid !== null && $lid !== '') ? (int) $lid : null;
        }
        if (!$update) {
            return response()->json(['success' => true]);
        }
        $update['updated_at'] = now();
        DB::connection('tenant')->table('bereiche_katalog')->where('id', $id)->update($update);

        // Rename propagation — a renamed tag must follow the data, otherwise
        // every row (and every user's whitelist) keeps the old name.
        if (isset($update['name']) && mb_strtolower($update['name']) !== mb_strtolower($row->name)) {
            $row->parent_id === null
                ? $this->renameMainEverywhere($row->name, $update['name'])
                : $this->renameSubEverywhere($row->name, $update['name']);
        }
        // Article service types follow the Einsatzart mapping.
        if ($row->parent_id !== null && (array_key_exists('leistungsart_id', $update) || isset($update['name']) || isset($update['aktiv']))) {
            \App\Support\SecurityServices::syncArtikel();
        }
        BereichScope::invalidateStaticCache();
        \App\Helpers\Realtime::changed('bereiche');
        return response()->json(['success' => true]);
    }

    /**
     * POST /bereich-catalog/{id}/paket {branche?} — applies the industry
     * starter pack to a main: missing Objektarten, Qualifikations- and
     * Kleidungs-Listen, security switches (only ever switched on) and the
     * industry itself. Existing entries are never changed or removed.
     */
    public function paket(Request $request, int $id)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        if (!Branchen::hasColumn()) {
            return response()->json(['error' => 'Noch nicht verfügbar (Migration ausstehend)'], 409);
        }
        $row = DB::connection('tenant')->table('bereiche_katalog')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        if ($row->parent_id !== null) {
            return response()->json(['error' => 'Nur für Hauptbereiche'], 422);
        }
        if ($resp = $this->scopeCheckRow($request->user(), $row)) return $resp;
        $key = (string) ($request->input('branche') ?: ($row->branche ?? ''));
        if (!TenantProfile::isSecurityProfile($key)) {
            return response()->json(['error' => 'Nur Sicherheitsdienst und Veranstaltungsdienst sind verfügbar'], 422);
        }
        $res = Branchen::applyPaket($id, $key);
        BereichScope::invalidateStaticCache();
        \App\Helpers\Realtime::changed('bereiche');
        return response()->json(['success' => true] + $res);
    }

    public function destroy(Request $request, int $id)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        $row = DB::connection('tenant')->table('bereiche_katalog')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        if ($resp = $this->scopeCheckRow($request->user(), $row)) return $resp;
        if ($row->parent_id === null && $this->isLastMain($id)) {
            return response()->json(['error' => self::MAIN_LOCKED], 409);
        }
        if ($row->parent_id === null) {
            $subCount = DB::connection('tenant')->table('bereiche_katalog')->where('parent_id', $id)->count();
            if ($subCount > 0) {
                return response()->json(['error' => 'Hauptbereich hat Unterbereiche — zuerst löschen'], 409);
            }
            if (!empty(BereichQualifikationController::listsByMain(null)[$id])) {
                return response()->json(['error' => 'Hauptbereich hat Qualifikations- oder Kleidungs-Listen — zuerst löschen'], 409);
            }
            if ($this->mainInUse($row->name)) {
                return response()->json(['error' => 'Bereich wird noch verwendet und kann nicht gelöscht werden'], 409);
            }
        } elseif ($this->subInUse($row->name)) {
            return response()->json(['error' => 'Unterbereich wird noch verwendet und kann nicht gelöscht werden'], 409);
        }
        DB::connection('tenant')->table('bereiche_katalog')->where('id', $id)->delete();
        BereichScope::invalidateStaticCache();
        \App\Helpers\Realtime::changed('bereiche');
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    /** True when $id is the company's only active main (the security area). */
    private function isLastMain(int $id): bool
    {
        return !DB::connection('tenant')->table('bereiche_katalog')
            ->whereNull('parent_id')->where('aktiv', 1)->where('id', '!=', $id)->exists();
    }

    private function hasTable(): bool
    {
        return Schema::connection('tenant')->hasTable('bereiche_katalog');
    }

    private function hasFarbeColumn(): bool
    {
        return $this->hasTable() && Schema::connection('tenant')->hasColumn('bereiche_katalog', 'farbe');
    }

    /** null = not sent / reset to automatic, false = invalid, else '#rrggbb'. */
    private function farbeInput(Request $request): string|null|false
    {
        $v = $request->input('farbe');
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) return false;
        return strtolower($v);
    }

    private function rowExists(int $id): bool
    {
        return $this->hasTable()
            && DB::connection('tenant')->table('bereiche_katalog')->where('id', $id)->exists();
    }

    private function requireManage(Request $request)
    {
        if (!$this->hasTable()) {
            return response()->json(['error' => 'Katalog nicht verfügbar (Migration ausstehend)'], 409);
        }
        $u = $request->user();
        // Bereiche are company master data — Inhaber only (owner-only
        // users.manage; super_admin keeps it via hasPermission()).
        $ok = $u && $u->hasPermission('users.manage');
        if (!$ok) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        return null;
    }

    /**
     * A restricted manager may only touch catalog rows inside his own
     * whitelist: a main must itself be allowed, a sub must sit under an
     * allowed main.
     */
    private function scopeCheckRow($user, $row)
    {
        $allowed = BereichScope::for($user);
        if ($allowed === null) {
            return null;
        }
        if ($row->parent_id === null) {
            return BereichScope::listAllows($allowed, $row->name)
                ? null
                : response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
        }
        $parent = DB::connection('tenant')->table('bereiche_katalog')->find($row->parent_id);
        return ($parent && BereichScope::listAllows($allowed, $parent->name))
            ? null
            : response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
    }

    /**
     * True when the main name is still stored on any COMPANY entity
     * (artikel / kunden / objekte / nachunternehmer). The catalog lists the
     * company's service areas — a worker's own Bereich tag never blocks a
     * delete; the worker simply keeps his stored value.
     */
    private function mainInUse(string $name): bool
    {
        $l = mb_strtolower($name);
        foreach (array_diff(self::TAGGED_TABLES, ['mitarbeiter']) as $tbl) {
            $schema = Schema::connection('tenant');
            if (!$schema->hasTable($tbl)) continue;
            // Comma-aware: a legacy "A, B" cell counts as two tags, so a
            // plain equality check would report a still-used main as free.
            if ($schema->hasColumn($tbl, 'bereich')
                && DB::connection('tenant')->table($tbl)
                    ->whereRaw("CONCAT(',', REPLACE(LOWER(bereich), ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')",
                        [str_replace(' ', '', $l)])->exists()) {
                return true;
            }
            if ($schema->hasColumn($tbl, 'bereiche')) {
                foreach (DB::connection('tenant')->table($tbl)->whereNotNull('bereiche')->pluck('bereiche') as $json) {
                    foreach (json_decode($json, true) ?: [] as $v) {
                        if (mb_strtolower(trim((string) $v)) === $l) return true;
                    }
                }
            }
        }
        // Central whitelists count as "in use" too — unless the name lives on
        // through worker tags: there the whitelist still governs which users
        // see those workers, independent of the catalog.
        if ($this->workerCarries($l)) {
            return false;
        }
        $central = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', \App\Services\TenantService::assertActive()->id)
            ->whereNotNull('allowed_bereiche')->pluck('allowed_bereiche');
        foreach ($central as $json) {
            foreach (json_decode($json, true) ?: [] as $v) {
                if (mb_strtolower(trim((string) $v)) === $l) return true;
            }
        }
        return false;
    }

    /** True when any worker carries the (lowercased) main in bereich/bereiche. */
    private function workerCarries(string $l): bool
    {
        $schema = Schema::connection('tenant');
        if (!$schema->hasTable('mitarbeiter')) return false;
        if ($schema->hasColumn('mitarbeiter', 'bereich')
            && DB::connection('tenant')->table('mitarbeiter')
                ->whereRaw("CONCAT(',', REPLACE(LOWER(bereich), ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')",
                    [str_replace(' ', '', $l)])->exists()) {
            return true;
        }
        if ($schema->hasColumn('mitarbeiter', 'bereiche')) {
            foreach (DB::connection('tenant')->table('mitarbeiter')->whereNotNull('bereiche')->pluck('bereiche') as $json) {
                foreach (json_decode($json, true) ?: [] as $v) {
                    if (mb_strtolower(trim((string) $v)) === $l) return true;
                }
            }
        }
        return false;
    }

    /** Rename a MAIN on every tagged entity + every user whitelist. */
    private function renameMainEverywhere(string $old, string $new): void
    {
        $oldL = mb_strtolower($old);
        $db = DB::connection('tenant');
        foreach (self::TAGGED_TABLES as $tbl) {
            $schema = Schema::connection('tenant');
            if (!$schema->hasTable($tbl)) continue;
            $pk = $tbl === 'kunden' ? 'kunde_id'
                : ($tbl === 'objekte' ? 'obj_id'
                : ($tbl === 'mitarbeiter' ? 'dienst_nr'
                : ($tbl === 'nachunternehmer' ? 'nu_id' : 'artikel_id')));
            if ($schema->hasColumn($tbl, 'bereich')) {
                // `bereich` may hold comma-joined legacy tags ("A, B") —
                // rewrite them part-wise so no stale token survives.
                foreach ($db->table($tbl)
                        ->whereRaw("CONCAT(',', REPLACE(LOWER(bereich), ' ', ''), ',') LIKE CONCAT('%,', ?, ',%')",
                            [str_replace(' ', '', $oldL)])
                        ->get([$pk, 'bereich']) as $row) {
                    $parts = array_map('trim', explode(',', (string) $row->bereich));
                    $changed = false;
                    foreach ($parts as $i => $v) {
                        if (mb_strtolower($v) === $oldL) {
                            $parts[$i] = $new;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $db->table($tbl)->where($pk, $row->$pk)
                            ->update(['bereich' => implode(', ', $parts)]);
                    }
                }
            }
            if ($schema->hasColumn($tbl, 'bereiche')) {
                foreach ($db->table($tbl)->whereNotNull('bereiche')->get([$pk, 'bereiche']) as $row) {
                    $list = json_decode($row->bereiche, true) ?: [];
                    $changed = false;
                    foreach ($list as $i => $v) {
                        if (mb_strtolower(trim((string) $v)) === $oldL) {
                            $list[$i] = $new;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $db->table($tbl)->where($pk, $row->$pk)->update(['bereiche' => json_encode(array_values($list))]);
                    }
                }
            }
        }
        // Central user whitelists reference the name too.
        // Only THIS company's users — another tenant may use the same name.
        foreach (DB::connection('mysql')->table('central_users')
                ->where('tenant_id', \App\Services\TenantService::assertActive()->id)
                ->whereNotNull('allowed_bereiche')->get(['id', 'allowed_bereiche']) as $u) {
            $list = json_decode($u->allowed_bereiche, true) ?: [];
            $changed = false;
            foreach ($list as $i => $v) {
                if (mb_strtolower(trim((string) $v)) === $oldL) {
                    $list[$i] = $new;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::connection('mysql')->table('central_users')->where('id', $u->id)
                    ->update(['allowed_bereiche' => json_encode(array_values($list))]);
            }
        }
    }

    /** True when the sub name is still used as an Objektart anywhere. */
    private function subInUse(string $name): bool
    {
        $l = mb_strtolower($name);
        $schema = Schema::connection('tenant');
        if ($schema->hasTable('objekte') && $schema->hasColumn('objekte', 'art')
            && DB::connection('tenant')->table('objekte')
                ->whereRaw('LOWER(TRIM(art)) = ?', [$l])->exists()) {
            return true;
        }
        foreach ([['objekte', 'obj_id'], ['artikel', 'artikel_id']] as [$tbl, $pk]) {
            if (!$schema->hasTable($tbl) || !$schema->hasColumn($tbl, 'arten')) continue;
            foreach (DB::connection('tenant')->table($tbl)->whereNotNull('arten')->pluck('arten') as $json) {
                foreach (json_decode($json, true) ?: [] as $v) {
                    if (mb_strtolower(trim((string) $v)) === $l) return true;
                }
            }
        }
        return false;
    }

    /** Rename a SUB — subs live on objekte.art (primary) + objekte/artikel.arten. */
    private function renameSubEverywhere(string $old, string $new): void
    {
        $oldL = mb_strtolower($old);
        $db = DB::connection('tenant');
        $schema = Schema::connection('tenant');
        if ($schema->hasColumn('objekte', 'art')) {
            $db->table('objekte')->whereRaw('LOWER(TRIM(art)) = ?', [$oldL])->update(['art' => $new]);
        }
        foreach ([['objekte', 'obj_id'], ['artikel', 'artikel_id']] as [$tbl, $pk]) {
            if (!$schema->hasTable($tbl) || !$schema->hasColumn($tbl, 'arten')) continue;
            foreach ($db->table($tbl)->whereNotNull('arten')->get([$pk, 'arten']) as $row) {
                $list = json_decode($row->arten, true) ?: [];
                $changed = false;
                foreach ($list as $i => $v) {
                    if (mb_strtolower(trim((string) $v)) === $oldL) {
                        $list[$i] = $new;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $db->table($tbl)->where($pk, $row->$pk)
                        ->update(['arten' => json_encode(array_values($list))]);
                }
            }
        }
    }
}
