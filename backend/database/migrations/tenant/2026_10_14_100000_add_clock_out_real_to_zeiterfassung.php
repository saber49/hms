<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_out_real')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                // Real moment the worker tapped "Beenden" (UTC). clock_out holds the
                // counted end: a tap after the planned/extended end is capped to it.
                $table->dateTime('clock_out_real')->nullable()->after('clock_out');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_out_real')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                $table->dropColumn('clock_out_real');
            });
        }
    }
};
