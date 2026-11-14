<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('preishistorie', function (Blueprint $table) {
            $table->id();
            $table->date('aenderungsdatum');
            $table->string('obj_id', 20);
            $table->string('objektname', 200)->nullable();
            $table->string('kunden_id', 20)->nullable();
            $table->decimal('alter_preis_kunde', 10, 2)->nullable();
            $table->decimal('neuer_preis_kunde', 10, 2)->nullable();
            $table->decimal('alter_nu_preis', 10, 2)->nullable();
            $table->decimal('neuer_nu_preis', 10, 2)->nullable();
            $table->text('grund')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::connection('tenant')->create('listen', function (Blueprint $table) {
            $table->id();
            $table->string('kategorie', 50);
            $table->string('wert', 200);
            $table->text('beschreibung')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->integer('sortierung')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::connection('tenant')->create('mitarbeiter_dokumente', function (Blueprint $table) {
            $table->id();
            $table->string('dienst_nr', 10);
            $table->string('titel', 200);
            $table->string('dateiname', 255);
            $table->string('dateipfad', 500);
            $table->integer('dateigroesse')->nullable();
            $table->date('ablaufdatum')->nullable();
            $table->timestamp('hochgeladen_am')->useCurrent();

            $table->foreign('dienst_nr')->references('dienst_nr')->on('mitarbeiter')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('mitarbeiter_abwesenheiten', function (Blueprint $table) {
            $table->id();
            $table->string('dienst_nr', 10);
            $table->string('typ', 50);
            $table->boolean('bezahlt')->default(false);
            $table->boolean('ganzer_tag')->default(true);
            $table->date('startdatum');
            $table->date('enddatum')->nullable();
            $table->string('startzeit', 10)->nullable();
            $table->string('endzeit', 10)->nullable();
            $table->decimal('tage', 5, 1)->default(1);
            $table->text('beschreibung')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('dienst_nr')->references('dienst_nr')->on('mitarbeiter')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('mitarbeiter_nachrichten', function (Blueprint $table) {
            $table->id();
            $table->string('dienst_nr', 10);
            $table->enum('richtung', ['worker', 'admin'])->default('worker');
            $table->text('nachricht');
            $table->boolean('gelesen')->default(false);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('datei_pfad', 500)->nullable();
            $table->string('datei_name', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('dienst_nr')->references('dienst_nr')->on('mitarbeiter')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('kunden_dokumente', function (Blueprint $table) {
            $table->id();
            $table->string('kunde_id', 20);
            $table->string('titel', 200);
            $table->string('dateiname', 255);
            $table->string('dateipfad', 500);
            $table->integer('dateigroesse')->nullable();
            $table->string('dateityp', 20)->nullable();
            $table->timestamp('hochgeladen_am')->useCurrent();

            $table->foreign('kunde_id')->references('kunde_id')->on('kunden')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('nu_dokumente', function (Blueprint $table) {
            $table->id();
            $table->string('nu_id', 20);
            $table->string('titel', 200);
            $table->string('dok_typ', 50)->nullable();
            $table->string('dateiname', 255);
            $table->string('dateipfad', 500);
            $table->integer('dateigroesse')->nullable();
            $table->string('faellig_typ', 30)->nullable();
            $table->timestamp('hochgeladen_am')->useCurrent();

            $table->foreign('nu_id')->references('nu_id')->on('nachunternehmer')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('nu_schicht_positionen', function (Blueprint $table) {
            $table->id();
            $table->string('einsatz_id', 30);
            $table->string('nu_id', 20);
            $table->integer('position_nr')->default(1);
            $table->string('position_name', 100);
            $table->integer('anzahl')->default(1);
            $table->string('qualifikation', 255)->nullable();
            $table->string('skills', 255)->nullable();
            $table->string('kleidung', 255)->nullable();
            $table->string('checkin_info', 255)->nullable();
            $table->text('bemerkung')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('einsatz_id', 'idx_nsp_einsatz');
            $table->index('nu_id', 'idx_nsp_nu');
        });

        Schema::connection('tenant')->create('nu_portal_mitarbeiter', function (Blueprint $table) {
            $table->id();
            $table->string('nu_id', 20);
            $table->string('vorname', 100)->nullable();
            $table->string('name', 100)->nullable();
            $table->string('personal_nr', 50)->nullable();
            $table->enum('geschlecht', ['m', 'w', 'd'])->nullable();
            $table->string('qualifikation', 255)->nullable();
            $table->string('skills', 255)->nullable();
            $table->string('telefon', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('bemerkung')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index('nu_id', 'idx_npm_nu');
        });

        // Chat system
        Schema::connection('tenant')->create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::connection('tenant')->create('chat_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();

            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_id');
            $table->text('message')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('chat_messages');
        Schema::connection('tenant')->dropIfExists('chat_participants');
        Schema::connection('tenant')->dropIfExists('chat_conversations');
        Schema::connection('tenant')->dropIfExists('nu_portal_mitarbeiter');
        Schema::connection('tenant')->dropIfExists('nu_schicht_positionen');
        Schema::connection('tenant')->dropIfExists('nu_dokumente');
        Schema::connection('tenant')->dropIfExists('kunden_dokumente');
        Schema::connection('tenant')->dropIfExists('mitarbeiter_nachrichten');
        Schema::connection('tenant')->dropIfExists('mitarbeiter_abwesenheiten');
        Schema::connection('tenant')->dropIfExists('mitarbeiter_dokumente');
        Schema::connection('tenant')->dropIfExists('listen');
        Schema::connection('tenant')->dropIfExists('preishistorie');
    }
};
