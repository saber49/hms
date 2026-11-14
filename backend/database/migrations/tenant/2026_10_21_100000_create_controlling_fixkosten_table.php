<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlling — monthly fixed costs (Gemeinkosten: Büro, Verwaltung,
 * Fahrzeuge, Versicherungen …). Each row is a recurring monthly amount valid
 * from `ab_monat` until `bis_monat` (NULL = open-ended). Changing an amount
 * from a given month closes the old row and starts a new one, so past months
 * keep their historic value. Never allocated to objects — shown as one
 * Sammelposition between DB II and the Betriebsergebnis. Idempotent, additive.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('controlling_fixkosten')) {
            Schema::connection('tenant')->create('controlling_fixkosten', function (Blueprint $t) {
                $t->id();
                $t->string('bezeichnung', 150);
                $t->string('kategorie', 50)->nullable();
                $t->decimal('betrag', 12, 2);
                $t->date('ab_monat');
                $t->date('bis_monat')->nullable();
                $t->string('updated_by', 100)->nullable();
                $t->timestamps();
                $t->index(['ab_monat', 'bis_monat']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('controlling_fixkosten');
    }
};
