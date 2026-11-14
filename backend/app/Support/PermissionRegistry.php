<?php

namespace App\Support;

/**
 * Single source of truth for the application's permission keys.
 *
 * IMPORTANT: A permission key is just a string — there is no magic. Any
 * page or API endpoint that wants to gate access must use exactly one of
 * the keys listed in self::ALL. Adding a new feature? Add the key here,
 * apply the 'permission' middleware to its routes, then update the
 * frontend gating in app.js.
 *
 * All permission storage and checking is per-user, per-tenant. A tenant's
 * permissions never leak to another tenant — central_users.tenant_id is
 * the hard boundary.
 */
final class PermissionRegistry
{
    /**
     * Every known permission key, grouped by feature area.
     * Each entry: [key, label]
     */
    public const GROUPS = [
        'Dashboard' => [
            ['dashboard.view',                  'Übersicht ansehen'],
        ],
        // Lead-Modul, one row per section. Einstellungen (Listen, Fristen,
        // Zuweisung, Webformular), Löschen und Export are NOT grantable —
        // they belong to the Inhaber / Administratoren (DERIVED below).
        'Vertrieb' => [
            ['crm.leads.view',                     'Leads ansehen'],
            ['crm.leads.edit',                     'Leads bearbeiten'],
            ['crm.deals.view',                     'Deals ansehen'],
            ['crm.deals.edit',                     'Deals bearbeiten'],
            ['crm.kontakte.view',                  'Firmen & Kontakte ansehen'],
            ['crm.kontakte.edit',                  'Firmen & Kontakte bearbeiten'],
            ['stammdaten.interessenten.view_all',  'Vertriebsleitung: alle Leads & Deals, Owner umverteilen'],
            ['stammdaten.interessenten.uebergabe', 'Objektleitung: gewonnene Deals übernehmen'],
        ],
        'Stammdaten' => [
            ['stammdaten.kunden.view',          'Kunden ansehen'],
            ['stammdaten.kunden.edit',          'Kunden bearbeiten'],
            ['stammdaten.objekte.view',         'Objekte & Artikel ansehen'],
            ['stammdaten.objekte.edit',         'Objekte & Artikel bearbeiten'],
            ['stammdaten.preise.view',          'Preishistorie ansehen'],
            ['stammdaten.lager.view',           'Lager & Ausrüstung ansehen'],
            ['stammdaten.lager.edit',           'Lager & Ausrüstung bearbeiten & ausgeben'],
            ['controlling.view',                'Auswertung & Controlling ansehen'],
            ['controlling.export',              'Controlling-Daten exportieren'],
        ],
        'Personal' => [
            ['stammdaten.mitarbeiter.view',     'Mitarbeiter ansehen'],
            ['stammdaten.mitarbeiter.edit',     'Mitarbeiter bearbeiten'],
            ['stammdaten.nu.view',              'Nachunternehmer ansehen'],
            ['stammdaten.nu.edit',              'Nachunternehmer bearbeiten'],
        ],
        'Disposition' => [
            ['auftraege.dispo.view',            'Planung ansehen'],
            ['auftraege.dispo.edit',            'Planung bearbeiten'],
            ['auftraege.monatsabschluss.view',  'Zeiterfassung ansehen'],
            ['auftraege.monatsabschluss.edit',  'Zeiterfassung bearbeiten'],
        ],
        'Verkauf' => [
            ['auftraege.eingang.view',          'Bestelleingang ansehen'],
            ['auftraege.eingang.edit',          'Bestelleingang bearbeiten'],
            ['auftraege.bestaetigung.view',     'Auftragsbestätigungen ansehen'],
            ['auftraege.bestaetigung.edit',     'Auftragsbestätigungen bearbeiten'],
            ['auftraege.bestellungen.view',     'Bestellungen ansehen'],
            ['auftraege.bestellungen.edit',     'Bestellungen bearbeiten'],
        ],
        'Kommunikation' => [
            ['auftraege.nachrichten.view',      'MA-Nachrichten ansehen'],
            ['auftraege.nachrichten.send',      'MA-Nachrichten senden'],
        ],
        // Qualitätsmanagement & Wachbuch. What each role may do in detail is
        // the Rechte-Tabelle in QM-Einstellungen (App\Support\Qm\QmRechte).
        'Qualität' => [
            ['qm.qmb',                          'QMB: Qualitätsmanagement komplett (Freigaben, Abschluss, Einstellungen)'],
            ['qm.buero',                        'QM Büro: alle Objekte, Reklamationen erfassen & beantworten'],
            ['qm.objektleitung',                'Objektleiter: nur zuständige Objekte (Wachbuch, Dienstanweisung, Reklamationen)'],
        ],
        'System' => [
            ['users.manage',                    'Benutzer & Berechtigungen verwalten'],
            ['system.dokument_design.manage',   'Dokumentvorlagen verwalten'],
        ],
    ];

