<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // Managed Bereich catalog: mains (parent_id NULL) feed the
        // kunden/objekte/mitarbeiter/nachunternehmer `bereiche` pickers;
        // subs (parent_id = main id) feed the objekte Objektart picker.
        if (!Schema::connection('tenant')->hasTable('bereiche_katalog')) {
            Schema::connection('tenant')->create('bereiche_katalog', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 190);
                $table->unsignedInteger('parent_id')->nullable(); // NULL = Hauptbereich
                $table->boolean('aktiv')->default(1);
                $table->integer('sortierung')->default(0);
                $table->timestamps();
                $table->index(['parent_id', 'aktiv']);
            });
        }

        // Workers + subcontractors join the multi-service model: `bereich`
        // stays the primary tag, `bereiche` is the full service list.
        foreach (['mitarbeiter', 'nachunternehmer'] as $tbl) {
            if (Schema::connection('tenant')->hasTable($tbl)
                && !Schema::connection('tenant')->hasColumn($tbl, 'bereiche')) {
                Schema::connection('tenant')->table($tbl, function (Blueprint $table) {
                    $table->json('bereiche')->nullable()->after('bereich');
                });
                DB::connection('tenant')->statement(
                    "UPDATE {$tbl} SET bereiche = JSON_ARRAY(bereich) WHERE bereich IS NOT NULL AND TRIM(bereich) <> ''"
                );
            }
        }

        // objekte.arten: multi-value Objektart list fed by catalog subs.
        // `art` stays the primary type (existing Zuschlag rules key on it).
        if (!Schema::connection('tenant')->hasColumn('objekte', 'arten')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->json('arten')->nullable()->after('art');
            });
            DB::connection('tenant')->statement(
                "UPDATE objekte SET arten = JSON_ARRAY(art) WHERE art IS NOT NULL AND TRIM(art) <> ''"
            );
        }

        // Seed catalog mains from every tag value already in use so the
        // picker keeps offering what the tenant actually works with.
        $existing = collect();
        foreach (['artikel', 'kunden', 'objekte', 'mitarbeiter', 'nachunternehmer'] as $tbl) {
            if (Schema::connection('tenant')->hasTable($tbl)
                && Schema::connection('tenant')->hasColumn($tbl, 'bereich')) {
                $existing = $existing->merge(
                    DB::connection('tenant')->table($tbl)->whereNotNull('bereich')->pluck('bereich')
                );
            }
            if (Schema::connection('tenant')->hasTable($tbl)
                && Schema::connection('tenant')->hasColumn($tbl, 'bereiche')) {
                foreach (DB::connection('tenant')->table($tbl)->whereNotNull('bereiche')->pluck('bereiche') as $json) {
                    $existing = $existing->merge(json_decode($json, true) ?: []);
                }
            }
        }
        $names = $existing
            ->flatMap(fn ($v) => explode(',', (string) $v))
            ->map(fn ($v) => trim($v))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v))
            ->sort()
            ->values();
        foreach ($names as $i => $name) {
            $dup = DB::connection('tenant')->table('bereiche_katalog')
                ->whereNull('parent_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
            if (!$dup) {
                DB::connection('tenant')->table('bereiche_katalog')->insert([
                    'name'       => $name,
                    'parent_id'  => null,
                    'aktiv'      => 1,
                    'sortierung' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bereiche_katalog');
        foreach (['mitarbeiter', 'nachunternehmer'] as $tbl) {
            if (Schema::connection('tenant')->hasColumn($tbl, 'bereiche')) {
                Schema::connection('tenant')->table($tbl, function (Blueprint $table) {
                    $table->dropColumn('bereiche');
                });
            }
        }
        if (Schema::connection('tenant')->hasColumn('objekte', 'arten')) {
            Schema::connection('tenant')->table('objekte', function (Blueprint $table) {
                $table->dropColumn('arten');
            });
        }
    }
};
