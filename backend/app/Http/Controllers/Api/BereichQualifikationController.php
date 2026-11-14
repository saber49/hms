<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualifikationen-Katalog (tenant table `bereich_qualifikationen`), maintained
 * by hand per Hauptbereich in Konto & Firma → Bereiche:
 *   list   (parent_id NULL, bereich_id = main)  e.g. "§34a"
 *   option (parent_id = list id)               e.g. "Sachkunde"
 * Reading happens through GET /bereich-catalog (lists ride on each main).
 * Write rights + Bereich scope mirror BereichCatalogController.
 */
class BereichQualifikationController extends Controller
{
    public function store(Request $request)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        $name = trim((string) $request->input('name'));
        if ($resp = $this->invalidName($name)) return $resp;

        $db = DB::connection('tenant');
        $hasTyp = self::hasTyp();
        $parentId = $request->input('parent_id');
        if ($parentId !== null) {
            $list = $db->table('bereich_qualifikationen')->where('id', (int) $parentId)->whereNull('parent_id')->first();
            if (!$list) {
                return response()->json(['error' => 'Liste nicht gefunden'], 404);
            }
            $bereichId = (int) $list->bereich_id;
            $typ = $list->typ ?? self::TYP_QUAL;
        } else {
            $bereichId = (int) $request->input('bereich_id');
            $main = $db->table('bereiche_katalog')->where('id', $bereichId)->whereNull('parent_id')->first();
            if (!$main) {
                return response()->json(['error' => 'Hauptbereich nicht gefunden'], 404);
            }
            $typ = (string) $request->input('typ', self::TYP_QUAL);
            if (!in_array($typ, [self::TYP_QUAL, self::TYP_KLEIDUNG], true)) {
                return response()->json(['error' => 'Ungültiger Typ'], 422);
            }
            if ($typ !== self::TYP_QUAL && !$hasTyp) {
                return response()->json(['error' => 'Kleidung noch nicht verfügbar (Migration ausstehend)'], 409);
            }
        }
        if ($resp = $this->scopeCheck($request->user(), $bereichId)) return $resp;

        $dup = $db->table('bereich_qualifikationen')
            ->where('bereich_id', $bereichId)
            ->where('parent_id', $parentId === null ? null : (int) $parentId)
            ->when($hasTyp, fn ($q) => $q->where('typ', $typ))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($dup) {
            return response()->json(['error' => 'Name existiert bereits'], 422);
        }

        $row = [
            'bereich_id' => $bereichId,
            'parent_id'  => $parentId === null ? null : (int) $parentId,
            'name'       => $name,
            'aktiv'      => 1,
            'sortierung' => (int) $request->input('sortierung', 0),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($hasTyp) {
            $row['typ'] = $typ;
        }
        $id = $db->table('bereich_qualifikationen')->insertGetId($row);
        \App\Helpers\Realtime::changed('bereiche');
        return response()->json(['success' => true, 'id' => $id], 201);
    }

    public function update(Request $request, int $id)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        $db = DB::connection('tenant');
        $row = $db->table('bereich_qualifikationen')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        if ($resp = $this->scopeCheck($request->user(), (int) $row->bereich_id)) return $resp;

