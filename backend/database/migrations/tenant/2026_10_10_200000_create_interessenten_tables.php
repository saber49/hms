<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interessentenverwaltung — prospect/lead management (see specification
 * "Interessentenverwaltung"). Lives under Stammdaten → Interessenten and is
 * available to every tenant.
 *
 * Two tables in each tenant DB:
 *   interessenten             — the prospect master record + Akquise status
 *   interessent_kommunikation — 1:n communication log per prospect
 *
 * `zustaendiger_mitarbeiter` references central_users.id (cross-database,
 * therefore a plain bigint — consistent with kunden.betreuer_id).
 *
 * `konvertiert_zu` links to kunden.kunde_id after "Zu Kunde konvertieren" —
 * the prospect is preserved for reporting instead of being deleted.
 *
 * Idempotent: re-running creates nothing that already exists.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('interessenten')) {
            Schema::connection($this->connection)->create('interessenten', function (Blueprint $table) {
                $table->string('interessent_id', 20)->primary();
                $table->string('firmenname', 200);
                $table->string('branche', 50);
                $table->string('mitarbeiterzahl_bereich', 20)->nullable();
                $table->string('einsatzgebiet', 150)->nullable();
                $table->string('ansprechpartner_name', 150);
                $table->string('ansprechpartner_position', 150)->nullable();
                $table->string('telefon', 60)->nullable();
                $table->string('email', 200)->nullable();
                $table->string('quelle', 50);
                $table->unsignedBigInteger('zustaendiger_mitarbeiter');
                $table->string('pipeline_phase', 30)->default('Erstkontakt');
                $table->string('voraussichtlicher_tarif', 60)->nullable();
                $table->text('naechster_schritt')->nullable();
                $table->date('wiedervorlage_am')->nullable();
                $table->string('konvertiert_zu', 20)->nullable();
                $table->timestamps();

                $table->index('pipeline_phase');
                $table->index('zustaendiger_mitarbeiter');
                $table->index('wiedervorlage_am');
            });
        }

        if (!Schema::connection($this->connection)->hasTable('interessent_kommunikation')) {
            Schema::connection($this->connection)->create('interessent_kommunikation', function (Blueprint $table) {
                $table->bigIncrements('eintrag_id');
                $table->string('interessent_id', 20);
                $table->string('typ', 30);
                $table->dateTime('datum');
                $table->text('notiz');
                $table->unsignedBigInteger('erfasst_von');
                $table->string('datei_pfad', 255)->nullable();
                $table->string('datei_name', 255)->nullable();
                $table->timestamps();

                $table->index('interessent_id');
                $table->index('datum');
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('interessent_kommunikation');
        Schema::connection($this->connection)->dropIfExists('interessenten');
    }
};
