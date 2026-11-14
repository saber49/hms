<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) return;
        if (Schema::connection('tenant')->hasColumn('einsaetze', 'nu_status')) return;

        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            $table->string('nu_status', 20)->nullable()->after('nu_id');
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsaetze')) return;
        if (!Schema::connection('tenant')->hasColumn('einsaetze', 'nu_status')) return;

        Schema::connection('tenant')->table('einsaetze', function (Blueprint $table) {
            $table->dropColumn('nu_status');
        });
    }
};
