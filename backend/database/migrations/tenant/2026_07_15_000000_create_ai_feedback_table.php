<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `ai_feedback` table — thumbs-up/down ratings users give
 * on individual AI assistant replies. Per-tenant like ai_actions, so
 * feedback is never visible across tenants.
 *
 * Purpose: quality telemetry. The stored question/answer excerpts let
 * an admin review WHICH kinds of questions the assistant answers badly
 * and tune the system prompt / tools accordingly.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('ai_feedback')) {
            return;
        }
        Schema::connection('tenant')->create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index()
                ->comment('central_users.id of the rating user');
            $table->string('user_name', 200)->nullable()
                ->comment('Snapshot of user display name');
            $table->string('rating', 10)->index()
                ->comment('up | down');
            $table->text('comment')->nullable()
                ->comment('Optional free-text comment (mainly for downvotes)');
            $table->text('question')->nullable()
                ->comment('Excerpt of the user question that led to the reply (max 1000 chars)');
            $table->text('answer')->nullable()
                ->comment('Excerpt of the AI reply being rated (max 2000 chars)');
            $table->json('tools_used')->nullable()
                ->comment('Names of tools the assistant called for this reply');
            $table->timestamps();

            $table->index(['rating', 'created_at'], 'ai_feedback_rating_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('ai_feedback');
    }
};
