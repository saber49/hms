<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('bestelleingang', function (Blueprint $table) {
            $table->id();
            $table->string('eingang_id', 30)->unique();
            $table->string('kunden_id', 20);
            $table->string('obj_id', 20);
            $table->integer('monat');
            $table->integer('jahr');
            $table->string('zeit_von', 10)->nullable();
            $table->string('zeit_bis', 10)->nullable();
            $table->string('modell', 50)->nullable();
            $table->decimal('kundenpreis', 10, 2)->nullable();
            $table->string('nu_id', 20)->nullable();
            $table->decimal('nu_preis', 10, 2)->nullable();
            $table->text('bemerkung')->nullable();
            $table->string('status', 30)->default('unbearbeitet');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('kunden_id')->references('kunde_id')->on('kunden')->cascadeOnUpdate();
            $table->foreign('obj_id')->references('obj_id')->on('objekte')->cascadeOnUpdate();
            $table->foreign('nu_id')->references('nu_id')->on('nachunternehmer')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::connection('tenant')->create('bestelleingang_tage', function (Blueprint $table) {
            $table->id();
            $table->string('eingang_id', 30);
            $table->integer('tag_nr');
            $table->date('datum');
            $table->string('wochentag', 12)->nullable();
            $table->string('zeit_von', 10)->nullable();
            $table->string('zeit_bis', 10)->nullable();
            $table->decimal('soll_std', 8, 2)->nullable();
            $table->integer('gesetzl_pause')->nullable();
            $table->decimal('menge', 8, 2)->nullable();
            $table->text('beschreibung')->nullable();
            $table->string('status', 20)->default('offen');
            $table->text('bemerkung')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('eingang_id')->references('eingang_id')->on('bestelleingang')->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::connection('tenant')->create('bestellungen', function (Blueprint $table) {
            $table->id();
            $table->string('bestell_id', 30)->unique();
            $table->string('eingang_id', 30)->nullable();
            $table->string('kunden_id', 20);
            $table->string('obj_id', 20);
            $table->string('kunden_obj_nr', 50)->nullable();
            $table->integer('monat')->nullable();
            $table->integer('jahr')->nullable();
            $table->date('datum_von')->nullable();
            $table->date('datum_bis')->nullable();
            $table->string('zeit_von', 10)->nullable();
            $table->string('zeit_bis', 10)->nullable();
            $table->string('menge_einheit', 50)->nullable();
            $table->decimal('soll_std_betrag', 10, 2)->nullable();
            $table->string('status', 20)->default('offen');
            $table->string('nu_id', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('eingang_id')->references('eingang_id')->on('bestelleingang')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('kunden_id')->references('kunde_id')->on('kunden')->cascadeOnUpdate();
            $table->foreign('obj_id')->references('obj_id')->on('objekte')->cascadeOnUpdate();
            $table->foreign('nu_id')->references('nu_id')->on('nachunternehmer')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::connection('tenant')->create('einsaetze', function (Blueprint $table) {
            $table->id();
            $table->string('einsatz_id', 30)->unique();
            $table->string('bestell_id', 30);
            $table->string('kunden_id', 20)->nullable();
            $table->string('obj_id', 20)->nullable();
            $table->string('nu_id', 20)->nullable();
            $table->date('datum');
            $table->integer('tag_nr')->nullable();
            $table->text('beschreibung')->nullable();
            $table->string('ist_start', 10)->nullable();
            $table->string('ist_ende', 10)->nullable();
            $table->decimal('ist_std_menge', 8, 2)->nullable();
            $table->decimal('ist_betrag', 10, 2)->nullable();
            $table->integer('pause_minuten')->nullable();
            $table->integer('gesetzl_pause')->nullable();
            $table->string('hms_bestaetigt', 20)->default('ausstehend');
            $table->string('pruefung', 20)->nullable();
            $table->text('bemerkung')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('bestell_id')->references('bestell_id')->on('bestellungen')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('kunden_id')->references('kunde_id')->on('kunden')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('obj_id')->references('obj_id')->on('objekte')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('nu_id')->references('nu_id')->on('nachunternehmer')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::connection('tenant')->create('einsatz_mitarbeiter', function (Blueprint $table) {
            $table->id();
            $table->string('einsatz_id', 30);
            $table->string('dienst_nr', 10);
            $table->decimal('ist_std', 8, 2)->nullable();
            $table->integer('sortierung')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['einsatz_id', 'dienst_nr'], 'uk_einsatz_worker');
            $table->index('dienst_nr', 'idx_em_dienst_nr');

            $table->foreign('einsatz_id')->references('einsatz_id')->on('einsaetze')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('dienst_nr')->references('dienst_nr')->on('mitarbeiter')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('einsatz_mitarbeiter');
        Schema::connection('tenant')->dropIfExists('einsaetze');
        Schema::connection('tenant')->dropIfExists('bestellungen');
        Schema::connection('tenant')->dropIfExists('bestelleingang_tage');
        Schema::connection('tenant')->dropIfExists('bestelleingang');
    }
};
