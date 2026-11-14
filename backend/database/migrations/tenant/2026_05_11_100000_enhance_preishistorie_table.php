<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Enhance preishistorie:
 *  - add indexes for fast filtering by date / kunde / obj
 *  - add geaendert_von (user id who made the change)
 *  - add aenderung_typ ('create' | 'update' | 'manual') for analytics
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('preishistorie')) {
            return;
        }

        // 1) Add new columns if missing
        Schema::connection('tenant')->table('preishistorie', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('preishistorie', 'geaendert_von')) {
                $table->unsignedBigInteger('geaendert_von')->nullable()->after('grund');
            }
            if (!Schema::connection('tenant')->hasColumn('preishistorie', 'aenderung_typ')) {
                $table->string('aenderung_typ', 20)->default('update')->after('geaendert_von');
            }
        });

        // 2) Add indexes (idempotent — only if not already present)
        $existing = collect(DB::connection('tenant')->select("SHOW INDEX FROM preishistorie"))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('preishistorie', function (Blueprint $table) use ($existing) {
            if (!in_array('preishistorie_aenderungsdatum_index', $existing)) {
                $table->index('aenderungsdatum', 'preishistorie_aenderungsdatum_index');
            }
            if (!in_array('preishistorie_obj_id_index', $existing)) {
                $table->index('obj_id', 'preishistorie_obj_id_index');
            }
            if (!in_array('preishistorie_kunden_id_index', $existing)) {
                $table->index('kunden_id', 'preishistorie_kunden_id_index');
            }
            if (!in_array('preishistorie_kunde_date_index', $existing)) {
                $table->index(['kunden_id', 'aenderungsdatum'], 'preishistorie_kunde_date_index');
            }
            if (!in_array('preishistorie_obj_date_index', $existing)) {
                $table->index(['obj_id', 'aenderungsdatum'], 'preishistorie_obj_date_index');
            }
            if (!in_array('preishistorie_aenderung_typ_index', $existing)) {
                $table->index('aenderung_typ', 'preishistorie_aenderung_typ_index');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('preishistorie')) {
            return;
        }

        $existing = collect(DB::connection('tenant')->select("SHOW INDEX FROM preishistorie"))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('preishistorie', function (Blueprint $table) use ($existing) {
            foreach ([
                'preishistorie_aenderungsdatum_index',
                'preishistorie_obj_id_index',
                'preishistorie_kunden_id_index',
                'preishistorie_kunde_date_index',
                'preishistorie_obj_date_index',
                'preishistorie_aenderung_typ_index',
            ] as $idx) {
                if (in_array($idx, $existing)) {
                    $table->dropIndex($idx);
                }
            }

            if (Schema::connection('tenant')->hasColumn('preishistorie', 'aenderung_typ')) {
                $table->dropColumn('aenderung_typ');
            }
            if (Schema::connection('tenant')->hasColumn('preishistorie', 'geaendert_von')) {
                $table->dropColumn('geaendert_von');
            }
        });
    }
};
