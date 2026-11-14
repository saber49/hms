<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengert_bis')) {
                // Effective end after the worker tapped "Verlängern" (UTC); NULL = planned end.
                $table->dateTime('verlaengert_bis')->nullable()->after('pause_start');
            }
            if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengerungen')) {
                $table->unsignedTinyInteger('verlaengerungen')->default(0)->after('verlaengert_bis');
            }
            if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'ende_vorwarnung_at')) {
                // "Schicht endet in 10 Min" — sent once per shift.
                $table->dateTime('ende_vorwarnung_at')->nullable()->after('verlaengerungen');
            }
            if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'ende_erinnerung_at')) {
                // "Beenden oder verlängern?" — sent at every (extended) end; the
                // notifier compares it with the current end to avoid repeats.
                $table->dateTime('ende_erinnerung_at')->nullable()->after('ende_vorwarnung_at');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
            foreach (['ende_erinnerung_at', 'ende_vorwarnung_at', 'verlaengerungen', 'verlaengert_bis'] as $c) {
                if (Schema::connection('tenant')->hasColumn('zeiterfassung', $c)) $table->dropColumn($c);
            }
        });
    }
};
