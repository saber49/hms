<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for hot tables.
 *
 * Background:
 *   The Disposition planner, worker chat, time tracking and listen lookups
 *   filter heavily on columns that were not indexed (datum, hms_bestaetigt,
 *   pruefung, kategorie, status, jahr/monat, gelesen, clock_in, ...). With
 *   small tenants this is invisible, but it forces full table scans the
 *   moment any tenant grows past a few thousand rows.
 *
 *   This migration is idempotent (checks information_schema before each
 *   CREATE/DROP) so it is safe to run multiple times and on tenants that
 *   may not yet have all referenced tables.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * List of [table, indexName, "col1, col2, ..."] entries to create.
     * Order matters for compound indexes (most-selective column first).
     */
    private array $indexes = [
        // einsaetze: hottest table - planner filters by date range + status
        ['einsaetze', 'idx_einsaetze_datum',                '`datum`'],
        ['einsaetze', 'idx_einsaetze_hms_bestaetigt',       '`hms_bestaetigt`'],
        ['einsaetze', 'idx_einsaetze_pruefung',             '`pruefung`'],
        ['einsaetze', 'idx_einsaetze_datum_hms',            '`datum`, `hms_bestaetigt`'],

        // listen: only PRIMARY existed - every kategorie lookup was a full scan
        ['listen', 'idx_listen_kategorie',                  '`kategorie`'],
        ['listen', 'idx_listen_kat_sort',                   '`kategorie`, `sortierung`'],

        // bestelleingang: monthly reports + status filtering
        ['bestelleingang', 'idx_be_status',                 '`status`'],
        ['bestelleingang', 'idx_be_jahr_monat',             '`jahr`, `monat`'],

        // bestelleingang_tage: planner JOIN on (eingang_id, datum, tag_nr)
        ['bestelleingang_tage', 'idx_bet_datum',            '`datum`'],
        ['bestelleingang_tage', 'idx_bet_eingang_tag',      '`eingang_id`, `tag_nr`'],

        // bestellungen: status filtering + monthly aggregation
        ['bestellungen', 'idx_bestellungen_status',         '`status`'],
        ['bestellungen', 'idx_bestellungen_jahr_monat',     '`jahr`, `monat`'],

        // mitarbeiter_nachrichten: unread badge is polled constantly
        ['mitarbeiter_nachrichten', 'idx_mn_dienst_gelesen',    '`dienst_nr`, `gelesen`'],
        ['mitarbeiter_nachrichten', 'idx_mn_richtung_gelesen',  '`richtung`, `gelesen`'],
        ['mitarbeiter_nachrichten', 'idx_mn_created',           '`created_at`'],

        // chat_messages: ordered feed per conversation
        ['chat_messages', 'idx_cm_conv_created',            '`conversation_id`, `created_at`'],

        // chat_participants: list conversations for a user
        ['chat_participants', 'idx_cp_user',                '`user_id`'],
        ['chat_participants', 'idx_cp_conv_user',           '`conversation_id`, `user_id`'],

        // zeiterfassung: time-window reports
        ['zeiterfassung', 'idx_zeit_clock_in',              '`clock_in`'],
        ['zeiterfassung', 'idx_zeit_dienst_clockin',        '`dienst_nr`, `clock_in`'],

        // mitarbeiter: worker-app login lookup
        ['mitarbeiter', 'idx_mitarbeiter_app_zugang',       '`app_zugang`'],
        ['mitarbeiter', 'idx_mitarbeiter_email_login',      '`email`, `aktiv`, `app_zugang`'],

        // mitarbeiter_abwesenheiten: end-date filtering for current absences
        ['mitarbeiter_abwesenheiten', 'idx_ma_enddatum',    '`enddatum`'],

        // einsatz_mitarbeiter: aggregation by worker (existing has only dienst_nr alone)
        // composite (dienst_nr, einsatz_id) helps reverse lookups + date joins
        ['einsatz_mitarbeiter', 'idx_em_dienst_einsatz',    '`dienst_nr`, `einsatz_id`'],
    ];

    public function up(): void
    {
        $db = DB::connection('tenant')->getDatabaseName();

        foreach ($this->indexes as [$table, $name, $cols]) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                continue;
            }
            if ($this->indexExists($db, $table, $name)) {
                continue;
            }
            try {
                DB::connection('tenant')->statement(
                    "CREATE INDEX `{$name}` ON `{$table}` ({$cols})"
                );
            } catch (\Throwable $e) {
                // Log and continue - never abort the whole migration on a single
                // bad table (e.g. column missing on legacy tenant).
                logger()->warning('add_performance_indexes: skipped', [
                    'tenant' => $db,
                    'table'  => $table,
                    'index'  => $name,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection('tenant')->getDatabaseName();

        // Drop in reverse so dependent indexes go first
        foreach (array_reverse($this->indexes) as [$table, $name, $cols]) {
            if (!Schema::connection('tenant')->hasTable($table)) {
                continue;
            }
            if (!$this->indexExists($db, $table, $name)) {
                continue;
            }
            try {
                DB::connection('tenant')->statement(
                    "DROP INDEX `{$name}` ON `{$table}`"
                );
            } catch (\Throwable $e) {
                logger()->warning('add_performance_indexes (down): skipped', [
                    'tenant' => $db,
                    'table'  => $table,
                    'index'  => $name,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }

    private function indexExists(string $schema, string $table, string $indexName): bool
    {
        $row = DB::connection('tenant')->selectOne(
            "SELECT COUNT(*) AS c
               FROM information_schema.statistics
              WHERE table_schema = ?
                AND table_name = ?
                AND index_name = ?",
            [$schema, $table, $indexName]
        );
        return $row && (int) $row->c > 0;
    }
};
