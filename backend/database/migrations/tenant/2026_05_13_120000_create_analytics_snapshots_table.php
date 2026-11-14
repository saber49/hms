<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlling / Analytics — gespeicherte Snapshots.
 *
 * Each row is a frozen analytic report at a point in time so the user can:
 *   - revisit a published month-end report without re-running queries,
 *   - compare two saved snapshots side-by-side,
 *   - export historical PDFs.
 *
 * The heavy data (KPIs, breakdowns, trend) is stored as JSON to keep the
 * schema flexible while still allowing JSON_EXTRACT() filtering.
 *
 * Idempotent — safe to run on tenants where the table already exists.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('analytics_snapshots')) {
            return;
        }

        Schema::connection('tenant')->create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('period_type', 20);          // day|week|month|quarter|year|ytd|custom
            $table->date('date_from');
            $table->date('date_bis');
            $table->json('filters')->nullable();         // {kunden_id, obj_id, nu_id, bereich}
            $table->json('kpi');                          // einnahmen, kosten, gewinn, marge, …
            $table->json('breakdowns')->nullable();      // top customers / objects / NUs
            $table->json('trend')->nullable();           // time-series points
            $table->json('forecast')->nullable();        // linear projection
            $table->string('created_by', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['period_type', 'date_from'], 'idx_snap_period');
            $table->index('created_at', 'idx_snap_created');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('analytics_snapshots');
    }
};
