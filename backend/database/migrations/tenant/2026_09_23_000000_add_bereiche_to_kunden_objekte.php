<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // Multi-service tagging: `bereich` stays the PRIMARY Bereich (used as
        // the derivation fallback for Einsätze), `bereiche` is the full JSON
        // list of services this customer/object buys (["Reinigung","Sicherheit"]).
        if (!Schema::connection('tenant')->hasColumn('kunden', 'bereiche')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->json('bereiche')->nullable()->after('bereich');
            });
            DB::connection('tenant')->statement(
                "UPDATE kunden SET bereiche = JSON_ARRAY(bereich) WHERE bereich IS NOT NULL AND TRIM(bereich) <> ''"
            );
        }
        if (!Schema::connection('tenant')->hasColumn('objekte', 'bereiche')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->json('bereiche')->nullable()->after('bereich');
            });
            DB::connection('tenant')->statement(
                "UPDATE objekte SET bereiche = JSON_ARRAY(bereich) WHERE bereich IS NOT NULL AND TRIM(bereich) <> ''"
            );
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('kunden', 'bereiche')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->dropColumn('bereiche');
            });
        }
        if (Schema::connection('tenant')->hasColumn('objekte', 'bereiche')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->dropColumn('bereiche');
            });
        }
    }
};
