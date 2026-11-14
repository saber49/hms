<?php

namespace App\Console\Commands;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Api\CrmStammController;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\Crm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lead-Modul automation (Spezifikation §4, Phase 2) — scheduled, per tenant.
 *
 *   A8   open lead without activity for N days → task "Lead nachfassen" for the
 *        owner + one digest to the Vertriebsleitung. Re-armed by new activity
 *        (crm_leads.a8_gemeldet_am).
 *   A9   N outgoing attempts in the window without success → hint to the owner
 *        "Disqualifizieren: nicht erreichbar?" (once, crm_leads.a9_hinweis_am).
 *   A12  disqualified lead whose Wiedervorlage date is reached → new lead
 *        (Stage Neu) for the same contact/Sparte, owner = previous owner or
 *        Zuweisungsregel; the task notifies him.
 *   Digest  each Vertrieb user: CRM tasks due today / overdue (bell, once a day).
 *   DSGVO   disqualified contacts without activity for N months → admins (max. monthly).
 *
 * Every automatic change lands in crm_protokoll with the rule number.
 * Thresholds come from crm_einstellungen (changeable without code).
 * Idempotent: running twice a day does not duplicate tasks or hints.
 *
 *   php artisan crm:automation [--tenant=slug] [--dry-run] [--nur=A8,A9,A12,digest,dsgvo]
 */
class CrmAutomation extends Command
{
    protected $signature = 'crm:automation
                            {--tenant= : Limit to one tenant slug}
                            {--dry-run : Report only, change nothing}
                            {--nur= : Comma list of steps (A8,A9,A12,digest,dsgvo)}';