        $update = [];
        if (array_key_exists('name', $request->all())) {
            $name = trim((string) $request->input('name'));
            if ($resp = $this->invalidName($name)) return $resp;
            $dup = $db->table('bereich_qualifikationen')
                ->where('bereich_id', $row->bereich_id)->where('parent_id', $row->parent_id)
                ->when(isset($row->typ), fn ($q) => $q->where('typ', $row->typ))
                ->where('id', '!=', $id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
            if ($dup) {
                return response()->json(['error' => 'Name existiert bereits'], 422);
            }
            $update['name'] = $name;
        }
        foreach (['aktiv', 'sortierung'] as $f) {
            if (array_key_exists($f, $request->all())) {
                $update[$f] = (int) $request->input($f);
            }
        }
        if ($update) {
            $update['updated_at'] = now();
            $db->table('bereich_qualifikationen')->where('id', $id)->update($update);
            \App\Helpers\Realtime::changed('bereiche');
        }
        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, int $id)
    {
        if ($resp = $this->requireManage($request)) return $resp;
        $db = DB::connection('tenant');
        $row = $db->table('bereich_qualifikationen')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        if ($resp = $this->scopeCheck($request->user(), (int) $row->bereich_id)) return $resp;

        if ($row->parent_id === null) {
            if ($db->table('bereich_qualifikationen')->where('parent_id', $id)->exists()) {
                return response()->json(['error' => 'Liste enthält noch Einträge — zuerst löschen'], 409);
            }
        } else {
            $used = self::workersWithOption($id);
            if ($used > 0) {
                return response()->json([
                    'error' => "Eintrag ist bei {$used} Mitarbeiter" . ($used === 1 ? '' : 'n') . ' hinterlegt und kann nicht gelöscht werden — stattdessen deaktivieren',
                ], 409);
            }
        }
        $db->table('bereich_qualifikationen')->where('id', $id)->delete();
        \App\Helpers\Realtime::changed('bereiche');
        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    public const TYP_QUAL = 'qualifikation';
    public const TYP_KLEIDUNG = 'kleidung';

    public static function hasTyp(): bool
    {
        return Schema::connection('tenant')->hasColumn('bereich_qualifikationen', 'typ');
    }

    /**
     * Lists (+ options) grouped by main id — used by the catalog index.
     * $typ null = all types (e.g. main-delete guard).
     */
    public static function listsByMain(?string $typ = self::TYP_QUAL): array
    {
        if (!Schema::connection('tenant')->hasTable('bereich_qualifikationen')) {
            return [];
        }
        $hasTyp = self::hasTyp();
        if ($typ !== null && $typ !== self::TYP_QUAL && !$hasTyp) {
            return [];
        }
        $rows = DB::connection('tenant')->table('bereich_qualifikationen')
            ->when($typ !== null && $hasTyp, fn ($q) => $q->where('typ', $typ))
            ->orderBy('sortierung')->orderBy('name')->get();
        $lists = [];
        foreach ($rows as $r) {
            if ($r->parent_id === null) {
                $lists[$r->id] = ['id' => $r->id, 'bereich_id' => (int) $r->bereich_id, 'name' => $r->name, 'aktiv' => (int) $r->aktiv, 'options' => []];
            }
        }
        foreach ($rows as $r) {
            if ($r->parent_id !== null && isset($lists[$r->parent_id])) {
                $lists[$r->parent_id]['options'][] = ['id' => $r->id, 'name' => $r->name, 'aktiv' => (int) $r->aktiv];
            }
        }
        $byMain = [];
        foreach ($lists as $l) {
            $byMain[$l['bereich_id']][] = $l;
        }
        return $byMain;
    }

    /** Option id → main id, for validating/scoping worker selections. */
    public static function optionMainMap(): array
    {
        if (!Schema::connection('tenant')->hasTable('bereich_qualifikationen')) {
            return [];
        }
        return DB::connection('tenant')->table('bereich_qualifikationen')
            ->whereNotNull('parent_id')
            ->when(self::hasTyp(), fn ($q) => $q->where('typ', self::TYP_QUAL))
            ->pluck('bereich_id', 'id')
            ->map(fn ($v) => (int) $v)->all();
    }

    public static function workersWithOption(int $optionId): int
    {
        $schema = Schema::connection('tenant');
        if (!$schema->hasTable('mitarbeiter') || !$schema->hasColumn('mitarbeiter', 'qualifikationen')) {
            return 0;
        }
        return DB::connection('tenant')->table('mitarbeiter')
            ->whereNotNull('qualifikationen')
            ->whereRaw('JSON_CONTAINS(qualifikationen, ?)', [json_encode($optionId)])
            ->count();
    }

    private function invalidName(string $name)
    {
        if ($name === '') {
            return response()->json(['error' => 'Name ist erforderlich'], 422);
        }
        if (mb_strlen($name) > 190) {
            return response()->json(['error' => 'Ungültiger Name (max. 190 Zeichen)'], 422);
        }
        return null;
    }

    /** Restricted managers may only edit lists of their own mains. */
    private function scopeCheck($user, int $bereichId)
    {
        $allowed = BereichScope::for($user);
        if ($allowed === null) {
            return null;
        }
        $main = DB::connection('tenant')->table('bereiche_katalog')->where('id', $bereichId)->first();
        return ($main && BereichScope::listAllows($allowed, $main->name))
            ? null
            : response()->json(['error' => 'Kein Zugriff auf diesen Bereich'], 403);
    }

    private function requireManage(Request $request)
    {
        if (!Schema::connection('tenant')->hasTable('bereich_qualifikationen')) {
            return response()->json(['error' => 'Qualifikationen noch nicht verfügbar (Migration ausstehend)'], 409);
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
}
