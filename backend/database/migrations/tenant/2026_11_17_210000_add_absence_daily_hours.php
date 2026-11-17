<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $table) {
            $table->json('stunden_korrekturen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $table) {
            $table->dropColumn('stunden_korrekturen');
        });
    }
};
