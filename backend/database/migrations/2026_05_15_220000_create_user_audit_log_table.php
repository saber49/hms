<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the Benutzerverwaltung module.
 *
 * Every sensitive change to a user account (create, update, deactivate,
 * delete, password reset, role/permission change) is appended here. The
 * log lives in the central DB so we get a single auditable timeline per
 * tenant — the data never crosses tenants because every row carries
 * `tenant_id` and is queried with that filter.
 *
 * Design choices:
 *   - JSON `before` / `after` snapshots are deliberately small (only the
 *     fields we change). Hashes / passwords are NEVER stored.
 *   - `actor_id` / `target_id` are FK-soft (no DB constraint) so deleting
 *     a user does not cascade away their own history.
 *   - Indexed on (tenant_id, created_at) for fast "last 50 changes" lookups
 *     and on (target_id) for "history of one user" lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('mysql')->hasTable('user_audit_log')) return;

        Schema::connection('mysql')->create('user_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_id')->nullable();   // who did it (null = system / migration)
            $table->string('actor_email', 255)->nullable();       // denormalised for fast UI rendering
            $table->unsignedBigInteger('target_id')->nullable();  // affected user (null = bulk)
            $table->string('target_username', 100)->nullable();   // denormalised
            $table->string('action', 40);                         // create | update | deactivate | activate | delete | password_reset | role_change | permissions_change
            $table->json('before')->nullable();                   // previous values (only the changed keys)
            $table->json('after')->nullable();                    // new values (only the changed keys)
            $table->string('ip', 45)->nullable();                 // IPv4/IPv6
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at'], 'idx_uaudit_tenant_time');
            $table->index('target_id', 'idx_uaudit_target');
            $table->index('action', 'idx_uaudit_action');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('user_audit_log');
    }
};
