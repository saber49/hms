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
        // 1) nachunternehmer: add updated_at + helpful indexes
        if (!Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at')) {
            Schema::connection('tenant')->table('nachunternehmer', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            });

            DB::connection('tenant')
                ->table('nachunternehmer')
                ->whereNull('updated_at')
                ->update(['updated_at' => DB::raw('created_at')]);
        }

        $nuIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM nachunternehmer"))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('nachunternehmer', function (Blueprint $table) use ($nuIdx) {
            if (!in_array('nachunternehmer_aktiv_index', $nuIdx)) {
                $table->index('aktiv', 'nachunternehmer_aktiv_index');
            }
            if (!in_array('nachunternehmer_bereich_index', $nuIdx)) {
                $table->index('bereich', 'nachunternehmer_bereich_index');
            }
            if (!in_array('nachunternehmer_firmenname_index', $nuIdx)) {
                $table->index('firmenname', 'nachunternehmer_firmenname_index');
            }
        });

        // 2) nu_dokumente: add validity period + uploaded_by tracking + index
        if (!Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_ab')) {
            Schema::connection('tenant')->table('nu_dokumente', function (Blueprint $table) {
                $table->date('gueltig_ab')->nullable()->after('faellig_typ');
            });
        }
        if (!Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis')) {
            Schema::connection('tenant')->table('nu_dokumente', function (Blueprint $table) {
                $table->date('gueltig_bis')->nullable()->after('gueltig_ab');
            });
        }
        if (!Schema::connection('tenant')->hasColumn('nu_dokumente', 'bemerkung')) {
            Schema::connection('tenant')->table('nu_dokumente', function (Blueprint $table) {
                $table->string('bemerkung', 500)->nullable()->after('gueltig_bis');
            });
        }

        $docIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM nu_dokumente"))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('nu_dokumente', function (Blueprint $table) use ($docIdx) {
            if (!in_array('nu_dokumente_dok_typ_index', $docIdx)) {
                $table->index('dok_typ', 'nu_dokumente_dok_typ_index');
            }
            if (!in_array('nu_dokumente_gueltig_bis_index', $docIdx)) {
                $table->index('gueltig_bis', 'nu_dokumente_gueltig_bis_index');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('nu_dokumente', function (Blueprint $table) {
            $idx = collect(DB::connection('tenant')->select("SHOW INDEX FROM nu_dokumente"))
                ->pluck('Key_name')->toArray();
            if (in_array('nu_dokumente_dok_typ_index', $idx)) $table->dropIndex('nu_dokumente_dok_typ_index');
            if (in_array('nu_dokumente_gueltig_bis_index', $idx)) $table->dropIndex('nu_dokumente_gueltig_bis_index');

            if (Schema::connection('tenant')->hasColumn('nu_dokumente', 'bemerkung')) $table->dropColumn('bemerkung');
            if (Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis')) $table->dropColumn('gueltig_bis');
            if (Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_ab')) $table->dropColumn('gueltig_ab');
        });

        Schema::connection('tenant')->table('nachunternehmer', function (Blueprint $table) {
            $idx = collect(DB::connection('tenant')->select("SHOW INDEX FROM nachunternehmer"))
                ->pluck('Key_name')->toArray();
            if (in_array('nachunternehmer_aktiv_index', $idx)) $table->dropIndex('nachunternehmer_aktiv_index');
            if (in_array('nachunternehmer_bereich_index', $idx)) $table->dropIndex('nachunternehmer_bereich_index');
            if (in_array('nachunternehmer_firmenname_index', $idx)) $table->dropIndex('nachunternehmer_firmenname_index');

            if (Schema::connection('tenant')->hasColumn('nachunternehmer', 'updated_at')) $table->dropColumn('updated_at');
        });
    }
};
