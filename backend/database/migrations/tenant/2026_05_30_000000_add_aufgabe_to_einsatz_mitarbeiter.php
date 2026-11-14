<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')) {
            return;
        }

        Schema::connection('tenant')->table('einsatz_mitarbeiter', function (Blueprint $table) {
            // Per-worker task / instructions ("was macht dieser Mitarbeiter").
            // Distinct from einsaetze.bemerkung (internal Dispo note) and
            // einsaetze.beschreibung (general shift note). This value is sent to
            // the individual worker's app so each person sees their own job.
            if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')) {
                $table->text('aufgabe')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')) {
            return;
        }

        Schema::connection('tenant')->table('einsatz_mitarbeiter', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')) {
                $table->dropColumn('aufgabe');
            }
        });
    }
};
