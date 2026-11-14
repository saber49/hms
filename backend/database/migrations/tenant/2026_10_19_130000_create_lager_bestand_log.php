<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock change log of the warehouse: every change of lager_artikel.menge_gesamt
 * (new article, Zugang, Abgang, Inventur …) with old/new value, reason and the
 * office user (central_users.id + name snapshot).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('lager_bestand_log')) return;
        Schema::connection($this->connection)->create('lager_bestand_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('artikel_id');
            $table->integer('menge_alt');
            $table->integer('menge_neu');
            $table->string('grund', 500)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 200)->nullable();
            $table->dateTime('created_at');
            $table->index('artikel_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('lager_bestand_log');
    }
};
