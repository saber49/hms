<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Kundenbetreuer (account manager) per customer: central_users.id of the
     * person responsible for the customer. Every Auftragsbestätigung of the
     * customer prints this person as "Bearbeiter" unless a different one is
     * chosen on the order itself (bestelleingang.ab_bearbeiter_id). NULL =
     * no account manager → the order's creator. Schema-guarded everywhere.
     */
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('kunden')
            && !Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->unsignedBigInteger('betreuer_id')->nullable()
                    ->comment('central_users.id of the Kundenbetreuer (account manager)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('kunden', 'betreuer_id')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->dropColumn('betreuer_id');
            });
        }
    }
};
