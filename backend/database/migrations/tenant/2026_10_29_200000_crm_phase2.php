<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead-Modul Phase 2 (Spezifikation §8 Phase 2):
 *   crm_leads.a8_gemeldet_am        last A8 "no activity" task (re-armed by new activity)
 *   crm_leads.a9_hinweis_am         A9 "nicht erreichbar?" hint sent
 *   crm_leads.wiedervorlage_lead_id A12: lead created when the follow-up date was reached
 *   crm_leads.zuweisung             how the owner was chosen (rotation/region/fest/fallback/manuell)
 *   crm_kontakte.merged_into, crm_firmen.merged_into   A1 merge target (source is soft-deleted)
 *   crm_zuweisung                   assignment rule per Sparte (§6)
 *   crm_importe                     CSV/Excel import log
 * Additive + idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('crm_leads')) return;

        $s->table('crm_leads', function (Blueprint $t) use ($s) {
            if (!$s->hasColumn('crm_leads', 'a8_gemeldet_am')) $t->dateTime('a8_gemeldet_am')->nullable();
            if (!$s->hasColumn('crm_leads', 'a9_hinweis_am')) $t->dateTime('a9_hinweis_am')->nullable();
            if (!$s->hasColumn('crm_leads', 'wiedervorlage_lead_id')) $t->uuid('wiedervorlage_lead_id')->nullable();
            if (!$s->hasColumn('crm_leads', 'zuweisung')) $t->string('zuweisung', 20)->nullable();
        });
        foreach (['crm_kontakte', 'crm_firmen'] as $tb) {
            if (!$s->hasColumn($tb, 'merged_into')) $s->table($tb, fn (Blueprint $t) => $t->uuid('merged_into')->nullable());
        }

        if (!$s->hasTable('crm_zuweisung')) {
            $s->create('crm_zuweisung', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('sparte', 190)->unique();
                $t->string('modus', 20);                 // rotation | region | fest
                $t->json('user_ids')->nullable();        // rotation pool
                $t->json('plz_regeln')->nullable();      // [{von, bis, user_id}]
                $t->unsignedBigInteger('fest_user_id')->nullable();
                $t->unsignedInteger('rr_index')->default(0);
                $t->timestamps();
            });
        }

        if (!$s->hasTable('crm_importe')) {
            $s->create('crm_importe', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('user_id');
                $t->string('datei_name', 255);
                $t->unsignedInteger('zeilen')->default(0);
                $t->unsignedInteger('angelegt')->default(0);
                $t->unsignedInteger('dubletten')->default(0);
                $t->unsignedInteger('fehler')->default(0);
                $t->unsignedInteger('leads')->default(0);
                $t->json('details')->nullable();
                $t->dateTime('created_at');
            });
        }

        DB::connection($this->connection)->table('crm_einstellungen')->insertOrIgnore([
            ['schluessel' => 'automatik_aktiv', 'wert' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['schluessel' => 'import_max_zeilen', 'wert' => '2000', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        $s->dropIfExists('crm_importe');
        $s->dropIfExists('crm_zuweisung');
        foreach (['a8_gemeldet_am', 'a9_hinweis_am', 'wiedervorlage_lead_id', 'zuweisung'] as $c) {
            if ($s->hasColumn('crm_leads', $c)) $s->table('crm_leads', fn (Blueprint $t) => $t->dropColumn($c));
        }
        foreach (['crm_kontakte', 'crm_firmen'] as $tb) {
            if ($s->hasColumn($tb, 'merged_into')) $s->table($tb, fn (Blueprint $t) => $t->dropColumn('merged_into'));
        }
    }
};
