<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the central `password_resets` table so the same flow can
 * also reset passwords for workers (rows live in tenant-side
 * `mitarbeiter`). Tokens for workers carry the tenant_id so the
 * reset can locate the right tenant DB without trusting the URL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('password_resets', function (Blueprint $table) {
            if (!Schema::hasColumn('password_resets', 'subject')) {
                $table->enum('subject', ['central', 'worker'])
                      ->default('central')
                      ->after('email');
            }
            if (!Schema::hasColumn('password_resets', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')
                      ->nullable()
                      ->after('subject');
                $table->index(['subject', 'tenant_id', 'email']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('password_resets', function (Blueprint $table) {
            if (Schema::hasColumn('password_resets', 'tenant_id')) {
                $table->dropIndex(['subject', 'tenant_id', 'email']);
                $table->dropColumn('tenant_id');
            }
            if (Schema::hasColumn('password_resets', 'subject')) {
                $table->dropColumn('subject');
            }
        });
    }
};
