<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\BereichScope;
use App\Support\SecurityServices;
use App\Support\TenantProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory + reviewed migration of a tenant towards the security-only model:
 *   - classifies every Hauptbereich as security or legacy (non-security)
 *   - counts what still references each legacy Bereich (no data is moved)
 *   - suggests a security service type per article
 *   - makes every user's access mode explicit (same effective access)
 * apply() only performs reversible changes and returns the previous values
 * of everything it touched. Nothing is deleted.
 */
final class LegacySecurityMigration
{
    private const TAGGED = ['artikel' => 'artikel_id', 'kunden' => 'kunde_id', 'objekte' => 'obj_id',
        'mitarbeiter' => 'dienst_nr', 'nachunternehmer' => 'nu_id'];

    public static function plan(Tenant $tenant): array
    {
        TenantService::assertCurrentMatches((int) $tenant->id);
        $db = DB::connection('tenant');
        $s = Schema::connection('tenant');
        $mains = $s->hasTable('bereiche_katalog')
            ? $db->table('bereiche_katalog')->whereNull('parent_id')->orderBy('id')->get() : collect();

        // name (lowercase) => table => count of rows carrying the tag
        $usage = [];
        foreach (self::TAGGED as $tbl => $pk) {
            if (!$s->hasTable($tbl) || !$s->hasColumn($tbl, 'bereich')) continue;
            $cols = array_values(array_filter([$pk, 'bereich', $s->hasColumn($tbl, 'bereiche') ? 'bereiche' : null]));
            foreach ($db->table($tbl)->get($cols) as $r) {
                foreach (self::tagsOf($r) as $t) $usage[$t][$tbl] = ($usage[$t][$tbl] ?? 0) + 1;
            }
        }

        $hasMode = Schema::connection('mysql')->hasColumn('central_users', 'zugriff_modus');
        $users = DB::connection('mysql')->table('central_users')->where('tenant_id', $tenant->id)
            ->get(array_merge(['id', 'email', 'is_owner', 'role', 'aktiv', 'allowed_bereiche'], $hasMode ? ['zugriff_modus'] : []));

        $out = ['tenant' => ['id' => (int) $tenant->id, 'slug' => $tenant->slug, 'database' => $tenant->database],
            'erstellt_am' => now()->toIso8601String(), 'bereiche' => [], 'artikel' => [], 'benutzer' => [], 'unbekannte_tags' => []];
        $known = [];
        $securityNames = [];
        foreach ($mains as $m) {
            $l = mb_strtolower(trim($m->name));
            $known[] = $l;
            $security = TenantProfile::isSecurityProfile($m->branche ?? null) || (int) ($m->bewachung ?? 0) === 1;
            if ($security) $securityNames[] = $l;
            $row = ['id' => (int) $m->id, 'name' => $m->name, 'branche' => $m->branche ?? null, 'aktiv' => (int) $m->aktiv,
                'klasse' => $security ? 'sicherheit' : 'altbereich', 'verwendung' => $usage[$l] ?? (object) []];
            if (!$security) {
                [$past, $future] = self::einsatzCounts($l);
                $row['einsaetze_vergangen'] = $past;
                $row['einsaetze_offen_zukuenftig'] = $future;
                $row['eingeschraenkte_benutzer'] = $users->filter(fn ($u) => in_array($l, self::listOf($u->allowed_bereiche), true))
                    ->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
            }
            $out['bereiche'][] = $row;
        }
        $out['unbekannte_tags'] = array_values(array_diff(array_keys($usage), $known));

        if (SecurityServices::ready()) {
            $rows = $db->table('artikel as a')->leftJoin('objekte as o', 'o.obj_id', '=', 'a.obj_id')
                ->leftJoin('kunden as k', 'k.kunde_id', '=', 'o.kunden_id')
                ->whereNull('a.leistungsart_id')
                ->get(['a.artikel_id', 'a.artikel_name', 'a.bereich', 'a.aktiv', 'k.bereich as kunden_bereich']);
            foreach ($rows as $a) {
                $eff = mb_strtolower(trim((string) ($a->bereich ?: $a->kunden_bereich)));
                $key = SecurityServices::guess($a->artikel_name, $a->bereich);
                $status = !in_array($eff, $securityNames, true) ? 'altbereich' : ($key ? 'eindeutig' : 'pruefen');
                $out['artikel'][] = ['artikel_id' => $a->artikel_id, 'name' => $a->artikel_name, 'bereich' => $a->bereich ?: $a->kunden_bereich,
                    'vorschlag' => $status === 'eindeutig' ? $key : null, 'status' => $status];
            }
        }

        foreach ($users as $u) {
            $list = self::listOf($u->allowed_bereiche);
            $out['benutzer'][] = ['id' => (int) $u->id, 'email' => $u->email, 'aktiv' => (int) $u->aktiv,
                'umgeht_einschraenkung' => (bool) $u->is_owner || in_array($u->role, ['super_admin', 'tenant_admin'], true),
                'bereiche' => $list, 'zugriff_modus' => $u->zugriff_modus ?? null,
                'zugriff_modus_neu' => ($u->zugriff_modus ?? null) === null && $hasMode ? ($list ? 'bereiche' : 'alle') : null,
                'nur_altbereiche' => $list && !array_intersect($list, $securityNames)];
        }

        $legacy = array_filter($out['bereiche'], fn ($b) => $b['klasse'] === 'altbereich');
        $out['zusammenfassung'] = [
            'sicherheitsbereiche' => count($out['bereiche']) - count($legacy),
            'altbereiche' => count($legacy),
            'altbereiche_aktiv' => count(array_filter($legacy, fn ($b) => $b['aktiv'] === 1)),
            'artikel_vorschlag' => count(array_filter($out['artikel'], fn ($a) => $a['status'] === 'eindeutig')),
            'artikel_pruefen' => count(array_filter($out['artikel'], fn ($a) => $a['status'] === 'pruefen')),
            'artikel_altbereich' => count(array_filter($out['artikel'], fn ($a) => $a['status'] === 'altbereich')),
            'benutzer_modus_offen' => count(array_filter($out['benutzer'], fn ($u) => $u['zugriff_modus_neu'] !== null)),
            'benutzer_nur_altbereiche' => count(array_filter($out['benutzer'], fn ($u) => $u['nur_altbereiche'] && $u['aktiv'] && !$u['umgeht_einschraenkung'])),
        ];
        return $out;
    }

