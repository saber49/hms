<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('zeiterfassung')
            || Schema::connection('tenant')->hasIndex('zeiterfassung', 'uq_zeit_einsatz_dienst')) return;

        // One clock record per worker and shift (WorkerAppZeitController::clockIn
        // already refuses a second one; this closes the double-tap race).
        // Existing duplicates are never deleted automatically — the index is
        // skipped and logged so they can be reviewed by hand.
        $dupes = DB::connection('tenant')->table('zeiterfassung')
            ->select('einsatz_id', 'dienst_nr')->groupBy('einsatz_id', 'dienst_nr')
            ->havingRaw('COUNT(*) > 1')->count();
        if ($dupes > 0) {
            Log::warning('zeiterfassung: unique index skipped, duplicates present', [
                'database' => DB::connection('tenant')->getDatabaseName(), 'pairs' => $dupes,
            ]);
            return;
        }

        Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
            $table->unique(['einsatz_id', 'dienst_nr'], 'uq_zeit_einsatz_dienst');
        });
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasIndex('zeiterfassung', 'uq_zeit_einsatz_dienst')) {
            Schema::connection('tenant')->table('zeiterfassung', function (Blueprint $table) {
                $table->dropUnique('uq_zeit_einsatz_dienst');
            });
        }
    }
};
