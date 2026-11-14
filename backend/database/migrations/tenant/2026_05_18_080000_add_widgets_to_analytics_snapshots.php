<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `heatmap` and `schichtart` JSON columns to `analytics_snapshots` so a
 * saved snapshot is a TRUE frozen report — the user can revisit it later and
 * see the same heatmap + Schicht-Aufschläge that were rendered when the
 * snapshot was taken, instead of having those widgets re-fetched against
 * current (different) data.
 *
 * The original migration already provisioned `forecast`, so we only add the
 * two missing pieces here.
 *
 * Idempotent — every column is checked individually so this migration is
 * safe to re-run on tenants that received a partial schema.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return; // create-table migration not yet applied — nothing to alter.
        }

        Schema::connection('tenant')->table('analytics_snapshots', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('analytics_snapshots', 'heatmap')) {
                // 7×12 (dow × month) grid + period meta. Stored as JSON because
                // the shape mirrors what /api/controlling/heatmap returns and
                // we want the snapshot to be a verbatim copy of that payload.
                $table->json('heatmap')->nullable()->after('forecast');
            }
            if (!Schema::connection('tenant')->hasColumn('analytics_snapshots', 'schichtart')) {
                // Per-Schichtart rows (Tagdienst, Nachtdienst, Sonntag, …) —
                // a flat array, mirrors /api/controlling/breakdown/schichtart.
                $table->json('schichtart')->nullable()->after('heatmap');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return;
        }
        Schema::connection('tenant')->table('analytics_snapshots', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('analytics_snapshots', 'schichtart')) {
                $table->dropColumn('schichtart');
            }
            if (Schema::connection('tenant')->hasColumn('analytics_snapshots', 'heatmap')) {
                $table->dropColumn('heatmap');
            }
        });
    }
};
