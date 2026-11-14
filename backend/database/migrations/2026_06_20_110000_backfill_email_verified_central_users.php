<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mandatory email verification (EnsureEmailVerified middleware) is now active.
 * Every pre-existing central_user predates double opt-in and must be treated
 * as verified, otherwise the new gate would lock current customers out of
 * their own accounts.
 *
 * Mark every existing user with a NULL email_verified_at as verified now.
 * Going forward only freshly self-registered owners start unverified.
 *
 * Central DB only. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('central_users') || !Schema::hasColumn('central_users', 'email_verified_at')) {
            return;
        }

        DB::table('central_users')
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at'  => now(),
                'verification_token' => null,
            ]);
    }

    public function down(): void
    {
        // Non-reversible: we cannot know which users were unverified before.
    }
};
