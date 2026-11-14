<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `lohn_typ` to `mitarbeiter`.
 *
 * Distinguishes between a fixed monthly salary ("gehalt", €/Monat)
 * and an hourly wage ("lohn", €/Std). The numeric amount continues
 * to live in the existing `stundenlohn` column to avoid a wider
 * schema change; downstream payroll/controlling code can read the
 * type flag to interpret the number correctly.
 *
 * Allowed values: NULL | 'gehalt' | 'lohn'.
 *
 * Idempotent: re-running on a tenant that already has the column
 * is a no-op.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'lohn_typ')) {
            return;
        }

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
            $t->string('lohn_typ', 10)->nullable()->after('stundenlohn');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter')
            && Schema::connection('tenant')->hasColumn('mitarbeiter', 'lohn_typ')) {
            Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
                $t->dropColumn('lohn_typ');
            });
        }
    }
};
