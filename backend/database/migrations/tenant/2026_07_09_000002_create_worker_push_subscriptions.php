<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web-Push (VAPID) subscriptions of worker-app users. One worker can hold
 * several subscriptions (phone + desktop). Expired endpoints (HTTP 404/410
 * on send) are pruned automatically by WorkerPushService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('worker_push_subscriptions')) {
            return;
        }

        Schema::connection('tenant')->create('worker_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('dienst_nr', 30)->index();
            $table->string('provider', 10)->default('webpush'); // webpush | fcm
            $table->text('endpoint'); // push endpoint URL (webpush) or device token (fcm)
            $table->string('endpoint_hash', 64)->unique(); // sha256(endpoint) — TEXT can't be UNIQUE
            $table->string('p256dh', 255)->nullable(); // webpush only
            $table->string('auth', 100)->nullable();   // webpush only
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('worker_push_subscriptions');
    }
};
