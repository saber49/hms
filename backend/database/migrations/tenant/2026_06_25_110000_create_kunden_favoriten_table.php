<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('kunden_favoriten')) {
            Schema::connection('tenant')->create('kunden_favoriten', function (Blueprint $table) {
                $table->id();
                $table->string('kunde_id', 20);
                $table->string('dienst_nr', 50);
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['kunde_id', 'dienst_nr'], 'kunden_favoriten_unique');
                $table->index('kunde_id', 'kunden_favoriten_kunde_index');
                $table->index('dienst_nr', 'kunden_favoriten_dienst_index');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('kunden_favoriten');
    }
};
