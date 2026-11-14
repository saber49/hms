<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device push tokens for central_users (admin side) — counterpart of the
 * tenant table `worker_push_subscriptions`, but stored on the CENTRAL
 * connection because the recipient identity is a central_user, not a
 * tenant worker.
 *
 * One row per device: the native worker app (Capacitor WebView loads the
 * web dashboard for admin logins) registers an FCM/APNs token here; the
 * columns p256dh/auth additionally allow a Web-Push (VAPID) subscription
 * if the web panel ever registers one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('central_user_push_tokens')) {
            Schema::create('central_user_push_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('central_user_id')->index();
                $table->string('provider', 20)->default('fcm'); // fcm | apns | webpush
                $table->string('endpoint_hash', 64)->unique();  // sha256 of the token/URL — dedupe key
                $table->text('endpoint');                       // device token or push endpoint URL
                $table->string('p256dh')->nullable();
                $table->string('auth', 100)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('central_user_push_tokens');
    }
};
