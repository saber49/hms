<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('zeiterfassung', function (Blueprint $table) {
            $table->id();
            $table->string('einsatz_id', 30);
            $table->string('dienst_nr', 10);
            $table->dateTime('clock_in');
            $table->dateTime('clock_out')->nullable();
            $table->enum('status', ['aktiv', 'pause', 'beendet'])->default('aktiv');
            $table->integer('pause_total')->default(0)->comment('Total pause in minutes');
            $table->string('geo_in', 50)->nullable();
            $table->string('geo_out', 50)->nullable();
            $table->text('notiz')->nullable();
            $table->timestamps();

            $table->index('dienst_nr', 'idx_zeit_dienst');
            $table->index('einsatz_id', 'idx_zeit_einsatz');
            $table->index('status', 'idx_zeit_status');
            $table->foreign('dienst_nr')->references('dienst_nr')->on('mitarbeiter')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('zeiterfassung');
    }
};
