<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Industry profile of a tenant (tenants.settings.branche) and the labels /
 * switches derived from it.
 *
 * Tenants created before the profile existed have no `branche` and keep the
 * historic behaviour (e.g. the shift log is called "Wachbuch").
 */
class TenantProfile
{
    public const INDUSTRY = 'sicherheitsdienst';
    public const SECURITY_PROFILES = [self::INDUSTRY, 'veranstaltungsdienst'];

    public static function isSecurityProfile(mixed $key): bool
    {
        return is_string($key) && in_array($key, self::SECURITY_PROFILES, true);
    }

    /**
     * Registration choices. `bereich` = main Bereich created for a new tenant
     * (null = none), `bewachung` / `stadiondienst` = switches on that Bereich,
     * `objektbuch` = default name of the shift log.
     */
    public const BRANCHEN = [
        'gebaeudereinigung'     => ['label' => 'Gebäudereinigung',              'bereich' => 'Reinigung',                   'bewachung' => false, 'stadiondienst' => false, 'objektbuch' => 'Objektbuch'],
        'sicherheitsdienst'     => ['label' => 'Sicherheitsdienst',             'bereich' => 'Sicherheit',                  'bewachung' => true,  'stadiondienst' => false, 'objektbuch' => 'Wachbuch'],
        'veranstaltungsdienst'  => ['label' => 'Veranstaltungs- & Ordnerdienst','bereich' => 'Veranstaltungsdienst',        'bewachung' => true,  'stadiondienst' => true,  'objektbuch' => 'Wachbuch'],
        'personaldienstleistung'=> ['label' => 'Personaldienstleistung / Zeitarbeit', 'bereich' => 'Personaldienstleistung', 'bewachung' => false, 'stadiondienst' => false, 'objektbuch' => 'Schichtbuch'],
        'facility_management'   => ['label' => 'Facility Management',           'bereich' => 'Facility Management',         'bewachung' => false, 'stadiondienst' => false, 'objektbuch' => 'Objektbuch'],
        'gartenpflege'          => ['label' => 'Garten- & Landschaftspflege',   'bereich' => 'Garten- & Landschaftspflege', 'bewachung' => false, 'stadiondienst' => false, 'objektbuch' => 'Objektbuch'],
        'hauswirtschaft'        => ['label' => 'Hauswirtschaft & Alltagshilfe', 'bereich' => 'Hauswirtschaft',              'bewachung' => false, 'stadiondienst' => false, 'objektbuch' => 'Objektbuch'],
        'sonstiges'             => ['label' => 'Sonstiges',                     'bereich' => null,                          'bewachung' => false, 'stadiondienst' => false, 'objektbuch' => 'Objektbuch'],
    ];

    public static function branche(?Tenant $tenant): ?string
    {
        $b = $tenant ? data_get($tenant->settings, 'branche') : null;
        return is_string($b) && isset(self::BRANCHEN[$b]) ? $b : null;
    }

    /** Custom name > industry default > "Wachbuch" (tenants without profile). */
    public static function objektbuchLabel(?Tenant $tenant): string
    {
        $custom = $tenant ? trim((string) data_get($tenant->settings, 'objektbuch_label', '')) : '';
        return $custom !== '' ? $custom : self::defaultObjektbuchLabel($tenant);
    }

    public static function defaultObjektbuchLabel(?Tenant $tenant): string
    {
        $b = self::branche($tenant);
        return $b ? self::BRANCHEN[$b]['objektbuch'] : 'Wachbuch';
    }

    /**
     * ArbZG planning hints (Dispo warnings). settings.arbzg = {aktiv, max_tag, ruhezeit}.
     * Default: on for tenants with an industry profile (new sign-ups), off for
     * older tenants so their planner looks exactly as before until enabled.
     * Limits are adjustable (collective agreements, § 7 ArbZG — e.g. 12 h days).
     */
    public static function arbzg(?Tenant $tenant): array
    {
        $cfg = $tenant ? data_get($tenant->settings, 'arbzg') : null;
        $cfg = is_array($cfg) ? $cfg : [];
        return [
            'aktiv'    => array_key_exists('aktiv', $cfg) ? (bool) $cfg['aktiv'] : self::branche($tenant) !== null,
            'max_tag'  => (float) ($cfg['max_tag'] ?? 10),
            'ruhezeit' => (float) ($cfg['ruhezeit'] ?? 11),
        ];
    }

    /**
     * Security switches of the ACTIVE tenant (any main Bereich with
     * bewachung / stadiondienst). Requires the tenant connection.
     */
    public static function securityFlags(): array
    {
        $flags = ['bewachung' => false, 'stadiondienst' => false];
        $s = Schema::connection('tenant');
        if (!$s->hasTable('bereiche_katalog') || !$s->hasColumn('bereiche_katalog', 'bewachung')) return $flags;
        $q = DB::connection('tenant')->table('bereiche_katalog')->whereNull('parent_id');
        if ($s->hasColumn('bereiche_katalog', 'aktiv')) $q->where('aktiv', 1);
        $row = $q->selectRaw('MAX(bewachung) AS bew, MAX(stadiondienst) AS std')->first();
        $flags['stadiondienst'] = (bool) ($row->std ?? 0);
        $flags['bewachung'] = $flags['stadiondienst'] || (bool) ($row->bew ?? 0);
        return $flags;
    }

    /** Creates the industry's main Bereich in a freshly provisioned tenant DB. */
    public static function seedBereich(string $branche): void
    {
        if (!self::isSecurityProfile($branche)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['branche' => 'Nur Sicherheitsdienstleistungen sind verfügbar.']);
        }
        \App\Services\TenantService::assertActive();
        $p = self::BRANCHEN[$branche] ?? null;
        if (!$p || !$p['bereich']) return;
        $s = Schema::connection('tenant');
        if (!$s->hasTable('bereiche_katalog')) return;
        $db = DB::connection('tenant');
        $id = $db->table('bereiche_katalog')->whereNull('parent_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($p['bereich'])])->value('id');
        if (!$id) {
            $row = ['name' => $p['bereich'], 'parent_id' => null, 'aktiv' => 1, 'sortierung' => 0, 'created_at' => now(), 'updated_at' => now()];
            if ($s->hasColumn('bereiche_katalog', 'bewachung')) {
                $row['bewachung'] = $p['bewachung'] ? 1 : 0;
                $row['stadiondienst'] = $p['stadiondienst'] ? 1 : 0;
            }
            $id = $db->table('bereiche_katalog')->insertGetId($row);
            // Ready-made Einsatzarten, each mapped to its platform service type.
            SecurityServices::seedStarterEinsatzarten((int) $id);
        }
        // Industry + starter pack (Objektarten, Qualifikations-/Kleidungs-Listen).
        Branchen::applyPaket((int) $id, $branche);
    }
}
