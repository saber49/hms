<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('objekte', 'pause_berechnen')) return;

        Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
            // 1 = customer pays the pause (full post coverage, legacy default);
            // 0 = Leistungsnachweis bills net time only.
            $table->tinyInteger('pause_berechnen')->unsigned()->default(1);
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasColumn('objekte', 'pause_berechnen')) return;
        Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
            $table->dropColumn('pause_berechnen');
        });
    }
};
