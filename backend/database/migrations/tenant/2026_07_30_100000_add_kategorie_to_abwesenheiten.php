<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Professional absence model (market-standard, Personio-style).
 *
 * Every absence now belongs to exactly ONE of three categories:
 *   - 'urlaub'    — paid vacation, deducted from the Urlaubskonto, needs approval
 *   - 'krank'     — sickness report, acknowledged (not approved), never deducted
 *   - 'sonstiges' — everything else (unpaid leave, appointments, ...), needs approval
 *
 * `typ` stays as the human-readable detail label (e.g. 'Arzt Termin'),
 * `kategorie` drives all balance / workflow logic from now on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('mitarbeiter_abwesenheiten', 'kategorie')) {
            Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $t) {
                $t->string('kategorie', 20)->default('sonstiges')->after('typ');
                $t->index(['dienst_nr', 'kategorie'], 'idx_abw_dienst_kategorie');
            });
        }

        // Backfill from the legacy `typ` labels.
        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->whereIn('typ', ['Urlaub'])
            ->update(['kategorie' => 'urlaub']);

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->whereIn('typ', ['Krank Mit AU', 'Krank Ohne AU'])
            ->update(['kategorie' => 'krank']);

        DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->whereNotIn('typ', ['Urlaub', 'Krank Mit AU', 'Krank Ohne AU'])
            ->update(['kategorie' => 'sonstiges']);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $t) {
            $t->dropIndex('idx_abw_dienst_kategorie');
            $t->dropColumn('kategorie');
        });
    }
};
