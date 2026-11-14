<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objekt-Terminal (tablet at the gate) + personal 4-digit PIN per worker.
 *
 *  - qm_terminals           one per Objekt: readable `kennung`, 6-digit PIN
 *    (hash only), lock after 5 failures.
 *  - qm_terminal_sitzungen  12 h sessions (token hash); the guard currently
 *    signed in on the device (dienst_nr) is part of the session.
 *  - qm_mitarbeiter_pins    personal PIN (hash only), start PIN must be
 *    changed on first use, lock after 3 failures.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection('tenant');

        if (!$s->hasTable('qm_terminals')) {
            $s->create('qm_terminals', function (Blueprint $t) {
                $t->id();
                $t->string('obj_id', 20)->unique();
                $t->string('kennung', 40)->unique();
                $t->string('pin_hash', 255)->nullable();
                $t->boolean('aktiv')->default(true);
                $t->unsignedTinyInteger('fehlversuche')->default(0);
                $t->timestamp('gesperrt_seit')->nullable();
                $t->timestamp('letzte_anmeldung')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!$s->hasTable('qm_terminal_sitzungen')) {
            $s->create('qm_terminal_sitzungen', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('terminal_id')->index();
                $t->char('token_hash', 64)->unique();
                $t->string('dienst_nr', 10)->nullable();
                $t->timestamp('wachperson_seit')->nullable();
                $t->timestamp('start')->useCurrent();
                $t->timestamp('laeuft_ab');
                $t->timestamp('ende')->nullable();
                $t->string('geraet', 255)->nullable();
                $t->string('ip', 45)->nullable();
            });
        }

        if (!$s->hasTable('qm_mitarbeiter_pins')) {
            $s->create('qm_mitarbeiter_pins', function (Blueprint $t) {
                $t->id();
                $t->string('dienst_nr', 10)->unique();
                $t->string('pin_hash', 255);
                $t->unsignedTinyInteger('fehlversuche')->default(0);
                $t->boolean('gesperrt')->default(false);
                $t->boolean('start_pin')->default(true);
                $t->timestamp('geaendert_am')->nullable();
                $t->string('geaendert_von', 200)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['qm_mitarbeiter_pins', 'qm_terminal_sitzungen', 'qm_terminals'] as $t) {
            Schema::connection('tenant')->dropIfExists($t);
        }
    }
};
