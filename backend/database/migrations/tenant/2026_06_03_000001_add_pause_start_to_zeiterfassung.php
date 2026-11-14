<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                $table->dateTime('pause_start')->nullable()->after('pause_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                $table->dropColumn('pause_start');
            });
        }
    }
};
