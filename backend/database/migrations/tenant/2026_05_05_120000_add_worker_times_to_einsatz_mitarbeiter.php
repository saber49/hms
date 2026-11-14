<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')) {
            return;
        }

        Schema::connection('tenant')->table('einsatz_mitarbeiter', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'ist_start')) {
                $table->string('ist_start', 10)->nullable()->after('ist_std');
            }
            if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'ist_ende')) {
                $table->string('ist_ende', 10)->nullable()->after('ist_start');
            }
            if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'pause_minuten')) {
                $table->integer('pause_minuten')->nullable()->after('ist_ende');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')) {
            return;
        }

        Schema::connection('tenant')->table('einsatz_mitarbeiter', function (Blueprint $table) {
            foreach (['ist_start', 'ist_ende', 'pause_minuten'] as $col) {
                if (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
