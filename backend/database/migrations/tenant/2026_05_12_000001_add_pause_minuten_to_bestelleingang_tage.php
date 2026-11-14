<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the missing pause_minuten column to bestelleingang_tage.
 *
 * Background:
 *   The frontend Bestelleingang wizard sends `pause_minuten` (the actual
 *   worker break used to compute soll_std), but the original schema only
 *   stored `gesetzl_pause` (legal break). The IncomingOrderController was
 *   silently dropping the field on save, leading to data loss and the
 *   inability to reconstruct the original soll_std on edit.
 *
 *   The corresponding column already exists on the `einsaetze` table.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang_tage')) {
            return;
        }
        if (Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'pause_minuten')) {
            return;
        }

        Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
            $table->integer('pause_minuten')->nullable()->after('soll_std');
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang_tage')) {
            return;
        }
        if (!Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'pause_minuten')) {
            return;
        }

        Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
            $table->dropColumn('pause_minuten');
        });
    }
};