    /**
     * Applies the reversible part of a plan. $archive additionally deactivates
     * legacy Hauptbereiche (+ their Unterbereiche) in the catalog: they leave
     * every picker, while stored data, tags and user whitelists stay intact.
     * Returns the previous values of every touched row.
     */
    public static function apply(Tenant $tenant, array $plan, bool $archive): array
    {
        TenantService::assertCurrentMatches((int) $tenant->id);
        if (($plan['tenant']['id'] ?? null) !== (int) $tenant->id) {
            throw new \RuntimeException('Plan belongs to another tenant');
        }
        $changes = ['benutzer' => [], 'artikel' => [], 'bereiche' => []];
        DB::connection('mysql')->transaction(function () use ($tenant, $plan, $archive, &$changes) {
            DB::connection('tenant')->transaction(function () use ($tenant, $plan, $archive, &$changes) {
                foreach ($plan['benutzer'] as $u) {
                    if ($u['zugriff_modus_neu'] === null) continue;
                    $n = DB::connection('mysql')->table('central_users')->where('tenant_id', $tenant->id)
                        ->where('id', $u['id'])->whereNull('zugriff_modus')->update(['zugriff_modus' => $u['zugriff_modus_neu']]);
                    if ($n) $changes['benutzer'][] = ['id' => $u['id'], 'zugriff_modus_vorher' => null, 'zugriff_modus' => $u['zugriff_modus_neu']];
                }
                foreach ($plan['artikel'] as $a) {
                    if ($a['status'] !== 'eindeutig' || !($id = SecurityServices::idForKey($a['vorschlag']))) continue;
                    $n = DB::connection('tenant')->table('artikel')->where('artikel_id', $a['artikel_id'])
                        ->whereNull('leistungsart_id')->update(['leistungsart_id' => $id]);
                    if ($n) $changes['artikel'][] = ['artikel_id' => $a['artikel_id'], 'leistungsart_id_vorher' => null, 'leistungsart_id' => $id];
                }
                if ($archive) {
                    foreach ($plan['bereiche'] as $b) {
                        if ($b['klasse'] !== 'altbereich') continue;
                        $rows = DB::connection('tenant')->table('bereiche_katalog')->where('aktiv', 1)
                            ->where(fn ($q) => $q->where('id', $b['id'])->orWhere('parent_id', $b['id']))->get(['id', 'name', 'parent_id']);
                        foreach ($rows as $r) {
                            DB::connection('tenant')->table('bereiche_katalog')->where('id', $r->id)->update(['aktiv' => 0, 'updated_at' => now()]);
                            $changes['bereiche'][] = ['id' => (int) $r->id, 'name' => $r->name, 'hauptbereich_id' => $r->parent_id, 'aktiv_vorher' => 1, 'aktiv' => 0];
                        }
                    }
                }
            });
        });
        BereichScope::invalidateStaticCache();
        return $changes;
    }

    /** @return array{0:int,1:int} past shifts, open future shifts of a legacy Bereich */
    private static function einsatzCounts(string $lower): array
    {
        $s = Schema::connection('tenant');
        if (!$s->hasTable('einsaetze')) return [0, 0];
        $expr = 'LOWER(TRIM(COALESCE(' . BereichScope::derivedExprSub('e') . ", '')))";
        $base = fn () => DB::connection('tenant')->table('einsaetze as e')->whereRaw("$expr = ?", [$lower]);
        $future = $base()->where('e.datum', '>=', now('Europe/Berlin')->toDateString());
        if ($s->hasColumn('einsaetze', 'bestell_storno_at')) $future->whereNull('e.bestell_storno_at');
        return [$base()->where('e.datum', '<', now('Europe/Berlin')->toDateString())->count(), $future->count()];
    }

    /** @return array<int,string> lowercased tags of a row (bereich comma list + bereiche JSON) */
    private static function tagsOf(object $r): array
    {
        $tags = array_map('trim', explode(',', (string) ($r->bereich ?? '')));
        if (isset($r->bereiche)) $tags = array_merge($tags, array_filter((array) (json_decode((string) $r->bereiche, true) ?: []), 'is_string'));
        return array_values(array_unique(array_filter(array_map(fn ($v) => mb_strtolower(trim($v)), $tags))));
    }

    /** @return array<int,string> */
    private static function listOf($raw): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        return array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? mb_strtolower(trim($v)) : '', (array) ($list ?: [])))));
    }
}
