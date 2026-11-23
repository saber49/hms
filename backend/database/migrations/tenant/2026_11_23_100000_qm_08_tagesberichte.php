<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');
        $s->table('qm_objekt_einstellungen', function (Blueprint $t) use ($s) {
            if (!$s->hasColumn('qm_objekt_einstellungen', 'bericht_pflicht')) {
                $t->boolean('bericht_pflicht')->default(false);
            }
            if (!$s->hasColumn('qm_objekt_einstellungen', 'bericht_ab')) {
                $t->date('bericht_ab')->nullable();
            }
            if (!$s->hasColumn('qm_objekt_einstellungen', 'durchgehend_bewacht')) {
                $t->boolean('durchgehend_bewacht')->default(false);
            }
            if (!$s->hasColumn('qm_objekt_einstellungen', 'besucher_ausweis_pflicht')) {
                $t->boolean('besucher_ausweis_pflicht')->default(false);
            }
            if (!$s->hasColumn('qm_objekt_einstellungen', 'lieferung_freigabe_pflicht')) {
                $t->boolean('lieferung_freigabe_pflicht')->default(false);
            }
            if (!$s->hasColumn('qm_objekt_einstellungen', 'uebergabe_checkliste')) {
                $t->boolean('uebergabe_checkliste')->default(false);
            }
            if (!$s->hasColumn('qm_objekt_einstellungen', 'besuchszeit_ende')) {
                $t->string('besuchszeit_ende', 5)->nullable();
            }
        });

        if (!$s->hasTable('qm_wb_schluessel')) {
            $s->create('qm_wb_schluessel', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20);
                $t->string('kennung', 80);
                $t->string('bezeichnung', 200);
                $t->boolean('aktiv')->default(true);
                $t->timestamp('bestand_geprueft_am')->nullable();
                $t->unsignedBigInteger('bestand_geprueft_von')->nullable();
                $t->timestamps();
                $t->unique(['obj_id', 'kennung']);
            });
        }

        if (!$s->hasTable('qm_wb_berichte')) {
            $s->create('qm_wb_berichte', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20);
                $t->date('datum');
                $t->unsignedInteger('version');
                $t->longText('daten'); // Preserve the exact hashed bytes across MySQL JSON normalization.
                $t->char('pruefsumme', 64);
                $t->unsignedBigInteger('freigegeben_von');
                $t->timestamp('freigegeben_am');
                $t->timestamps();
                $t->unique(['obj_id', 'datum', 'version']);
                $t->index(['obj_id', 'datum']);
            });
        }
    }

    public function down(): void
    {
        $s = Schema::connection('tenant');
        $s->dropIfExists('qm_wb_berichte');
        $s->dropIfExists('qm_wb_schluessel');
        foreach (['bericht_pflicht', 'bericht_ab', 'durchgehend_bewacht', 'besucher_ausweis_pflicht', 'lieferung_freigabe_pflicht', 'uebergabe_checkliste', 'besuchszeit_ende'] as $column) {
            if ($s->hasColumn('qm_objekt_einstellungen', $column)) {
                $s->table('qm_objekt_einstellungen', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
