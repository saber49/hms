<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schichtbörse (shift exchange).
 *
 *   schicht_angebote  — an offer on the exchange:
 *       typ 'offen'   = the office published an understaffed shift
 *       typ 'abgabe'  = a worker wants to hand over his own shift
 *   schicht_anfragen  — a worker asks to take an offer; the office approves
 *                       or rejects (nothing changes in the plan before that).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('schicht_angebote')) {
            $s->create('schicht_angebote', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('einsatz_id', 30);
                $table->string('typ', 10);                          // offen | abgabe
                $table->string('anbieter_dienst_nr', 10)->nullable(); // typ abgabe
                $table->string('status', 20)->default('aktiv');    // aktiv | vergeben | zurueckgezogen
                $table->string('notiz', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable(); // central user (typ offen)
                $table->timestamps();
                $table->index(['einsatz_id', 'status']);
                $table->index(['status', 'typ']);
            });
        }
        if (!$s->hasTable('schicht_anfragen')) {
            $s->create('schicht_anfragen', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('angebot_id');
                $table->string('dienst_nr', 10);
                $table->string('status', 20)->default('angefragt'); // angefragt | genehmigt | abgelehnt | zurueckgezogen
                $table->string('grund', 500)->nullable();
                $table->unsignedBigInteger('entschieden_von')->nullable();
                $table->string('entschieden_von_name', 200)->nullable();
                $table->dateTime('entschieden_am')->nullable();
                $table->timestamps();
                $table->index(['angebot_id', 'status']);
                $table->index(['dienst_nr', 'status']);
                $table->foreign('angebot_id')->references('id')->on('schicht_angebote')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('schicht_anfragen');
        Schema::connection($this->connection)->dropIfExists('schicht_angebote');
    }
};
