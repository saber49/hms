<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adds the German wage-surcharge ("Zuschläge") system.
 *
 * Schema changes
 * ──────────────
 * 1. artikel  → adds 5 percentage columns + night window + master toggle.
 *    Defaults match the standard market rates in DE:
 *    Nachtdienst +10 %, Sonntag +25 %, Feiertag +50 %,
 *    Nacht+Sonntag +35 %, Nacht+Feiertag +60 %.
 *    Toggle is OFF by default so existing pricing keeps working.
 *
 * 2. einsaetze → adds an auto-computed `schichtart` (tagdienst | nachtdienst |
 *    sonntag | feiertag | nacht_sonntag | nacht_feiertag) and a snapshot of
 *    the applied `zuschlag_pct`. The snapshot lets us re-price historic
 *    einsätze deterministically even after artikel rates change later.
 *
 * 3. feiertage → new lookup table with German federal holidays seeded for
 *    2024-2030. Tenants can add region-specific holidays (e.g. DE-BY).
 *
 * Computation priority (highest → lowest):
 *   feiertag + nacht  → nacht_feiertag
 *   feiertag          → feiertag
 *   sonntag + nacht   → nacht_sonntag
 *   sonntag           → sonntag
 *   nacht             → nachtdienst
 *   else              → tagdienst (0 %)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ────────── 1. artikel: surcharge configuration ──────────
        Schema::connection('tenant')->table('artikel', function (Blueprint $t) {
            // Master toggle — when off, all einsätze are tagdienst (0%).
            $t->boolean('zuschlaege_aktiv')->default(0)->after('aktiv');

            // Percentages (decimal so 12.5 % is representable).
            $t->decimal('zuschlag_nacht_pct',          5, 2)->default(10.00)->after('zuschlaege_aktiv');
            $t->decimal('zuschlag_sonntag_pct',        5, 2)->default(25.00)->after('zuschlag_nacht_pct');
            $t->decimal('zuschlag_feiertag_pct',       5, 2)->default(50.00)->after('zuschlag_sonntag_pct');
            $t->decimal('zuschlag_nacht_sonntag_pct',  5, 2)->default(35.00)->after('zuschlag_feiertag_pct');
            $t->decimal('zuschlag_nacht_feiertag_pct', 5, 2)->default(60.00)->after('zuschlag_nacht_sonntag_pct');

            // Definition of "night" (defaults to legal German night-work window).
            $t->time('nacht_von')->default('22:00:00')->after('zuschlag_nacht_feiertag_pct');
            $t->time('nacht_bis')->default('06:00:00')->after('nacht_von');
        });

        // ────────── 2. einsaetze: schichtart classification ──────────
        Schema::connection('tenant')->table('einsaetze', function (Blueprint $t) {
            $t->string('schichtart', 30)->default('tagdienst')->after('hms_bestaetigt');
            $t->decimal('zuschlag_pct', 5, 2)->default(0.00)->after('schichtart');
            $t->index('schichtart', 'idx_einsaetze_schichtart');
        });

        // ────────── 3. feiertage table ──────────
        Schema::connection('tenant')->create('feiertage', function (Blueprint $t) {
            $t->id();
            $t->date('datum');
            $t->string('name', 200);
            $t->string('region', 10)->default('DE'); // DE = bundesweit, DE-BY/DE-NW etc. = regional
            $t->boolean('aktiv')->default(1);
            $t->timestamps();

            $t->unique(['datum', 'region', 'name'], 'uniq_feiertag_datum_region_name');
            $t->index(['region', 'aktiv', 'datum'], 'idx_feiertag_lookup');
        });

        // Seed federal holidays for 2024-2030.
        $this->seedFeiertage();

        // ────────── 4. Backfill schichtart for existing einsaetze ──────────
        // We don't auto-classify historic einsätze (zuschlaege_aktiv defaults
        // to 0, so all artikel start with no surcharges). Once the user turns
        // it on, the controller will reclassify on the next save.
        //
        // For new einsätze created BEFORE this migration ran, we leave them
        // at the default 'tagdienst' / 0%. The user can toggle and resave
        // to apply.
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('feiertage');

        Schema::connection('tenant')->table('einsaetze', function (Blueprint $t) {
            $t->dropIndex('idx_einsaetze_schichtart');
            $t->dropColumn(['schichtart', 'zuschlag_pct']);
        });

        Schema::connection('tenant')->table('artikel', function (Blueprint $t) {
            $t->dropColumn([
                'zuschlaege_aktiv',
                'zuschlag_nacht_pct',
                'zuschlag_sonntag_pct',
                'zuschlag_feiertag_pct',
                'zuschlag_nacht_sonntag_pct',
                'zuschlag_nacht_feiertag_pct',
                'nacht_von',
                'nacht_bis',
            ]);
        });
    }

    /**
     * Seed German federal holidays (gesetzliche Feiertage in ganz Deutschland).
     *
     * Easter is the anchor for 4 movable feasts (Karfreitag, Ostermontag,
     * Christi Himmelfahrt, Pfingstmontag). We hard-code Easter dates for
     * 2024-2030 to avoid depending on the PHP `calendar` extension at
     * runtime.
     */
    private function seedFeiertage(): void
    {
        $easterDates = [
            2024 => '2024-03-31',
            2025 => '2025-04-20',
            2026 => '2026-04-05',
            2027 => '2027-03-28',
            2028 => '2028-04-16',
            2029 => '2029-04-01',
            2030 => '2030-04-21',
        ];

        $now = now();
        $rows = [];

        foreach ($easterDates as $year => $easter) {
            $easterTs = strtotime($easter);

            $rows[] = ['datum' => "$year-01-01",                                  'name' => 'Neujahr'];
            $rows[] = ['datum' => date('Y-m-d', $easterTs - 2 * 86400),           'name' => 'Karfreitag'];
            $rows[] = ['datum' => date('Y-m-d', $easterTs + 1 * 86400),           'name' => 'Ostermontag'];
            $rows[] = ['datum' => "$year-05-01",                                  'name' => 'Tag der Arbeit'];
            $rows[] = ['datum' => date('Y-m-d', $easterTs + 39 * 86400),          'name' => 'Christi Himmelfahrt'];
            $rows[] = ['datum' => date('Y-m-d', $easterTs + 50 * 86400),          'name' => 'Pfingstmontag'];
            $rows[] = ['datum' => "$year-10-03",                                  'name' => 'Tag der Deutschen Einheit'];
            $rows[] = ['datum' => "$year-12-25",                                  'name' => '1. Weihnachtstag'];
            $rows[] = ['datum' => "$year-12-26",                                  'name' => '2. Weihnachtstag'];
        }

        // Insert in chunks with insertOrIgnore so re-runs don't blow up.
        $rows = array_map(function ($r) use ($now) {
            return $r + [
                'region'     => 'DE',
                'aktiv'      => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        DB::connection('tenant')->table('feiertage')->insertOrIgnore($rows);
    }
};
