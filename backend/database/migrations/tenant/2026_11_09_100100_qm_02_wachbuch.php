<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wachbuch (QM) — replaces the old free-text guard log.
 *
 *  - qm_wb_blaetter   one sheet per worker and shift (einsatz + dienst_nr),
 *    opened by check-in, closed by check-out. Keeps the head of its hash
 *    chain (letzte_nr / letzter_hash).
 *  - qm_wb_eintraege  append-only entries. A correction is a NEW entry of art
 *    'berichtigung' with ref_id → the corrected one (shown struck through).
 *    hash = sha256(vorgaenger_hash | canonical payload). `status` /
 *    `bearbeitet_*` are Leitstand metadata and NOT part of the hash.
 *  - qm_wb_fotos      photos bound to an entry (never deleted).
 *
 * The old tables wachbuch_eintraege / wachbuch_share_links are dropped (user
 * decision 2026-11-09: old data is not carried over).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('qm_wb_blaetter')) {
            $s->create('qm_wb_blaetter', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20);
                $t->string('einsatz_id', 30)->nullable();
                $t->string('dienst_nr', 10)->nullable();
                $t->date('datum');
                $t->timestamp('geoeffnet_am')->nullable();
                $t->timestamp('geschlossen_am')->nullable();
                $t->text('hinweis_nachfolger')->nullable();
                $t->unsignedBigInteger('gegengezeichnet_von')->nullable();
                $t->timestamp('gegengezeichnet_am')->nullable();
                $t->unsignedInteger('letzte_nr')->default(0);
                $t->char('letzter_hash', 64)->nullable();
                $t->timestamps();
                $t->unique(['einsatz_id', 'dienst_nr']);
                $t->index(['obj_id', 'datum']);
            });
        }

        if (!$s->hasTable('qm_wb_eintraege')) {
            $s->create('qm_wb_eintraege', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('blatt_id');
                $t->string('obj_id', 20);
                $t->unsignedInteger('nr');
                $t->string('art', 20);
                $t->timestamp('zeit')->useCurrent();          // server time
                $t->timestamp('erfasst_am')->nullable();       // device time (offline)
                $t->boolean('nachgereicht')->default(false);
                $t->string('ort', 200)->nullable();
                $t->text('text')->nullable();
                $t->json('details')->nullable();
                $t->boolean('wichtig')->default(false);
                $t->boolean('auto')->default(false);
                $t->unsignedBigInteger('ref_id')->nullable();  // berichtigung → corrected entry
                $t->string('quelle', 20)->default('app');      // app | terminal | buero | system
                $t->string('dienst_nr', 10)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('erfasst_von', 200)->nullable();
                $t->string('client_uuid', 64)->nullable()->unique();
                $t->char('vorgaenger_hash', 64)->nullable();
                $t->char('hash', 64);
                $t->string('status', 20)->nullable();          // offen | erledigt (Vorkommnis/Alarm)
                $t->unsignedBigInteger('bearbeitet_von')->nullable();
                $t->timestamp('bearbeitet_am')->nullable();
                $t->unique(['blatt_id', 'nr']);
                $t->index(['obj_id', 'zeit']);
                $t->index(['art', 'status']);
            });
        }

        if (!$s->hasTable('qm_wb_fotos')) {
            $s->create('qm_wb_fotos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('eintrag_id')->index();
                $t->string('pfad', 300);
                $t->timestamp('aufgenommen_am')->nullable();
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->unsignedInteger('genauigkeit_m')->nullable();
                $t->unsignedInteger('entfernung_objekt_m')->nullable();
                $t->boolean('nicht_am_objekt')->default(false);
                $t->string('quelle', 20)->default('app');
                $t->timestamp('created_at')->useCurrent();
            });
        }

        $s->dropIfExists('wachbuch_share_links');
        $s->dropIfExists('wachbuch_eintraege');
    }

    public function down(): void
    {
        foreach (['qm_wb_fotos', 'qm_wb_eintraege', 'qm_wb_blaetter'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
