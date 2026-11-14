<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reklamationen + Maßnahmen (ISO 9001 8.2.1 / 10.2). Never deleted — every
 * change is written to qm_verlauf.
 *
 *  - qm_reklamationen          workflow eingegangen → bestaetigt →
 *    in_bearbeitung → beantwortet → abgeschlossen; deadlines per Schwere.
 *  - qm_reklamation_schritte   timeline (Eingangsbestätigung, Sofortmaßnahme,
 *    Ursache, Antwort, Eskalation, Abschluss, Notiz).
 *  - qm_massnahmen             source (reklamation | wachbuch | manuell | …),
 *    deadline + extensions, completion, effectiveness check after N days,
 *    follow-up measure, optional release to the customer portal.
 *  - qm_nummern                yearly counters for R-2026-001 / M-2026-001.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('qm_nummern')) {
            $s->create('qm_nummern', function (Blueprint $t) {
                $t->string('kreis', 10);
                $t->unsignedSmallInteger('jahr');
                $t->unsignedInteger('zaehler')->default(0);
                $t->primary(['kreis', 'jahr']);
            });
        }

        if (!$s->hasTable('qm_reklamationen')) {
            $s->create('qm_reklamationen', function (Blueprint $t) {
                $t->id();
                $t->string('nr', 20)->unique();
                $t->string('obj_id', 20)->index();
                $t->string('kunde_id', 20)->nullable()->index();
                $t->timestamp('eingang_am');
                $t->string('kanal', 20)->default('telefon');   // telefon | email | portal | persoenlich | begehung
                $t->string('melder', 200)->nullable();
                $t->unsignedBigInteger('melder_zugang_id')->nullable();
                $t->text('text');
                $t->string('kategorie', 40)->nullable();
                $t->string('schwere', 20)->default('mittel');  // gering | mittel | hoch | kritisch
                $t->string('dienst_nr', 10)->nullable();
                $t->unsignedBigInteger('zustaendig_id')->nullable()->index();
                $t->string('status', 20)->default('eingegangen');
                $t->timestamp('bestaetigt_am')->nullable();
                $t->text('sofortmassnahme')->nullable();
                $t->text('ursache')->nullable();
                $t->boolean('berechtigt')->nullable();
                $t->text('antwort')->nullable();
                $t->timestamp('beantwortet_am')->nullable();
                $t->decimal('kosten_eur', 10, 2)->nullable();
                $t->timestamp('frist_bestaetigung')->nullable();
                $t->date('frist_erledigung')->nullable();
                $t->timestamp('eskaliert_am')->nullable();
                $t->timestamp('abgeschlossen_am')->nullable();
                $t->unsignedBigInteger('abgeschlossen_von')->nullable();
                $t->unsignedBigInteger('erstellt_von')->nullable();
                $t->timestamps();
                $t->index(['status', 'frist_erledigung']);
            });
        }

        if (!$s->hasTable('qm_reklamation_schritte')) {
            $s->create('qm_reklamation_schritte', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('reklamation_id')->index();
                $t->string('art', 30);
                $t->text('text')->nullable();
                $t->boolean('an_kunde')->default(false);
                $t->unsignedBigInteger('von_id')->nullable();
                $t->string('von_name', 200)->nullable();
                $t->timestamp('am')->useCurrent();
            });
        }

        if (!$s->hasTable('qm_massnahmen')) {
            $s->create('qm_massnahmen', function (Blueprint $t) {
                $t->id();
                $t->string('nr', 20)->unique();
                $t->string('quelle_typ', 20)->default('manuell');
                $t->string('quelle_id', 40)->nullable();
                $t->string('obj_id', 20)->nullable()->index();
                $t->string('art', 20)->default('korrektur');   // korrektur | korrektiv | vorbeugung
                $t->string('titel', 200);
                $t->text('ursache')->nullable();
                $t->text('text')->nullable();
                $t->unsignedBigInteger('verantwortlich_id')->nullable()->index();
                $t->date('frist');
                $t->unsignedTinyInteger('frist_verlaengert')->default(0);
                $t->string('status', 20)->default('offen');    // offen | erledigt | wirksam | unwirksam
                $t->timestamp('erledigt_am')->nullable();
                $t->unsignedBigInteger('erledigt_von')->nullable();
                $t->text('nachweis')->nullable();
                $t->date('wirksam_pruefung_am')->nullable();
                $t->string('wirksamkeit', 20)->nullable();     // wirksam | unwirksam
                $t->text('wirksamkeit_text')->nullable();
                $t->timestamp('wirksamkeit_am')->nullable();
                $t->unsignedBigInteger('wirksamkeit_von')->nullable();
                $t->unsignedBigInteger('folge_id')->nullable();
                $t->boolean('im_kundenportal')->default(false);
                $t->unsignedBigInteger('erstellt_von')->nullable();
                $t->timestamps();
                $t->index(['quelle_typ', 'quelle_id']);
                $t->index(['status', 'frist']);
            });
        }
    }

    public function down(): void
    {
        foreach (['qm_massnahmen', 'qm_reklamation_schritte', 'qm_reklamationen', 'qm_nummern'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