    protected $description = 'Lead-Modul: automatic rules A8/A9/A12, daily task digest, DSGVO reminder';

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $nur = array_filter(array_map('trim', explode(',', (string) $this->option('nur'))));
        $step = fn (string $s) => !$nur || in_array($s, $nur, true);
        $tenants = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->get()
            : Tenant::where('aktiv', true)->get();

        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                if (!Crm::ready() || Crm::setting('automatik_aktiv', '1') === '0') continue;
                $res = [];
                if ($step('A12')) $res['A12'] = $this->a12($tenant);
                if ($step('A8')) $res['A8'] = $this->a8($tenant);
                if ($step('A9')) $res['A9'] = $this->a9($tenant);
                if ($step('digest')) $res['digest'] = $this->digest($tenant);
                if ($step('dsgvo')) $res['dsgvo'] = $this->dsgvo($tenant);
                $line = collect($res)->map(fn ($v, $k) => "$k=$v")->implode(' ');
                $this->line(sprintf('%s%-28s %s', $this->dry ? '[DRY] ' : '', $tenant->slug, $line));
                if (array_sum($res) > 0 && !$this->dry) Crm::changed();
            } catch (\Throwable $e) {
                Log::error('crm:automation failed', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
                $this->error($tenant->slug . ': ' . $e->getMessage());
            } finally {
                TenantService::reset();
            }
        }
        return 0;
    }

    /** A8 — open leads without activity since N days (not yet flagged since their last activity). */
    private function a8(Tenant $tenant): int
    {
        $tage = Crm::intSetting('a8_inaktiv_tage', 7);
        $grenze = now()->subDays($tage);
        $leads = Crm::t('crm_leads as l')->join('crm_kontakte as k', 'k.id', '=', 'l.kontakt_id')
            ->leftJoin('crm_firmen as f', 'f.id', '=', 'l.firma_id')
            ->whereNull('l.deleted_at')->whereIn('l.stage', Crm::LEAD_OFFEN)
            ->whereRaw('COALESCE(l.letzte_aktivitaet_am, l.created_at) < ?', [$grenze])
            ->where(fn ($w) => $w->whereNull('l.a8_gemeldet_am')->orWhereRaw('l.a8_gemeldet_am < COALESCE(l.letzte_aktivitaet_am, l.created_at)'))
            ->limit(1000)->get(['l.id', 'l.owner_id', 'l.sparte', 'k.vorname', 'k.nachname', 'f.name as firma']);
        if ($this->dry || $leads->isEmpty()) return $leads->count();

        $bot = Crm::automatik((int) $tenant->id);
        foreach ($leads as $l) {
            $name = trim(($l->firma ? $l->firma . ' · ' : '') . $l->vorname . ' ' . $l->nachname);
            Crm::aufgabe('lead', $l->id, 'anruf', 'Lead nachfassen (A8): ' . $name, Crm::heute(), null, (int) $l->owner_id, $bot,
                $l->sparte, "Seit über $tage Tagen keine Aktivität.");
            Crm::t('crm_leads')->where('id', $l->id)->update(['a8_gemeldet_am' => now()]);
        }
        Crm::hinweis(['all'], $leads->count() . ' Leads ohne Aktivität (A8)',
            "Seit über $tage Tagen keine Aktivität — die Owner haben eine Nachfass-Aufgabe erhalten.", '#interessenten');
        return $leads->count();
    }

    /** A9 — threshold of unsuccessful attempts reached → one hint to the owner. */
    private function a9(Tenant $tenant): int
    {
        $n = Crm::intSetting('a9_versuche', 6);
        $seit = now()->subDays(Crm::intSetting('a9_tage', 21));
        $leads = Crm::t('crm_leads as l')->join('crm_kontakte as k', 'k.id', '=', 'l.kontakt_id')
            ->whereNull('l.deleted_at')->where('l.stage', 'kontaktversuch')->whereNull('l.a9_hinweis_am')
            ->whereRaw("(SELECT COUNT(*) FROM crm_aktivitaeten a WHERE a.lead_id = l.id AND a.typ IN ('anruf','email')
                AND a.richtung = 'ausgehend' AND a.zeitpunkt >= ?) >= ?", [$seit, $n])
            ->limit(1000)->get(['l.id', 'l.owner_id', 'l.anzahl_kontaktversuche', 'k.vorname', 'k.nachname']);
        if ($this->dry) return $leads->count();
        foreach ($leads as $l) {
            NotificationDispatcher::notifyUserIds((int) $tenant->id, [(int) $l->owner_id], 'vertrieb.hinweis',
                'Nicht erreichbar? ' . trim($l->vorname . ' ' . $l->nachname),
                "$n Kontaktversuche ohne Erfolg — Lead ggf. disqualifizieren (Grund „nicht erreichbar“, Regel A9).",
                '#interessenten', ['lead_id' => $l->id]);
            Crm::t('crm_leads')->where('id', $l->id)->update(['a9_hinweis_am' => now()]);
            Crm::log('lead', $l->id, 'a9_hinweis', null, 'gesendet', null, 'A9');
        }
        return $leads->count();
    }

    /** A12 — follow-up date of a disqualified lead reached → new lead. */
    private function a12(Tenant $tenant): int
    {
        $leads = Crm::t('crm_leads')->whereNull('deleted_at')->where('stage', 'disqualifiziert')
            ->whereNotNull('wiedervorlage_am')->where('wiedervorlage_am', '<=', Crm::heute())->whereNull('wiedervorlage_lead_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('crm_kontakte as k')->whereColumn('k.id', 'crm_leads.kontakt_id')->whereNull('k.deleted_at'))
            ->limit(500)->get();
        if ($this->dry || $leads->isEmpty()) return $leads->count();

        $stamm = app(CrmStammController::class);
        $valid = Crm::usersWith((int) $tenant->id, ['view', 'all'])->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach ($leads as $l) {
            DB::connection('tenant')->transaction(function () use ($l, $stamm, $valid, $tenant) {
                [$owner, $regel] = in_array((int) $l->owner_id, $valid, true)
                    ? [(int) $l->owner_id, 'manuell']
                    : Crm::zuweisen($l->sparte, $l->firma_id ? Crm::t('crm_firmen')->where('id', $l->firma_id)->value('plz') : null,
                        (int) $tenant->id, (int) $l->owner_id);
                $neu = $stamm->leadAnlegen(null, $l->kontakt_id, $l->firma_id, (string) ($l->sparte ?: ''), $l->prioritaet, $owner, true, $regel, true, 'A12');
                Crm::t('crm_leads')->where('id', $l->id)->update(['wiedervorlage_lead_id' => $neu, 'updated_at' => now()]);
                Crm::log('lead', $l->id, 'wiedervorlage_lead', null, $neu, null, 'A12');
            });
        }
        return $leads->count();
    }

    /** Daily digest: CRM tasks due today / overdue per assignee (bell + push). */
    private function digest(Tenant $tenant): int
    {
        $heute = Crm::heute();
        $rows = Crm::t('aufgaben as a')->join('aufgabe_empfaenger as e', 'e.aufgabe_id', '=', 'a.id')
            ->whereIn('a.bezug_typ', ['lead', 'deal', 'kontakt'])->where('e.typ', 'user')->where('e.status', '!=', 'erledigt')
            ->where('a.faellig_am', '<=', $heute)
            ->groupBy('e.user_id')
            ->selectRaw('e.user_id, SUM(a.faellig_am = ?) AS heute, SUM(a.faellig_am < ?) AS ueber', [$heute, $heute])
            ->get();
        if ($this->dry) return $rows->count();
        foreach ($rows as $r) {
            $txt = trim(((int) $r->heute ? "{$r->heute} heute fällig" : '') . ((int) $r->heute && (int) $r->ueber ? ', ' : '') . ((int) $r->ueber ? "{$r->ueber} überfällig" : ''));
            NotificationDispatcher::notifyUserIds((int) $tenant->id, [(int) $r->user_id], 'vertrieb.hinweis',
                'Vertrieb: ' . $txt, 'Aufgaben und Wiedervorlagen im Lead-Modul.', '#interessenten');
        }
        return $rows->count();
    }

    /** DSGVO §6 — disqualified contacts without activity for N months → admins, at most every 30 days. */
    private function dsgvo(Tenant $tenant): int
    {
        $grenze = now()->subMonths(Crm::intSetting('dsgvo_pruefung_monate', 24));
        $n = Crm::t('crm_kontakte as k')->whereNull('k.deleted_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('crm_leads as l')->whereColumn('l.kontakt_id', 'k.id')->whereNull('l.deleted_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_leads as l')->whereColumn('l.kontakt_id', 'k.id')->whereNull('l.deleted_at')->where('l.stage', '!=', 'disqualifiziert'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_deals as d')->whereColumn('d.kontakt_id', 'k.id')->whereNull('d.deleted_at'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_aktivitaeten as a')->whereColumn('a.kontakt_id', 'k.id')->where('a.zeitpunkt', '>=', $grenze))
            ->where('k.updated_at', '<', $grenze)
            ->count();
        $zuletzt = Crm::setting('dsgvo_letzter_hinweis');
        if ($n === 0 || $this->dry || ($zuletzt && $zuletzt > now()->subDays(30)->toDateString())) return $this->dry ? $n : 0;
        Crm::hinweis(['admin'], "DSGVO-Prüfung: $n Kontakte", "$n disqualifizierte Kontakte ohne Aktivität seit "
            . Crm::intSetting('dsgvo_pruefung_monate', 24) . ' Monaten — bitte prüfen (löschen/anonymisieren).', '#interessenten');
        Crm::t('crm_einstellungen')->updateOrInsert(['schluessel' => 'dsgvo_letzter_hinweis'], ['wert' => Crm::heute(), 'updated_at' => now()]);
        return $n;
    }
}
