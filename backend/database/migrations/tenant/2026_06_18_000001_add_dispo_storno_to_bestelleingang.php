<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a "shift deleted from Dispo" marker to `bestelleingang`.
 *
 * When a planner deletes an Einsatz in the Dispo section, the originating
 * Bestelleingang (order) is flagged so the back-office sees a badge on the
 * order card in Bestellungseingang. `dispo_storno_count` lets multiple
 * deletions accumulate; `dispo_storno_at` carries the most recent timestamp.
 * The badge is cleared when the order detail is opened (see
 * IncomingOrderController::show).
 *
 * Idempotent — checks for the columns before adding so re-running on
 * already-migrated tenants is a no-op.
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
            if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_count')) {
                $table->unsignedInteger('dispo_storno_count')->default(0)->after('status')
                    ->comment('Number of Einsätze deleted from Dispo for this order, not yet acknowledged');
            }
            if (!Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_at')) {
                $table->timestamp('dispo_storno_at')->nullable()->after('dispo_storno_count')
                    ->comment('Timestamp of the most recent Dispo deletion');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('bestelleingang')) {
            return;
        }
        Schema::connection('tenant')->table('bestelleingang', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_at')) {
                $table->dropColumn('dispo_storno_at');
            }
            if (Schema::connection('tenant')->hasColumn('bestelleingang', 'dispo_storno_count')) {
                $table->dropColumn('dispo_storno_count');
            }
        });
    }
};
