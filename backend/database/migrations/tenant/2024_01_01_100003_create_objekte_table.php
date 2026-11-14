<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('objekte', function (Blueprint $table) {
            $table->id();
            $table->string('obj_id', 20)->unique();
            $table->string('kunden_id', 20);
            $table->string('obj_nr_kunde', 50)->nullable();
            $table->string('obj_nr_intern', 50)->nullable()->unique();
            $table->string('objektname', 200);
            $table->string('bereich', 50)->nullable();
            $table->string('modell', 50)->default('Pro Einsatz');
            $table->decimal('preis_kunde', 10, 2)->default(0);
            $table->decimal('nu_preis', 10, 2)->default(0);
            $table->decimal('gewinn', 10, 2)->default(0);
            $table->boolean('aktiv')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('kunden_id')->references('kunde_id')->on('kunden')->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('objekte');
    }
};
