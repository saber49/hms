<?php

namespace App\Services\Ai;

/**
 * Maps every AI tool to:
 *   - whether it's a WRITE tool (changes state) or read-only
 *   - the PermissionRegistry key required to call it
 *
 * Read tools default to "no permission required" beyond authentication
 * because the existing AiTools class already scopes everything to the
 * caller's tenant via the DB connection. Write tools MUST have a
 * permission key — if a tool is not listed here as a write, the AI
 * controller will refuse to expose it (defense-in-depth: a forgotten
 * mapping = tool hidden, never accidentally exposed).
 */
final class AiToolPermissions
{
    /**
     * Read-only tools — visible to any authenticated tenant user.
     * The list mirrors AiTools::execute()'s switch statement.
     */
    public const READ_TOOLS = [
        'get_kpi',
        'count_einsaetze',
        'top_kunden',
        'top_objekte',
        'top_nu',
        'search_kunde',
        'search_mitarbeiter',
        'search_objekt',
        'search_artikel',
        'search_bestellung',
        'list_offene_einsaetze',
        'mitarbeiter_count',
        'aktive_bestellungen',
        'monatsabschluss',
        'disposition_uebersicht',
        'bestelleingang_status',
        'bestellungen_status',
        'bestellung_details',
        'mitarbeiter_abwesenheiten',
        'mitarbeiter_arbeitszeit',
        'today_overview',
        'artikel_preise',
        'mitarbeiter_einsaetze',
        'verfuegbare_mitarbeiter',
        'kunde_details',
        'export_einsaetze_csv',
        'export_bestelleingang_csv',
        'search_nu',
        'nu_einsaetze',
        'nu_details',
        'einsatz_details',
        'einsatz_mitarbeiter',
        'zeiterfassung_live',
        'dispo_warnungen',
        'abrechnung_bestellung',
        'abrechnung_monat',
        'system_capabilities',
        'describe_table',
        'query_table',
        'bereich_katalog',
    ];

    /**
     * Write tools — each entry: tool_name => permission_key
     * Permission keys MUST exist in PermissionRegistry::ALL.
     */
    public const WRITE_TOOLS = [
        'create_worker'           => 'stammdaten.mitarbeiter.edit',
        'update_worker'           => 'stammdaten.mitarbeiter.edit',
        'create_abwesenheit'      => 'stammdaten.mitarbeiter.edit',
        'cancel_abwesenheit'      => 'stammdaten.mitarbeiter.edit',
        'create_kunde'            => 'stammdaten.kunden.edit',
        'update_kunde'            => 'stammdaten.kunden.edit',
        'create_objekt'           => 'stammdaten.objekte.edit',
        'update_objekt'           => 'stammdaten.objekte.edit',
        'create_artikel'          => 'stammdaten.objekte.edit',
        'update_artikel'          => 'stammdaten.objekte.edit',
        'create_nu'               => 'stammdaten.nu.edit',
        'update_nu'               => 'stammdaten.nu.edit',
        'create_bestelleingang'   => 'auftraege.eingang.edit',
        'update_anforderungen'    => 'auftraege.eingang.edit',
        'create_bestellung'       => 'auftraege.bestellungen.edit',
        'update_bestellung'       => 'auftraege.bestellungen.edit',
        'create_einsatz'          => 'auftraege.dispo.edit',
        'update_einsatz'          => 'auftraege.dispo.edit',
        'update_einsatz_zeit'     => 'auftraege.dispo.edit',
        'update_mitarbeiter_zeit' => 'auftraege.dispo.edit',
        'update_nu_position_zeit' => 'auftraege.dispo.edit',
        'set_zeit_freigabe'       => 'auftraege.monatsabschluss.edit',
        'bulk_create_einsaetze'   => 'auftraege.dispo.edit',
        'assign_mitarbeiter'      => 'auftraege.dispo.edit',
        'remove_mitarbeiter'      => 'auftraege.dispo.edit',
    ];

    /** True if the named tool changes state (and thus needs confirmation). */
    public static function isWrite(string $toolName): bool
    {
        return array_key_exists($toolName, self::WRITE_TOOLS);
    }

    /** Permission key required, or null for read-only tools. */
    public static function permissionFor(string $toolName): ?string
    {
        return self::WRITE_TOOLS[$toolName] ?? null;
    }

    /**
     * Filter a flat list of tool names to those the user can call.
     * Read tools always pass; write tools require their permission key.
     */
    public static function filterAllowed(array $toolNames, $user): array
    {
        if (!$user) return [];

        return array_values(array_filter($toolNames, function ($name) use ($user) {
            // Read tools — always allowed for authenticated users
            if (in_array($name, self::READ_TOOLS, true)) {
                return true;
            }
            // Write tools — gated on PermissionRegistry key
            $perm = self::WRITE_TOOLS[$name] ?? null;
            if ($perm === null) {
                // Unknown tool name → never expose
                return false;
            }
            return method_exists($user, 'hasPermission') && $user->hasPermission($perm);
        }));
    }
}
