<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengerung_gruende')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                // JSON list of the worker's reasons, one per "Verlängern":
                // [{"am": UTC tap, "bis": UTC new end, "grund": text}, …]
                $table->text('verlaengerung_gruende')->nullable()->after('verlaengerungen');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengerung_gruende')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                $table->dropColumn('verlaengerung_gruende');
            });
        }
    }
};
