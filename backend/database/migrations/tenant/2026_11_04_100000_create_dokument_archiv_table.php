<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDF archive of issued documents (DokumentArchivController): one row per
 * download / print / send; rows with the same content share version + file.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if ($s->hasTable('dokument_archiv')) return;

        $s->create('dokument_archiv', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 10);
            $table->string('ref_id', 130);
            $table->string('label', 190)->nullable();
            $table->unsignedInteger('version');
            $table->string('aktion', 16);
            $table->char('content_hash', 64);
            $table->string('pdf_path', 255);
            $table->unsignedInteger('file_size')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 190)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['doc_type', 'ref_id', 'version'], 'idx_dokarchiv_ref');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('dokument_archiv');
    }
};
