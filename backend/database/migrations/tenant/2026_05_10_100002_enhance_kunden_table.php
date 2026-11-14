<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // 1) Add updated_at to kunden if missing
        if (!Schema::connection('tenant')->hasColumn('kunden', 'updated_at')) {
            Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            });

            // Backfill so existing rows have a sensible "last touched" stamp
            DB::connection('tenant')
                ->table('kunden')
                ->whereNull('updated_at')
                ->update(['updated_at' => DB::raw('created_at')]);
        }

        // 2) Helpful indexes for filtering / sorting
        Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
            $indexes = collect(DB::connection('tenant')
                ->select("SHOW INDEX FROM kunden"))
                ->pluck('Key_name')
                ->toArray();

            if (!in_array('kunden_aktiv_index', $indexes)) {
                $table->index('aktiv', 'kunden_aktiv_index');
            }
            if (!in_array('kunden_bereich_index', $indexes)) {
                $table->index('bereich', 'kunden_bereich_index');
            }
            if (!in_array('kunden_kundenname_index', $indexes)) {
                $table->index('kundenname', 'kunden_kundenname_index');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('kunden', function (Blueprint $table) {
            $indexes = collect(DB::connection('tenant')
                ->select("SHOW INDEX FROM kunden"))
                ->pluck('Key_name')
                ->toArray();

            if (in_array('kunden_aktiv_index', $indexes)) {
                $table->dropIndex('kunden_aktiv_index');
            }
            if (in_array('kunden_bereich_index', $indexes)) {
                $table->dropIndex('kunden_bereich_index');
            }
            if (in_array('kunden_kundenname_index', $indexes)) {
                $table->dropIndex('kunden_kundenname_index');
            }

            if (Schema::connection('tenant')->hasColumn('kunden', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};
