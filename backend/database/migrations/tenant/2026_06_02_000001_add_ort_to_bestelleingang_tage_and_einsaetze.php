<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional free-text "Ort / Treffpunkt" (location / meeting point)
 * to the shift template days and to the generated Einsätze, so the Dispo and
 * the worker app can see exactly where to meet. Populated from the
 * Bestelleingang wizard (with map-based autocomplete on the frontend).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang_tage')
            && !Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'ort')) {
            Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
                $table->string('ort', 255)->nullable()->after('beschreibung');
            });
        }

        if (Schema::connection('tenant')->hasTable('einsaetze')
            && !Schema::connection('tenant')->hasColumn('einsaetze', 'ort')) {
            Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
                $table->string('ort', 255)->nullable()->after('beschreibung');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang_tage')
            && Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'ort')) {
            Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
                $table->dropColumn('ort');
            });
        }

        if (Schema::connection('tenant')->hasTable('einsaetze')
            && Schema::connection('tenant')->hasColumn('einsaetze', 'ort')) {
            Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
                $table->dropColumn('ort');
            });
        }
    }
};
