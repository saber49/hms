<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document renewal requests: when a worker document expires (or is about
 * to), HR requests a renewal. The worker uploads a replacement PDF through
 * the worker app; an HR user reviews it and either approves (the file
 * replaces / is added to mitarbeiter_dokumente) or rejects it (the worker
 * can re-upload).
 *
 * Status flow: angefordert -> hochgeladen -> genehmigt | abgelehnt
 * (abgelehnt allows a re-upload, i.e. back to hochgeladen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokument_anfragen')) {
            return;
        }

        Schema::connection('tenant')->create('mitarbeiter_dokument_anfragen', function (Blueprint $table) {
            $table->id();
            $table->string('dienst_nr', 30)->index();
            $table->unsignedBigInteger('dokument_id')->nullable(); // old doc being renewed
            $table->string('titel', 190);
            $table->text('nachricht')->nullable();                 // instructions for the worker
            $table->string('status', 20)->default('angefordert')->index(); // angefordert|hochgeladen|genehmigt|abgelehnt
            $table->string('dateiname', 190)->nullable();          // uploaded replacement
            $table->string('dateipfad', 255)->nullable();
            $table->unsignedBigInteger('dateigroesse')->nullable();
            $table->date('ablaufdatum_neu')->nullable();           // new expiry provided by worker
            $table->text('ablehnungsgrund')->nullable();
            $table->string('angefordert_von', 190)->nullable();    // HR user name or 'System'
            $table->timestamp('hochgeladen_am')->nullable();
            $table->string('geprueft_von', 190)->nullable();
            $table->timestamp('geprueft_am')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mitarbeiter_dokument_anfragen');
    }
};
