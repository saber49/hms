<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `gewo_qualifikation` to `mitarbeiter`.
 *
 * Stores the §34a GewO qualification level a security guard holds:
 *   VOD   = ohne §34a (Veranstaltungsordner / no §34a)
 *   U     = §34a Unterrichtung (40h)
 *   SKP   = §34a Sachkundeprüfung
 *   GSSK  = Geprüfte Schutz- und Sicherheitskraft (IHK)
 *   FK    = Fachkraft für Schutz und Sicherheit (IHK)
 *
 * Stored as a short VARCHAR so the list can be extended without an
 * ALTER TABLE (avoids the ENUM-rebuild pitfall on MySQL).
 *
 * Idempotent: re-running on a tenant that already has the column is a
 * no-op.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'gewo_qualifikation')) {
            return;
        }

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
            // Placed right after bewacherregister_nr so the column order
            // mirrors the on-screen "Bewachung & DFB" group.
            $t->string('gewo_qualifikation', 10)
                ->nullable()
                ->after('bewacherregister_nr');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter')
            && Schema::connection('tenant')->hasColumn('mitarbeiter', 'gewo_qualifikation')) {
            Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
                $t->dropColumn('gewo_qualifikation');
            });
        }
    }
};
