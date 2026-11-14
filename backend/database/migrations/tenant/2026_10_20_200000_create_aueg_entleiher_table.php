<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AÜG (Arbeitnehmerüberlassung) — customers that are Entleiher.
 *
 * Own table (not columns on `kunden`): only customers listed here are
 * checked for Überlassungshöchstdauer (§ 1 Abs. 1b AÜG) and Equal Pay
 * (§ 8 Abs. 4 AÜG). Deviating limits from a collective agreement of the
 * Entleiher's industry are stored per customer. Idempotent, additive.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('aueg_entleiher')) {
            Schema::connection('tenant')->create('aueg_entleiher', function (Blueprint $t) {
                $t->id();
                $t->string('kunde_id', 20)->unique();
                $t->boolean('aktiv')->default(true);
                $t->unsignedSmallInteger('hoechstdauer_monate')->default(18);
                $t->unsignedSmallInteger('equal_pay_monate')->default(9);
                $t->string('bemerkung', 255)->nullable();
                $t->string('updated_by', 100)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('aueg_entleiher');
    }
};
