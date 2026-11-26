<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokument_versionen')) return;

        Schema::connection('tenant')->create('mitarbeiter_dokument_versionen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dokument_id')->index();
            $table->string('dienst_nr', 30)->index();
            $table->string('titel', 200);
            $table->string('dateiname', 255);
            $table->string('dateipfad', 500);
            $table->unsignedBigInteger('dateigroesse')->nullable();
            $table->char('pruefsumme', 64);
            $table->date('ablaufdatum')->nullable();
            $table->timestamp('hochgeladen_am')->nullable();
            $table->timestamp('archiviert_am');
            $table->string('archiviert_von', 190);
            $table->unsignedBigInteger('anfrage_id');
            $table->unique(['dokument_id', 'anfrage_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mitarbeiter_dokument_versionen');
    }
};
