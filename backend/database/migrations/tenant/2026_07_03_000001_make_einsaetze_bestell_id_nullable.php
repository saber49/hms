<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * einsaetze.bestell_id must be nullable: both the incoming-order sync and the
 * incoming-order delete path "unlink" protected einsätze (confirmed, or
 * planned with assigned workers) by setting bestell_id = NULL so they survive
 * the removal of their parent Bestellung. With a NOT NULL column those paths
 * throw SQLSTATE[23000] (1048) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) return;

        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::connection('tenant')->statement(
                'ALTER TABLE einsaetze MODIFY bestell_id VARCHAR(30) NULL'
            );
        } finally {
            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) return;

        DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::connection('tenant')->statement(
                'ALTER TABLE einsaetze MODIFY bestell_id VARCHAR(30) NOT NULL'
            );
        } finally {
            DB::connection('tenant')->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
};
