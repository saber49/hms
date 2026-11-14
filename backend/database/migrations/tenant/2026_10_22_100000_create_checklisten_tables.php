<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklisten & Protokolle (engine for all industries — QM Reinigung,
 * Leistungsnachweis Hauswirtschaft, Wartung FM, Winterdienst, Kontrollgang).
 *
 *  - `checklisten_vorlagen`     templates: `felder` JSON (see App\Support\Checklisten),
 *                               scope = main Bereich (`bereich_id`, NULL = all) and
 *                               `alle_objekte` or explicit assignments. `vorlage_key`
 *                               marks templates installed from an industry pack.
 *  - `checklisten_zuordnungen`  template ↔ Objekt (+ pflicht = must be filled per shift).
 *  - `checklisten_protokolle`   one filled checklist: snapshot of the template fields,
 *                               answers, score, GPS, signatures. `client_uuid` keeps
 *                               app retries idempotent.
 *  - `checklisten_fotos`        photos per protocol + field (private disk).
 *
 * Idempotent — checks each table before creating it.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('checklisten_vorlagen')) {
            $s->create('checklisten_vorlagen', function (Blueprint $t) {
                $t->id();
                $t->string('name', 190);
                $t->text('beschreibung')->nullable();
                $t->string('kategorie', 30)->default('sonstiges');
                $t->unsignedInteger('bereich_id')->nullable();
                $t->boolean('alle_objekte')->default(true);
                $t->json('felder');
                $t->string('unterschrift', 20)->default('keine');
                $t->boolean('gps')->default(true);
                $t->boolean('im_kundenportal')->default(true);
                $t->boolean('aktiv')->default(true);
                $t->unsignedInteger('version')->default(1);
                $t->string('vorlage_key', 80)->nullable();
                $t->string('created_by', 255)->nullable();
                $t->string('updated_by', 255)->nullable();
                $t->timestamps();
                $t->index(['aktiv', 'bereich_id']);
                $t->index('vorlage_key');
            });
        }

        if (!$s->hasTable('checklisten_zuordnungen')) {
            $s->create('checklisten_zuordnungen', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('vorlage_id');
                $t->string('obj_id', 20);
                $t->boolean('pflicht')->default(false);
                $t->timestamps();
                $t->unique(['vorlage_id', 'obj_id']);
                $t->index('obj_id');
            });
        }

        if (!$s->hasTable('checklisten_protokolle')) {
            $s->create('checklisten_protokolle', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('vorlage_id')->nullable();
                $t->string('vorlage_name', 190);
                $t->unsignedInteger('vorlage_version')->default(1);
                $t->string('kategorie', 30)->default('sonstiges');
                $t->json('felder');
                $t->json('antworten');
                $t->string('obj_id', 20)->nullable();
                $t->string('artikel_id', 20)->nullable();
                $t->string('einsatz_id', 30)->nullable();
                $t->date('datum');
                $t->decimal('ergebnis_prozent', 5, 2)->nullable();
                $t->unsignedInteger('maengel')->default(0);
                $t->decimal('gps_lat', 10, 7)->nullable();
                $t->decimal('gps_lng', 10, 7)->nullable();
                $t->unsignedInteger('gps_genauigkeit')->nullable();
                $t->string('unterschrift_kunde_pfad', 255)->nullable();
                $t->string('unterschrift_kunde_name', 120)->nullable();
                $t->string('unterschrift_ma_pfad', 255)->nullable();
                $t->string('client_uuid', 64)->nullable()->unique();
                $t->string('created_by_dienst_nr', 30)->nullable();
                $t->string('created_by', 255)->nullable();
                $t->timestamp('gestartet_at')->nullable();
                $t->timestamp('abgeschlossen_at')->nullable();
                $t->timestamps();
                $t->index(['obj_id', 'datum']);
                $t->index('einsatz_id');
                $t->index(['vorlage_id', 'datum']);
            });
        }

        if (!$s->hasTable('checklisten_fotos')) {
            $s->create('checklisten_fotos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('protokoll_id');
                $t->string('feld_id', 40);
                $t->string('pfad', 255);
                $t->timestamp('created_at')->nullable();
                $t->index('protokoll_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['checklisten_fotos', 'checklisten_protokolle', 'checklisten_zuordnungen', 'checklisten_vorlagen'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
