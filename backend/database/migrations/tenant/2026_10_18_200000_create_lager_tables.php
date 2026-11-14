<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lagerverwaltung / Materialwirtschaft — company warehouse for equipment
 * and supplies (Dienstkleidung, Funkgeräte, Zutrittsmittel, ...).
 *
 * Three tables in each tenant DB:
 *   lager_kategorien — user-defined categories (e.g. Kleidung, Funkgeräte)
 *   lager_artikel    — the items themselves, incl. stock + min stock level
 *   lager_ausgaben   — issue/return log: which worker got how many, when,
 *                      and by whom (DISPONIC-style Ressourcenverfolgung)
 *
 * `mitarbeiter_dienst_nr` references mitarbeiter.dienst_nr (same tenant DB).
 * `ausgegeben_von` / `rueckgabe_von` reference central_users.id (cross-DB,
 * therefore a plain bigint — consistent with kunden.betreuer_id).
 *
 * `menge_gesamt` is the owned quantity; the *available* quantity shown in
 * the UI is derived as menge_gesamt minus open (nicht zurückgegebene)
 * lager_ausgaben rows — no denormalised counter that can drift.
 *
 * Idempotent: re-running creates nothing that already exists.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('lager_kategorien')) {
            Schema::connection($this->connection)->create('lager_kategorien', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 100);
                $table->string('icon', 50)->default('category');
                $table->string('farbe', 20)->nullable();
                $table->unsignedInteger('sortierung')->default(0);
                $table->boolean('aktiv')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('lager_artikel')) {
            Schema::connection($this->connection)->create('lager_artikel', function (Blueprint $table) {
                $table->bigIncrements('artikel_id');
                $table->unsignedBigInteger('kategorie_id')->nullable();
                $table->string('name', 200);
                $table->string('art_nr', 40)->nullable();
                $table->string('groesse', 20)->nullable();
                $table->string('seriennummer', 100)->nullable();
                $table->string('zustand', 30)->default('neu');
                $table->unsignedInteger('menge_gesamt')->default(0);
                $table->unsignedInteger('mindestbestand')->default(0);
                $table->string('lagerort', 150)->nullable();
                $table->text('bemerkung')->nullable();
                $table->boolean('aktiv')->default(true);
                $table->timestamps();

                $table->index('kategorie_id');
                $table->index('zustand');
                $table->index('aktiv');
                $table->foreign('kategorie_id')->references('id')->on('lager_kategorien')->nullOnDelete();
            });
        }

        if (!Schema::connection($this->connection)->hasTable('lager_ausgaben')) {
            Schema::connection($this->connection)->create('lager_ausgaben', function (Blueprint $table) {
                $table->bigIncrements('ausgabe_id');
                $table->unsignedBigInteger('artikel_id');
                $table->string('mitarbeiter_dienst_nr', 10);
                $table->string('mitarbeiter_name', 200);
                $table->unsignedInteger('menge')->default(1);
                $table->dateTime('ausgegeben_am');
                $table->unsignedBigInteger('ausgegeben_von')->nullable();
                $table->dateTime('rueckgabe_am')->nullable();
                $table->unsignedBigInteger('rueckgabe_von')->nullable();
                $table->string('status', 20)->default('offen');
                $table->text('bemerkung')->nullable();
                $table->timestamps();

                $table->index('artikel_id');
                $table->index('mitarbeiter_dienst_nr');
                $table->index('status');
                $table->foreign('artikel_id')->references('artikel_id')->on('lager_artikel')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('lager_ausgaben');
        Schema::connection($this->connection)->dropIfExists('lager_artikel');
        Schema::connection($this->connection)->dropIfExists('lager_kategorien');
    }
};
