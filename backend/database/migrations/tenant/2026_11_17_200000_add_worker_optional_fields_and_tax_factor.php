<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            $table->json('nicht_vorhanden')->nullable();
            $table->decimal('steuer_faktor', 5, 3)->nullable();
        });
        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $table) {
            $table->boolean('zeit_freigabe')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            $table->dropColumn(['nicht_vorhanden', 'steuer_faktor']);
        });
        Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $table) {
            $table->dropColumn('zeit_freigabe');
        });
    }
};
