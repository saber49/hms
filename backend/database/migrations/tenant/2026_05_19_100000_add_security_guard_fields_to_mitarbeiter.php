<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add HR + security-guard ("Bewachung") fields to `mitarbeiter` so the
 * tenant matches the standard German Sicherheitsdienst-onboarding form
 * (DFB stadium guards, §34a GewO/GewSchV-compliant security personnel).
 *
 * Adds 14 columns:
 *  - Identity:        anrede, geschlecht, geburtsname, geburtsland
 *  - ID document:     personalausweis_nr, personalausweis_gueltig_ab,
 *                     personalausweis_gueltig_bis
 *  - Bewachung:       bewacherregister_nr, dfb_pin, funkrufname,
 *                     barcode, ordnernummer
 *  - Address (extra): mobil, land, bundesland
 *
 * Idempotent — every column is checked via `hasColumn` first, so the
 * migration is safe to re-run on tenants that may have been hand-patched.
 *
 * No back-fill is required: existing rows simply get NULL in the new
 * columns. The HR module's "Profil-unvollständig" badge will surface
 * the gaps so managers can fill them in over time.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) {
            // ---- Identity ----
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'anrede')) {
                // Herr / Frau / Divers / neutral (or NULL)
                $t->string('anrede', 20)->nullable()->after('vorname');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'geschlecht')) {
                // m / w / d / k (k = keine Angabe)
                $t->char('geschlecht', 1)->nullable()->after('anrede');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'geburtsname')) {
                $t->string('geburtsname', 100)->nullable()->after('geburtsort');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'geburtsland')) {
                $t->string('geburtsland', 50)->nullable()->after('geburtsname');
            }

            // ---- Personalausweis (German ID card) ----
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'personalausweis_nr')) {
                $t->string('personalausweis_nr', 30)->nullable()->after('staatsangehoerigkeit');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'personalausweis_gueltig_ab')) {
                $t->date('personalausweis_gueltig_ab')->nullable()->after('personalausweis_nr');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'personalausweis_gueltig_bis')) {
                $t->date('personalausweis_gueltig_bis')->nullable()->after('personalausweis_gueltig_ab');
            }

            // ---- Address (extra) ----
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'mobil')) {
                $t->string('mobil', 50)->nullable()->after('telefon');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'land')) {
                // ISO-3166 alpha-2 (DE, AT, CH, ...). Default DE for German tenants.
                $t->string('land', 5)->nullable()->default('DE')->after('ort');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'bundesland')) {
                $t->string('bundesland', 50)->nullable()->after('land');
            }

            // ---- Bewachung / DFB ----
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'bewacherregister_nr')) {
                // Required by §34a GewO + GewSchV for any active security guard.
                $t->string('bewacherregister_nr', 30)->nullable()->after('dfb_schulung');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'dfb_pin')) {
                // 4-8 digit PIN issued by the DFB for stadium-licensed guards.
                $t->string('dfb_pin', 20)->nullable()->after('bewacherregister_nr');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'funkrufname')) {
                $t->string('funkrufname', 50)->nullable()->after('dfb_pin');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'barcode')) {
                // Optional ID-badge barcode for clock-in / access control.
                $t->string('barcode', 50)->nullable()->after('funkrufname');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'ordnernummer')) {
                // Physical HR-folder reference number.
                $t->string('ordnernummer', 30)->nullable()->after('barcode');
            }
        });

        // Helpful look-ups: barcode is scanned at gates, so add an index;
        // also bewacherregister_nr because compliance audits filter on it.
        $existing = collect(\DB::connection('tenant')->select('SHOW INDEX FROM mitarbeiter'))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) use ($existing) {
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'barcode')
                && !in_array('idx_mitarbeiter_barcode', $existing)) {
                $t->index('barcode', 'idx_mitarbeiter_barcode');
            }
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'bewacherregister_nr')
                && !in_array('idx_mitarbeiter_bewacherreg', $existing)) {
                $t->index('bewacherregister_nr', 'idx_mitarbeiter_bewacherreg');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('mitarbeiter')) {
            return;
        }

        $existing = collect(\DB::connection('tenant')->select('SHOW INDEX FROM mitarbeiter'))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $t) use ($existing) {
            if (in_array('idx_mitarbeiter_barcode', $existing)) {
                $t->dropIndex('idx_mitarbeiter_barcode');
            }
            if (in_array('idx_mitarbeiter_bewacherreg', $existing)) {
                $t->dropIndex('idx_mitarbeiter_bewacherreg');
            }

            foreach ([
                'ordnernummer', 'barcode', 'funkrufname', 'dfb_pin',
                'bewacherregister_nr', 'bundesland', 'land', 'mobil',
                'personalausweis_gueltig_bis', 'personalausweis_gueltig_ab',
                'personalausweis_nr', 'geburtsland', 'geburtsname',
                'geschlecht', 'anrede',
            ] as $col) {
                if (Schema::connection('tenant')->hasColumn('mitarbeiter', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
