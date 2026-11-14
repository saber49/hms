<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `quasod_module` to `mitarbeiter`.
 *
 * Stores the QuaSOD (Qualifikation Stadion-Ordnungsdienst) modules a
 * stadium guard has completed, as a JSON array of short codes:
 *   m1e        = Modul 1 - E-Learning
 *   m1p        = Modul 1 - Prüfung
 *   m2         = Modul 2 - Ortskunde
 *   m3         = Modul 3 - Brandschutz
 *   m4         = Modul 4 - Erste-Hilfe
 *   m5         = Modul 5 - Deeskalation
 *   m6         = Modul 6 - Einlass
 *   m7         = Modul 7 - Führungskräfte
 *   m8         = Modul 8 - Einsatzbereitschaft
 *   m9         = Modul 9 - VIP
 *   gesperrt   = Gesperrt (worker is blocked from stadium duty)
 *
 * JSON keeps the column flexible: extra modules added by the DFB in
 * future seasons can be supported without ALTER TABLE.
 *
 * Idempotent: re-running on a tenant that already has the column is
 * a no-op.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'quasod_module')) {
            return;
        }

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
            // Placed after dfb_pin so the column order mirrors the
            // on-screen "Bewachung & DFB" group.
            $t->json('quasod_module')->nullable()->after('dfb_pin');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter')
            && Schema::connection('tenant')->hasColumn('mitarbeiter', 'quasod_module')) {
            Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
                $t->dropColumn('quasod_module');
            });
        }
    }
};
