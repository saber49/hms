<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker e-signatures on documents (DokumentSignaturService): one row per
 * signature request sent to a worker's app. `daten` is the frozen content
 * the worker saw (his own rows only) — the signature is valid for exactly
 * those rows.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasTable('dokument_signaturen')) return;

        $s->create('dokument_signaturen', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 10);
            $table->string('ref_id', 130);
            $table->unsignedBigInteger('mitarbeiter_id');
            $table->string('dienst_nr', 40);
            // offen | signiert | abgelehnt | ersetzt | zurueckgezogen
            $table->string('status', 16)->default('offen');
            $table->string('titel', 190);
            $table->json('daten');
            $table->char('daten_hash', 64);
            $table->string('signatur_pfad', 255)->nullable();
            $table->char('signatur_hash', 64)->nullable();
            $table->timestamp('signiert_am')->nullable();
            $table->string('signatur_ip', 45)->nullable();
            $table->string('signatur_geraet', 255)->nullable();
            $table->string('ablehn_grund', 500)->nullable();
            $table->timestamp('abgelehnt_am')->nullable();
            $table->unsignedBigInteger('angefragt_von')->nullable();
            $table->string('angefragt_von_name', 190)->nullable();
            $table->timestamp('erinnert_am')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->index(['doc_type', 'ref_id', 'dienst_nr'], 'idx_doksig_ref');
            $table->index(['mitarbeiter_id', 'status'], 'idx_doksig_worker');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('dokument_signaturen');
    }
};
