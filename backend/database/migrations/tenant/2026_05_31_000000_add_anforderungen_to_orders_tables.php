<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Structured staffing requirements ("Anforderungen") for a shift.
     *
     * Stored as a JSON document so Dispo sees exactly who to pick without
     * re-opening the Bestelleingang order. Shape:
     *   {
     *     "qualifikation": "§34a",
     *     "kleidung": "neutrale DK, schwarze Hose",
     *     "skill": "Deutsch C1",
     *     "positionen": [
     *       {"anzahl": 1, "funktion": "Blocker", "qualifikation": "QuaSOD", "kleidung": "neutrale DK"},
     *       {"anzahl": 9, "funktion": "Checker", "qualifikation": "§34a + QuaSOD", "kleidung": "schwarze Hose"}
     *     ]
     *   }
     *
     * Source of truth lives on bestelleingang_tage; it is copied onto each
     * einsatz so the planner can read it directly. Kept distinct from the
     * free-text beschreibung (general note / Treffpunkt) which stays as-is.
     */
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang_tage')
            && !Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'anforderungen')) {
            Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
                $table->longText('anforderungen')->nullable();
            });
        }

        if (Schema::connection('tenant')->hasTable('einsaetze')
            && !Schema::connection('tenant')->hasColumn('einsaetze', 'anforderungen')) {
            Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
                $table->longText('anforderungen')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('bestelleingang_tage')
            && Schema::connection('tenant')->hasColumn('bestelleingang_tage', 'anforderungen')) {
            Schema::connection('tenant')->table('bestelleingang_tage', function (Blueprint $table) {
                $table->dropColumn('anforderungen');
            });
        }

        if (Schema::connection('tenant')->hasTable('einsaetze')
            && Schema::connection('tenant')->hasColumn('einsaetze', 'anforderungen')) {
            Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
                $table->dropColumn('anforderungen');
            });
        }
    }
};
