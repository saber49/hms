<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `stundenlohn` (€ per hour) to the `mitarbeiter` table so the
 * Controlling module can compute the TRUE Gewinn of every Einsatz:
 *
 *     Gewinn = Einnahmen − NU-Kosten − MA-Kosten
 *
 * where MA-Kosten = Σ (worker.ist_std × worker.stundenlohn) for every
 * INTERNAL Einsatz (NU einsätze are unaffected: their cost is already
 * captured by `artikel.nu_preis`).
 *
 * Default value is 0.00 — i.e. tenants that haven't filled this in yet
 * still see exactly the numbers they saw before, just without the new
 * MA-Kosten column being meaningful. They opt in by entering wages on
 * the worker profiles.
 *
 * Idempotent — checks for the column before adding.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'stundenlohn')) {
            return;
        }
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            // 8,2 mirrors `arbeitsstunden` and is the same shape used for
            // `preis_kunde` / `nu_preis` on `artikel`, keeping money math
            // consistent across the schema.
            $table->decimal('stundenlohn', 8, 2)
                  ->default(0)
                  ->nullable()
                  ->after('arbeitsstunden')
                  ->comment('Brutto-€/Stunde des Mitarbeiters; wird vom Controlling als interne Kosten verrechnet');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter')
            && Schema::connection('tenant')->hasColumn('mitarbeiter', 'stundenlohn')) {
            Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
                $table->dropColumn('stundenlohn');
            });
        }
    }
};
