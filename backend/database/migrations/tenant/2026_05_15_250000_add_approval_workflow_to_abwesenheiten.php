<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval workflow for mitarbeiter_abwesenheiten + a dedicated channel
 * for sending notifications back to the worker (worker_notifications).
 *
 * Design choices:
 *  - `status` defaults to 'genehmigt' so EVERY existing row (and any future
 *    row created directly by an admin via the web Mitarbeiter tab) keeps
 *    its current semantics. Only the worker app store() path overrides it
 *    to 'pending'.
 *  - `entschieden_von` is a plain unsigned bigint — references central_users.id
 *    on the central DB, no FK because of cross-connection storage.
 *  - `worker_notifications` mirrors the manager-facing `notifications` table
 *    but is keyed by mitarbeiter.id (per-tenant), with the same gelesen +
 *    payload structure so the worker app can render a unified feed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $t) {
            // ENUM via raw because doctrine/dbal isn't bundled.
            $t->string('status', 20)->default('genehmigt')->after('typ');
            $t->unsignedBigInteger('entschieden_von')->nullable()->after('beschreibung');
            $t->timestamp('entschieden_am')->nullable()->after('entschieden_von');
            $t->text('ablehnungsgrund')->nullable()->after('entschieden_am');
            $t->index(['dienst_nr', 'status'], 'idx_abw_dienst_status');
            $t->index('status', 'idx_abw_status');
        });

        // Backfill all existing rows so the default-on-INSERT only affects
        // brand new inserts. Already 'genehmigt' by default, but be explicit
        // in case anyone added rows during the migration window.
        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->whereNull('status')->orWhere('status', '')
            ->update(['status' => 'genehmigt']);

        Schema::connection('tenant')->create('worker_notifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('mitarbeiter_id')->index();
            $t->string('dienst_nr', 10)->index();
            $t->string('type', 100);          // e.g. 'absence.approved', 'absence.rejected'
            $t->string('title', 255);
            $t->text('body')->nullable();
            $t->json('payload')->nullable();
            $t->tinyInteger('gelesen')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('read_at')->nullable();

            $t->index(['mitarbeiter_id', 'gelesen', 'created_at'], 'idx_wnotif_unread');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('worker_notifications');

        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $t) {
            $t->dropIndex('idx_abw_dienst_status');
            $t->dropIndex('idx_abw_status');
            $t->dropColumn(['status', 'entschieden_von', 'entschieden_am', 'ablehnungsgrund']);
        });
    }
};
