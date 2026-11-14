<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds plan-cycle audit timestamps to `einsaetze`:
 *
 *   - `geplant_at`         — stamped every time a shift transitions INTO
 *                            pruefung='geplant' (first publish and every
 *                            re-publish after a withdrawal).
 *   - `plan_aufgehoben_at` — stamped every time a previously 'geplant' shift
 *                            loses its plan (all workers removed via
 *                            "Plan aufheben", dispatcher rejection, NU
 *                            backing out, or the AI tool). Cleared again on
 *                            re-publish so the pair always describes the
 *                            CURRENT cycle.
 *
 * The planner modal shows a notice above the "Zugewiesen" list when
 * plan_aufgehoben_at is set: "veröffentlicht am … / zurückgezogen am …" —
 * so dispatchers can see that the shift once went out to workers and was
 * pulled back, instead of mistaking it for a never-published draft.
 *
 * Idempotent — checks for the columns before adding. All writers guard with
 * hasColumn(), so the feature simply stays inactive until this migration
 * has run on a given tenant.
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
            if (!Schema::connection('tenant')->hasColumn('einsaetze', 'geplant_at')) {
                $table->timestamp('geplant_at')->nullable()
                    ->comment('Last time pruefung transitioned to geplant (plan published to workers).');
            }
            if (!Schema::connection('tenant')->hasColumn('einsaetze', 'plan_aufgehoben_at')) {
                $table->timestamp('plan_aufgehoben_at')->nullable()
                    ->comment('Last time a geplant shift lost its plan (workers removed / rejected / NU withdrawal). Cleared on re-publish.');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) {
            return;
        }
        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            foreach (['geplant_at', 'plan_aufgehoben_at'] as $col) {
                if (Schema::connection('tenant')->hasColumn('einsaetze', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