    /**
     * Keys that only the company owner (Inhaber) may ever hold. They are
     * stripped from every other account's effective permissions — even an
     * Administrator with the '*' preset or an explicit list containing them
     * — and are not offered in the permission picker.
     *
     * Currently empty: `users.manage` became a grantable permission so the
     * owner can delegate user administration to an admin. The owner account
     * itself stays protected by the hard guards in UserController
     * (immutable for others, undeletable, no owner-role assignment, and a
     * user-admin cannot change his own role/permissions/aktiv flag).
     */
    public const OWNER_ONLY = [];

    /**
     * Keys only the Inhaber may GRANT (Qualitätsmanagement). They are never
     * part of a role preset, custom role or stored permission list —
     * administrators do not get them with "Vollzugriff". The owner grants
     * them per user (central_users.qm_rollen); see CentralUser.
     */
    public const OWNER_GRANT = ['qm.qmb', 'qm.buero', 'qm.objektleitung'];

    /**
     * Role presets: each role maps to a fixed list of permission keys,
     * or '*' for "all permissions".
     */
    public const PRESETS = [
        'owner'    => '*',
        'admin'    => '*',
        'manager'  => [
            'dashboard.view',
            'crm.leads.view', 'crm.leads.edit', 'crm.deals.view', 'crm.deals.edit',
            'crm.kontakte.view', 'crm.kontakte.edit', 'stammdaten.interessenten.view_all',
            'stammdaten.kunden.view',
            'stammdaten.objekte.view',
            'stammdaten.nu.view',
            'stammdaten.lager.view', 'stammdaten.lager.edit',
            'stammdaten.preise.view',
            'stammdaten.mitarbeiter.view', 'stammdaten.mitarbeiter.edit',
            'auftraege.eingang.view',      'auftraege.eingang.edit',
            'auftraege.bestaetigung.view', 'auftraege.bestaetigung.edit',
            'auftraege.dispo.view',        'auftraege.dispo.edit',
            'auftraege.monatsabschluss.view', 'auftraege.monatsabschluss.edit',
            'auftraege.bestellungen.view', 'auftraege.bestellungen.edit',
            'auftraege.nachrichten.view',  'auftraege.nachrichten.send',
            'controlling.view',            'controlling.export',
        ],
        'dispo'    => [
            'dashboard.view',
            'auftraege.dispo.view',        'auftraege.dispo.edit',
            'auftraege.eingang.view',
            'auftraege.bestaetigung.view',
            'auftraege.nachrichten.view',  'auftraege.nachrichten.send',
            'stammdaten.kunden.view',
            'stammdaten.objekte.view',
            'stammdaten.mitarbeiter.view',
        ],
        'buchhaltung' => [
            'dashboard.view',
            'stammdaten.kunden.view',
            'stammdaten.objekte.view',
            'stammdaten.preise.view',
            'auftraege.bestellungen.view',
            'auftraege.bestaetigung.view', 'auftraege.bestaetigung.edit',
            'auftraege.monatsabschluss.view',
            'controlling.view', 'controlling.export',
        ],
        'viewer'   => [
            'dashboard.view',
            'crm.leads.view', 'crm.deals.view', 'crm.kontakte.view',
            'stammdaten.kunden.view',
            'stammdaten.objekte.view',
            'stammdaten.nu.view',
            'stammdaten.lager.view',
            'stammdaten.preise.view',
            'stammdaten.mitarbeiter.view',
            'auftraege.eingang.view',
            'auftraege.bestaetigung.view',
            'auftraege.dispo.view',
            'auftraege.monatsabschluss.view',
            'auftraege.bestellungen.view',
            'auftraege.nachrichten.view',
            'controlling.view',
        ],
        'custom'   => [], // permissions explicitly supplied by the owner
    ];

