<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Enhance mitarbeiter & related tables:
 *  - mitarbeiter: drop password_plain (security), add legal/HR fields,
 *    add performance indexes
 *  - mitarbeiter_dokumente: add dok_typ, gueltig_ab, pflicht + index ablaufdatum
 *  - mitarbeiter_abwesenheiten: add composite index (dienst_nr, startdatum),
 *    convert startzeit/endzeit varchar(10) to TIME
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // ---------- 1) mitarbeiter ----------
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            // Legal/HR fields
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'geburtstag')) {
                $table->date('geburtstag')->nullable()->after('vorname');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'geburtsort')) {
                $table->string('geburtsort', 100)->nullable()->after('geburtstag');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'staatsangehoerigkeit')) {
                $table->string('staatsangehoerigkeit', 50)->nullable()->after('geburtsort');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'strasse')) {
                $table->string('strasse', 200)->nullable()->after('telefon');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'plz')) {
                $table->string('plz', 10)->nullable()->after('strasse');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'ort')) {
                $table->string('ort', 100)->nullable()->after('plz');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'austritt')) {
                $table->date('austritt')->nullable()->after('eintritt');
            }
            if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'geaendert_am')) {
                $table->timestamp('geaendert_am')->nullable()->after('created_at');
            }
        });

        // Drop password_plain (security): copy hash already exists, no data loss
        if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'password_plain')) {
            Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
                $table->dropColumn('password_plain');
            });
        }

        // Indexes for mitarbeiter
        $maIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM mitarbeiter"))
            ->pluck('Key_name')->toArray();

        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) use ($maIdx) {
            if (!in_array('mitarbeiter_aktiv_index', $maIdx)) {
                $table->index('aktiv', 'mitarbeiter_aktiv_index');
            }
            if (!in_array('mitarbeiter_bereich_index', $maIdx)) {
                $table->index('bereich', 'mitarbeiter_bereich_index');
            }
            if (!in_array('mitarbeiter_email_index', $maIdx)) {
                $table->index('email', 'mitarbeiter_email_index');
            }
            if (!in_array('mitarbeiter_eintritt_index', $maIdx)) {
                $table->index('eintritt', 'mitarbeiter_eintritt_index');
            }
        });

        // ---------- 2) mitarbeiter_dokumente ----------
        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
            Schema::connection('tenant')->table('mitarbeiter_dokumente', function (Blueprint $table) {
                if (!Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'dok_typ')) {
                    $table->string('dok_typ', 80)->nullable()->after('titel');
                }
                if (!Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'gueltig_ab')) {
                    $table->date('gueltig_ab')->nullable()->after('ablaufdatum');
                }
                if (!Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'pflicht')) {
                    $table->boolean('pflicht')->default(false)->after('gueltig_ab');
                }
                if (!Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'bemerkung')) {
                    $table->string('bemerkung', 500)->nullable()->after('pflicht');
                }
            });

            $docIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM mitarbeiter_dokumente"))
                ->pluck('Key_name')->toArray();

            Schema::connection('tenant')->table('mitarbeiter_dokumente', function (Blueprint $table) use ($docIdx) {
                if (!in_array('mitarbeiter_dokumente_ablaufdatum_index', $docIdx)) {
                    $table->index('ablaufdatum', 'mitarbeiter_dokumente_ablaufdatum_index');
                }
                if (!in_array('mitarbeiter_dokumente_dok_typ_index', $docIdx)) {
                    $table->index('dok_typ', 'mitarbeiter_dokumente_dok_typ_index');
                }
            });
        }

        // ---------- 3) mitarbeiter_abwesenheiten ----------
        if (Schema::connection('tenant')->hasTable('mitarbeiter_abwesenheiten')) {
            // Composite index for fast year/month queries per worker
            $absIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM mitarbeiter_abwesenheiten"))
                ->pluck('Key_name')->toArray();

            Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $table) use ($absIdx) {
                if (!in_array('mitarbeiter_abwesenheiten_dienst_start_index', $absIdx)) {
                    $table->index(['dienst_nr', 'startdatum'], 'mitarbeiter_abwesenheiten_dienst_start_index');
                }
                if (!in_array('mitarbeiter_abwesenheiten_typ_index', $absIdx)) {
                    $table->index('typ', 'mitarbeiter_abwesenheiten_typ_index');
                }
            });

            // Convert varchar(10) startzeit/endzeit -> TIME (preserves data, MariaDB/MySQL casts HH:MM[:SS])
            $abscol = collect(DB::connection('tenant')->select("SHOW COLUMNS FROM mitarbeiter_abwesenheiten"))
                ->keyBy('Field');

            if (isset($abscol['startzeit']) && stripos($abscol['startzeit']->Type, 'time') === false) {
                // MySQL: ALTER TABLE preserves valid HH:MM[:SS] strings into TIME automatically
                DB::connection('tenant')->statement("ALTER TABLE mitarbeiter_abwesenheiten MODIFY startzeit TIME NULL");
            }
            if (isset($abscol['endzeit']) && stripos($abscol['endzeit']->Type, 'time') === false) {
                DB::connection('tenant')->statement("ALTER TABLE mitarbeiter_abwesenheiten MODIFY endzeit TIME NULL");
            }
        }
    }

    public function down(): void
    {
        // mitarbeiter_abwesenheiten
        if (Schema::connection('tenant')->hasTable('mitarbeiter_abwesenheiten')) {
            $absIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM mitarbeiter_abwesenheiten"))
                ->pluck('Key_name')->toArray();
            Schema::connection('tenant')->table('mitarbeiter_abwesenheiten', function (Blueprint $table) use ($absIdx) {
                if (in_array('mitarbeiter_abwesenheiten_dienst_start_index', $absIdx)) {
                    $table->dropIndex('mitarbeiter_abwesenheiten_dienst_start_index');
                }
                if (in_array('mitarbeiter_abwesenheiten_typ_index', $absIdx)) {
                    $table->dropIndex('mitarbeiter_abwesenheiten_typ_index');
                }
            });
            // We do NOT revert TIME -> varchar — destructive
        }

        // mitarbeiter_dokumente
        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
            $docIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM mitarbeiter_dokumente"))
                ->pluck('Key_name')->toArray();
            Schema::connection('tenant')->table('mitarbeiter_dokumente', function (Blueprint $table) use ($docIdx) {
                if (in_array('mitarbeiter_dokumente_ablaufdatum_index', $docIdx)) {
                    $table->dropIndex('mitarbeiter_dokumente_ablaufdatum_index');
                }
                if (in_array('mitarbeiter_dokumente_dok_typ_index', $docIdx)) {
                    $table->dropIndex('mitarbeiter_dokumente_dok_typ_index');
                }
                if (Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'bemerkung')) {
                    $table->dropColumn('bemerkung');
                }
                if (Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'pflicht')) {
                    $table->dropColumn('pflicht');
                }
                if (Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'gueltig_ab')) {
                    $table->dropColumn('gueltig_ab');
                }
                if (Schema::connection('tenant')->hasColumn('mitarbeiter_dokumente', 'dok_typ')) {
                    $table->dropColumn('dok_typ');
                }
            });
        }

        // mitarbeiter
        $maIdx = collect(DB::connection('tenant')->select("SHOW INDEX FROM mitarbeiter"))
            ->pluck('Key_name')->toArray();
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) use ($maIdx) {
            foreach ([
                'mitarbeiter_aktiv_index',
                'mitarbeiter_bereich_index',
                'mitarbeiter_email_index',
                'mitarbeiter_eintritt_index',
            ] as $idx) {
                if (in_array($idx, $maIdx)) {
                    $table->dropIndex($idx);
                }
            }
        });

        // Re-add password_plain (NOT recommended, but for full revert)
        if (!Schema::connection('tenant')->hasColumn('mitarbeiter', 'password_plain')) {
            Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
                $table->string('password_plain', 255)->nullable()->after('password_hash');
            });
        }

        // Drop new columns
        Schema::connection('tenant')->table('mitarbeiter', function (Blueprint $table) {
            foreach (['geaendert_am', 'austritt', 'ort', 'plz', 'strasse',
                     'staatsangehoerigkeit', 'geburtsort', 'geburtstag'] as $col) {
                if (Schema::connection('tenant')->hasColumn('mitarbeiter', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
