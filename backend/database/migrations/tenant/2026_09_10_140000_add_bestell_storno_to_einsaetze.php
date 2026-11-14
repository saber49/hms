<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `bestell_storno_at` to `einsaetze` — a tombstone marker for shifts
 * whose day was removed from the originating Bestelleingang.
 *
 * Previously, when a day was removed from a Bestelleingang, the linked Einsatz
 * was "unlinked" (bestell_id = NULL) if it was planned with workers, or deleted
 * otherwise. The unlinked shift became an orphan in Dispo with no
 * bestell_id and therefore no plan_menge (required worker count), causing
 * the card to always show "partial" stripes even when fully staffed.
 *
 * With this migration:
 *   - Planned shifts (pruefung='geplant' with workers) are LOCKED instead of
 *     unlinked: bestell_id stays, bestell_storno_at is set, the card renders
 *     grayed-out and read-only in Dispo with a "Vom Bestelleingang entfernt"
 *     badge.
 *   - If the same day is re-added to the Bestelleingang later, the locked
 *     shift is reactivated (bestell_storno_at cleared) and re-linked.
 *   - Confirmed shifts (hms_bestaetigt='ja') block day removal entirely.
 *
 * Idempotent — checks for the column before adding.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) {
            return;
        }
        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) {
                $table->timestamp('bestell_storno_at')->nullable()->after('pruefung')
                    ->comment('Set when the day was removed from Bestelleingang; shift is locked (grayed-out, read-only) until the day is re-added.');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) {
            return;
        }
        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) {
                $table->dropColumn('bestell_storno_at');
            }
        });
    }
};
