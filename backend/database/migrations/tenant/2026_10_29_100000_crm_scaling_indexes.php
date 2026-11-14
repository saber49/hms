<?php

use App\Support\Crm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead-Modul at SaaS scale:
 *   crm_kontakte.telefon_norm / mobil_norm — digits-only phone (+49/0049 → 0),
 *     indexed, so the A1 duplicate check is an index lookup instead of a
 *     REPLACE() full scan. Kept in sync by Crm::telNorm() on every write.
 *   composite indexes for the hot paths: own open leads, own deals,
 *   A9 attempt counting, open linked tasks.
 * Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::connection($this->connection)->select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$name]))->isNotEmpty();
    }

    private function index(string $table, array $cols, string $name): void
    {
        if (Schema::connection($this->connection)->hasTable($table) && !$this->hasIndex($table, $name)) {
            Schema::connection($this->connection)->table($table, fn (Blueprint $t) => $t->index($cols, $name));
        }
    }

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('crm_kontakte')) return;

        $s->table('crm_kontakte', function (Blueprint $t) use ($s) {
            if (!$s->hasColumn('crm_kontakte', 'telefon_norm')) $t->string('telefon_norm', 30)->nullable()->after('mobil');
            if (!$s->hasColumn('crm_kontakte', 'mobil_norm')) $t->string('mobil_norm', 30)->nullable()->after('telefon_norm');
        });
        $this->index('crm_kontakte', ['telefon_norm'], 'crm_kontakte_telefon_norm_index');
        $this->index('crm_kontakte', ['mobil_norm'], 'crm_kontakte_mobil_norm_index');

        DB::connection($this->connection)->table('crm_kontakte')
            ->where(fn ($q) => $q->whereNotNull('telefon')->orWhereNotNull('mobil'))
            ->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    DB::connection($this->connection)->table('crm_kontakte')->where('id', $r->id)->update([
                        'telefon_norm' => Crm::telNorm($r->telefon), 'mobil_norm' => Crm::telNorm($r->mobil),
                    ]);
                }
            });

        $this->index('crm_leads', ['owner_id', 'stage', 'deleted_at'], 'crm_leads_owner_stage_index');
        $this->index('crm_leads', ['kontakt_id', 'stage'], 'crm_leads_kontakt_stage_index');
        $this->index('crm_deals', ['owner_id', 'phase', 'deleted_at'], 'crm_deals_owner_phase_index');
        $this->index('crm_aktivitaeten', ['lead_id', 'typ', 'zeitpunkt'], 'crm_akt_lead_typ_zeit_index');
        $this->index('aufgabe_empfaenger', ['aufgabe_id', 'status'], 'aufgabe_empf_aufgabe_status_index');
    }

    public function down(): void
    {
        $s = Schema::connection($this->connection);
        foreach ([['crm_leads', 'crm_leads_owner_stage_index'], ['crm_leads', 'crm_leads_kontakt_stage_index'],
                  ['crm_deals', 'crm_deals_owner_phase_index'], ['crm_aktivitaeten', 'crm_akt_lead_typ_zeit_index'],
                  ['aufgabe_empfaenger', 'aufgabe_empf_aufgabe_status_index'],
                  ['crm_kontakte', 'crm_kontakte_telefon_norm_index'], ['crm_kontakte', 'crm_kontakte_mobil_norm_index']] as [$t, $i]) {
            if ($s->hasTable($t) && $this->hasIndex($t, $i)) $s->table($t, fn (Blueprint $b) => $b->dropIndex($i));
        }
        if ($s->hasColumn('crm_kontakte', 'telefon_norm')) $s->table('crm_kontakte', fn (Blueprint $t) => $t->dropColumn(['telefon_norm', 'mobil_norm']));
    }
};