    /**
     * Human-friendly preset labels for the UI.
     */
    public const PRESET_LABELS = [
        'owner'       => 'Inhaber (Vollzugriff)',
        'admin'       => 'Administrator (Vollzugriff)',
        'manager'     => 'Manager',
        'dispo'       => 'Disponent',
        'buchhaltung' => 'Buchhaltung',
        'viewer'      => 'Viewer (nur lesen)',
        'custom'      => 'Benutzerdefiniert',
    ];

    /**
     * Flatten all known permission keys.
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::GROUPS as $perms) {
            foreach ($perms as [$key, $_]) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Map preset name → resolved permission list ('*' is expanded).
     */
    public static function permissionsForPreset(string $preset): array
    {
        $p = self::PRESETS[$preset] ?? [];
        if ($p === '*') return self::all();
        return is_array($p) ? $p : [];
    }

    /**
     * Sanitise a list of permission keys — drop unknown / non-string entries.
     *
     * Returns the input keys (deduplicated, in insertion order) intersected
     * with the registry. Used everywhere we accept a permissions array from
     * the wire so a stale or hand-crafted client cannot smuggle unknown
     * keys into the database.
     */
    public static function filterValid(array $keys): array
    {
        $known = array_flip(self::all());
        $out = [];
        foreach (self::translateLegacy($keys) as $k) {
            if (is_string($k) && isset($known[$k]) && !in_array($k, $out, true) && !in_array($k, self::OWNER_GRANT, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * Stored lists from before the per-section Vertrieb rights: the old
     * all-in-one keys expand to the three sections (same effective access).
     * The old `.admin` key is dropped — admin is derived from the role now.
     */
    public const LEGACY = [
        'stammdaten.interessenten.view'  => ['crm.leads.view', 'crm.deals.view', 'crm.kontakte.view'],
        'stammdaten.interessenten.edit'  => ['crm.leads.edit', 'crm.deals.edit', 'crm.kontakte.edit'],
        'stammdaten.interessenten.admin' => [],
    ];

    /** Section edit implies section view. */
    private const IMPLIES = [
        'crm.leads.edit' => 'crm.leads.view',
        'crm.deals.edit' => 'crm.deals.view',
        'crm.kontakte.edit' => 'crm.kontakte.view',
    ];

    public static function translateLegacy(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k)) continue;
            foreach (self::LEGACY[$k] ?? [$k] as $x) {
                $out[] = $x;
                if (isset(self::IMPLIES[$x])) $out[] = self::IMPLIES[$x];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Keys that are never stored or granted, only derived, so every
     * existing gate keeps working:
     *   stammdaten.interessenten.view  = any Vertrieb section visible
     *   stammdaten.interessenten.edit  = any Vertrieb section editable
     *   stammdaten.interessenten.admin = Inhaber / Administrator only
     *     (Vertrieb-Einstellungen, Löschen, Export, DSGVO-Export).
     *   qm.view = any Qualität role (QMB / Büro / Objektleiter)
     */
    public static function withDerived(array $perms, bool $adminLevel): array
    {
        $has = array_flip($perms);
        $extra = [];
        if (isset($has['crm.leads.view']) || isset($has['crm.deals.view']) || isset($has['crm.kontakte.view'])) {
            $extra[] = 'stammdaten.interessenten.view';
        }
        if (isset($has['crm.leads.edit']) || isset($has['crm.deals.edit']) || isset($has['crm.kontakte.edit'])) {
            $extra[] = 'stammdaten.interessenten.edit';
        }
        if ($adminLevel) $extra[] = 'stammdaten.interessenten.admin';
        // qm.view = any QM role (page + read gate; object scope in QmScope).
        if (isset($has['qm.qmb']) || isset($has['qm.buero']) || isset($has['qm.objektleitung'])) {
            $extra[] = 'qm.view';
        }
        return array_values(array_unique(array_merge($perms, $extra)));
    }

    /**
     * Get the flat group structure for the UI builder.
     * Returns: [{group, items:[{key,label}]}]
     */
    public static function asUiTree(): array
    {
        $tree = [];
        foreach (self::GROUPS as $group => $perms) {
            $items = [];
            foreach ($perms as [$key, $label]) {
                if (in_array($key, self::OWNER_ONLY, true) || in_array($key, self::OWNER_GRANT, true)) continue;
                $items[] = ['key' => $key, 'label' => $label];
            }
            if ($items) $tree[] = ['group' => $group, 'items' => $items];
        }
        return $tree;
    }
}
