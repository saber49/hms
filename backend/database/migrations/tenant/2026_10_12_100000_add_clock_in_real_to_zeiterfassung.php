<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_in_real')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                // Real moment the worker tapped "Start" (UTC). clock_in holds the
                // counted start: an early tap is snapped to the planned start.
                $table->dateTime('clock_in_real')->nullable()->after('clock_in');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_in_real')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                $table->dropColumn('clock_in_real');
            });
        }
    }
};
