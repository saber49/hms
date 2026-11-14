<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kundenportal — one read-only login per customer.
 *
 * Credentials live in their OWN table (not on `kunden`) so the password
 * hash can never leak through the many `kunden.*` selects (customer list,
 * AI tools, exports). Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('kunden_portal_zugaenge')) {
            Schema::connection('tenant')->create('kunden_portal_zugaenge', function (Blueprint $t) {
                $t->id();
                $t->string('kunde_id', 20)->unique();
                $t->string('username', 50)->unique();
                $t->string('password', 255)->nullable();
                $t->boolean('aktiv')->default(false);
                $t->timestamp('last_login_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('kunden_portal_zugaenge');
    }
};
