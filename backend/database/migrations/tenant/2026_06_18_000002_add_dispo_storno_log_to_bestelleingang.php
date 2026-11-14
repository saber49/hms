<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `dispo_storno_log` to `bestelleingang` — a JSON array describing each
 * Einsatz deleted from Dispo (einsatz_id, datum, time, deleted_at). It backs
 * the clickable "Aus Dispo gelöscht" badge on the order card, which lists
 * exactly which shifts were removed. Cleared together with the count when the
 * order detail is opened (ack=1).
 *
 * Idempotent — checks for the column before adding.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang')) {
            return;
        }
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_log')) {
                $table->text('dispo_storno_log')->nullable()->after('dispo_storno_at')
                    ->comment('JSON array of Einsätze deleted from Dispo, not yet acknowledged');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang')) {
            return;
        }
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_log')) {
                $table->dropColumn('dispo_storno_log');
            }
        });
    }
};
