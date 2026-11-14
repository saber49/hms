<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Zuschläge configuration AT THE OBJEKT LEVEL.
 *
 * Why both levels?
 * ────────────────
 * The artikel-level Zuschläge from migration `2026_05_14_120000_add_zuschlaege_system`
 * gives full granularity (one rate card per service).
 *
 * In practice many cleaning / security companies negotiate ONE rate card
 * with the customer that applies to every service performed at that
 * location. Forcing them to retype the same percentages on each new
 * artikel is friction.
 *
 * So we add the same fields on `objekte`:
 *   - They serve as DEFAULTS for every newly created artikel under the
 *     objekt (inherited automatically by ArtikelController.store()).
 *   - They power a "Auf alle Artikel anwenden" button in the UI that
 *     copies the objekt rates to all existing artikels of the objekt
 *     (handled in ObjectController.applyZuschlaegeToArtikel()).
 *
 * The artikel-level config remains the source of truth for per-einsatz
 * classification — Controlling and the SchichtartCalculator still read
 * from `artikel`. The objekt-level fields are pure UX convenience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('objekte', function (Blueprint $t) {
            $t->boolean('zuschlaege_aktiv')->default(0)->after('aktiv');

            $t->decimal('zuschlag_nacht_pct',          5, 2)->default(10.00)->after('zuschlaege_aktiv');
            $t->decimal('zuschlag_sonntag_pct',        5, 2)->default(25.00)->after('zuschlag_nacht_pct');
            $t->decimal('zuschlag_feiertag_pct',       5, 2)->default(50.00)->after('zuschlag_sonntag_pct');
            $t->decimal('zuschlag_nacht_sonntag_pct',  5, 2)->default(35.00)->after('zuschlag_feiertag_pct');
            $t->decimal('zuschlag_nacht_feiertag_pct', 5, 2)->default(60.00)->after('zuschlag_nacht_sonntag_pct');

            $t->time('nacht_von')->default('22:00:00')->after('zuschlag_nacht_feiertag_pct');
            $t->time('nacht_bis')->default('06:00:00')->after('nacht_von');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('objekte', function (Blueprint $t) {
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
};
