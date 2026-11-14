<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `ai_actions` audit table — every write action proposed,
 * confirmed and executed by the AI assistant is logged here. This table
 * lives per-tenant so a tenant can only ever see its own AI history.
 *
 * Lifecycle of a row:
 *   1. AI tool wants to write → row inserted with status='pending'
 *   2. User clicks "Confirm"   → status='confirmed' (briefly)
 *      then immediately        → status='executed' OR 'failed'
 *      User cancels/expires    → status='cancelled' / 'expired'
 *
 * Idempotent: the unique action_id (UUID) prevents the same action
 * from being executed twice if the confirm endpoint is called twice.
 *
 * The `rollback_data` column stores enough information to manually undo
 * a write later (currently human-driven; future auto-undo possible).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('ai_actions')) {
            return;
        }
        Schema::connection('tenant')->create('ai_actions', function (Blueprint $table) {
            $table->id();
            $table->char('action_id', 36)->unique()
                ->comment('Public UUID — referenced by frontend confirmation flow');
            $table->string('tool_name', 64)
                ->comment('e.g. create_worker, update_kunde');
            $table->string('status', 20)->default('pending')->index()
                ->comment('pending|confirmed|executed|failed|cancelled|expired');

            // Who & when
            $table->unsignedBigInteger('user_id')->index()
                ->comment('central_users.id of the requesting user');
            $table->string('user_name', 200)->nullable()
                ->comment('Snapshot of user display name for audit clarity');

            // Payloads
            $table->json('args')
                ->comment('Raw arguments the LLM proposed');
            $table->json('preview')->nullable()
                ->comment('Human-readable preview shown to user before confirmation');
            $table->json('result')->nullable()
                ->comment('Successful execution result (e.g. {dienst_nr: "MA-123"})');
            $table->json('rollback_data')->nullable()
                ->comment('Snapshot of pre-write state for manual undo (updates only)');
            $table->text('error')->nullable()
                ->comment('Error message if status=failed');

            // Timestamps for the full lifecycle
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Useful composite index for "show my pending actions" queries
            $table->index(['user_id', 'status', 'created_at'], 'ai_actions_user_status_idx');
            $table->index(['tool_name', 'status'], 'ai_actions_tool_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('ai_actions');
    }
};
