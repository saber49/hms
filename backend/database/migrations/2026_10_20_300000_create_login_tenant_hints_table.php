<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central "which tenant did this login last succeed in" hints for logins that
 * otherwise scan every tenant DB (worker app). Only a SHA-256 of the lower-cased
 * identifier is stored. A hint is an optimisation, never an authority: the
 * password is still checked in the tenant DB, and a miss falls back to the
 * full scan. Additive / idempotent.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        if (!Schema::connection('mysql')->hasTable('login_tenant_hints')) {
            Schema::connection('mysql')->create('login_tenant_hints', function (Blueprint $t) {
                $t->string('typ', 20);
                $t->char('identifier_hash', 64);
                $t->unsignedBigInteger('tenant_id');
                $t->timestamp('updated_at')->nullable();
                $t->primary(['typ', 'identifier_hash']);
                $t->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('login_tenant_hints');
    }
};
