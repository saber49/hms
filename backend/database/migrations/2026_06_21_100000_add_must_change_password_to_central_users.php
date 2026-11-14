<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Force-password-change on first login.
 *
 * When an admin invites a new sub-user (UserController::store) we now flag
 * the account with must_change_password = 1. The login flow surfaces this
 * flag, the frontend bounces the user to a mandatory "set new password"
 * screen, and AuthController::changePassword clears it once a fresh password
 * is stored. Existing accounts (owner + already-active users) are left at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('aktiv');
        });
    }

    public function down(): void
    {
        Schema::table('central_users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
