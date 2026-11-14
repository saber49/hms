<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualitätsmanagement — foundation.
 *
 *  - qm_objekt_einstellungen  QM switches per Clood-X Objekt (1:1, created on
 *    first use with defaults). objektleiter_id = central_users.id of the
 *    responsible Objektleiter — the ONLY source of "own objects".
 *  - qm_einstellungen         tenant-wide key/value settings (rights table,
 *    deadlines, sender address, …).
 *  - qm_verlauf               append-only change log of every QM record.
 *  - qm_benachrichtigungen    protocol of every QM notification sent.
 *
 * Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('qm_objekt_einstellungen')) {
            $s->create('qm_objekt_einstellungen', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20)->unique();
                $t->boolean('wachbuch_aktiv')->default(false);
                $t->boolean('waechterkontrolle_aktiv')->default(false);
                $t->boolean('fotopflicht')->default(false);
                $t->unsignedBigInteger('objektleiter_id')->nullable()->index();
                $t->string('bericht_empfaenger', 500)->nullable();
                $t->unsignedSmallInteger('gps_grenze_m')->default(150);
                $t->unsignedSmallInteger('soll_begehung_tage')->nullable();
                $t->unsignedSmallInteger('soll_besuch_tage')->nullable();
                $t->text('notfallkontakte')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        if (!$s->hasTable('qm_einstellungen')) {
            $s->create('qm_einstellungen', function (Blueprint $t) {
                $t->string('schluessel', 60)->primary();
                $t->text('wert')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        if (!$s->hasTable('qm_verlauf')) {
            $s->create('qm_verlauf', function (Blueprint $t) {
                $t->id();
                $t->string('tabelle', 40);
                $t->string('datensatz_id', 40);
                $t->string('aktion', 40);
                $t->json('alt')->nullable();
                $t->json('neu')->nullable();
                $t->text('text')->nullable();
                $t->unsignedBigInteger('benutzer_id')->nullable();
                $t->string('benutzer_name', 200)->nullable();
                $t->timestamp('zeit')->useCurrent();
                $t->index(['tabelle', 'datensatz_id']);
            });
        }

        if (!$s->hasTable('qm_benachrichtigungen')) {
            $s->create('qm_benachrichtigungen', function (Blueprint $t) {
                $t->id();
                $t->string('ereignis', 60);
                $t->string('empfaenger_typ', 20);   // user | mitarbeiter | kunde
                $t->string('empfaenger_id', 40);
                $t->string('kanal', 20);            // app | email | portal | push
                $t->string('titel', 255);
                $t->text('text')->nullable();
                $t->string('bezug_typ', 40)->nullable();
                $t->string('bezug_id', 40)->nullable();
                $t->timestamp('gesendet_am')->useCurrent();
                $t->index(['ereignis', 'gesendet_am']);
                $t->index(['bezug_typ', 'bezug_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['qm_benachrichtigungen', 'qm_verlauf', 'qm_einstellungen', 'qm_objekt_einstellungen'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
