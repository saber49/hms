<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('einsatz_nu_status')) {
            return;
        }
        Schema::connection('tenant')->create('einsatz_nu_status', function (Blueprint $table) {
            $table->id();
            $table->string('einsatz_id', 50);
            $table->string('nu_id', 20);
            $table->string('status', 30)->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();

            $table->unique(['einsatz_id', 'nu_id'], 'uniq_einsatz_nu');
            $table->index('einsatz_id', 'idx_ens_einsatz');
            $table->index('nu_id', 'idx_ens_nu');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('einsatz_nu_status');
    }
};
