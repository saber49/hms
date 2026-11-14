<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('nu_position_zuweisungen')) {
            return;
        }
        Schema::connection('tenant')->create('nu_position_zuweisungen', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('nu_mitarbeiter_id');
            $table->time('ist_start')->nullable();
            $table->time('ist_ende')->nullable();
            $table->integer('pause_minuten')->nullable();
            $table->decimal('ist_std', 8, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['position_id', 'nu_mitarbeiter_id'], 'uniq_pos_worker');
            $table->index('position_id', 'idx_npz_position');
            $table->index('nu_mitarbeiter_id', 'idx_npz_worker');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('nu_position_zuweisungen');
    }
};
