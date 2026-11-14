<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications, per-tenant.
 *
 * `central_user_id` references central_users.id (central DB) — kept as a plain
 * unsigned bigint (no FK) because central_users lives in a different connection.
 *
 * `type` is a short machine-readable kind (e.g. 'worker.absence.created',
 * 'worker.absence.updated') used by the UI to route + style notifications.
 *
 * `link` is an in-app deep link (e.g. '#workers?dienst_nr=...') so the user
 * can jump straight to the related record from the notifications dropdown.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('notifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('central_user_id')->index();
            $t->string('type', 100);
            $t->string('title', 255);
            $t->text('body')->nullable();
            $t->string('link', 255)->nullable();
            $t->json('payload')->nullable();
            $t->tinyInteger('gelesen')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('read_at')->nullable();

            $t->index(['central_user_id', 'gelesen', 'created_at'], 'idx_notif_user_unread');
            $t->index(['central_user_id', 'type', 'gelesen'], 'idx_notif_user_type');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('notifications');
    }
};
