<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunden-Freigabe (Monatsabschluss → Modus "Kunde"): the customer-facing
 * values of one assignment (Leistungsnachweis) and their own approval,
 * independent of the worker's recorded time and zeit_freigabe.
 * NULL kunden_* = not yet set → the worker's Ist is shown; approving
 * snapshots the shown values, so later Ist changes never alter them.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    private const TABLES = ['einsatz_mitarbeiter', 'nu_position_zuweisungen'];

    public function up(): void
    {
        foreach (self::TABLES as $tbl) {
            if (!Schema::connection('tenant')->hasTable($tbl)
                || Schema::connection('tenant')->hasColumn($tbl, 'kunden_freigabe')) continue;
            Schema::connection('tenant')->table($tbl, function (Blueprint $table) {
                $table->string('kunden_start', 10)->nullable();
                $table->string('kunden_ende', 10)->nullable();
                $table->integer('kunden_pause')->nullable();
                $table->decimal('kunden_std', 6, 2)->nullable();
                $table->boolean('kunden_freigabe')->default(false);
                $table->dateTime('kunden_freigabe_am')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tbl) {
            if (!Schema::connection('tenant')->hasTable($tbl)
                || !Schema::connection('tenant')->hasColumn($tbl, 'kunden_freigabe')) continue;
            Schema::connection('tenant')->table($tbl, function (Blueprint $table) {
                $table->dropColumn(['kunden_start', 'kunden_ende', 'kunden_pause', 'kunden_std',
                    'kunden_freigabe', 'kunden_freigabe_am']);
            });
        }
    }
};
