<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable key between a Bestelleingang day row and its Einsatz.
 *
 * tag_nr is the row POSITION in the wizard (renumbered on every save), so
 * day row ↔ Einsatz matching had to guess by date/time/service. tag_uid is
 * a UUID that a day row keeps across saves and that its Einsatz carries:
 *   - bestelleingang_tage.tag_uid
 *   - einsaetze.tag_uid
 * Backfill: every existing day row gets a UUID; Einsätze inherit it through
 * the same datum + tag_nr pairing the Dispo join uses. Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $db = DB::connection($this->connection);
        if (!$s->hasTable('bestelleingang_tage') || !$s->hasTable('einsaetze')) return;

        if (!$s->hasColumn('bestelleingang_tage', 'tag_uid')) {
            $s->table('bestelleingang_tage', function (Blueprint $table) {
                $table->string('tag_uid', 36)->nullable();
                $table->index('tag_uid', 'idx_bt_tag_uid');
            });
        }
        if (!$s->hasColumn('einsaetze', 'tag_uid')) {
            $s->table('einsaetze', function (Blueprint $table) {
                $table->string('tag_uid', 36)->nullable();
                $table->index('tag_uid', 'idx_e_tag_uid');
            });
        }

        $db->statement('UPDATE bestelleingang_tage SET tag_uid = LOWER(UUID()) WHERE tag_uid IS NULL');

        // Einsatz with tag_nr → the day row of the same order, date and tag_nr.
        $db->statement('
            UPDATE einsaetze e
              JOIN bestellungen b ON b.bestell_id = e.bestell_id
              JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum AND d.tag_nr = e.tag_nr
               SET e.tag_uid = d.tag_uid
             WHERE e.tag_uid IS NULL AND e.tag_nr IS NOT NULL');

        // Legacy Einsatz without tag_nr → the lowest tag_nr of that date
        // (identical to the fallback of the Dispo / Abrechnung joins).
        $db->statement('
            UPDATE einsaetze e
              JOIN bestellungen b ON b.bestell_id = e.bestell_id
              JOIN (SELECT eingang_id, datum, MIN(tag_nr) AS tag_nr FROM bestelleingang_tage GROUP BY eingang_id, datum) m
                ON m.eingang_id = b.eingang_id AND m.datum = e.datum
              JOIN bestelleingang_tage d ON d.eingang_id = m.eingang_id AND d.datum = m.datum AND d.tag_nr = m.tag_nr
               SET e.tag_uid = d.tag_uid
             WHERE e.tag_uid IS NULL AND e.tag_nr IS NULL');
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['einsaetze' => 'idx_e_tag_uid', 'bestelleingang_tage' => 'idx_bt_tag_uid'] as $table => $idx) {
            if ($s->hasTable($table) && $s->hasColumn($table, 'tag_uid')) {
                $s->table($table, function (Blueprint $t) use ($idx) {
                    $t->dropIndex($idx);
                    $t->dropColumn('tag_uid');
                });
            }
        }
    }
};
