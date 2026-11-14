<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * password_resets — backs the self-service "Passwort vergessen?" flow.
 *
 * Lives in hms_central (the same DB as central_users) because reset
 * requests are global: an email belongs to exactly one CentralUser, and
 * the flow must work BEFORE we know the user's tenant.
 *
 * We store only a SHA-256 hash of the token, never the plaintext, so
 * a leaked DB row cannot be used to reset anyone's password. The same
 * token is sent in the URL once and discarded after use.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            // 64-char hex (SHA-256 of the random plaintext token)
            $table->string('token_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Fast lookup by token hash + still-valid window
            $table->index(['token_hash', 'expires_at']);
            // Allow purging of expired/consumed rows
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
