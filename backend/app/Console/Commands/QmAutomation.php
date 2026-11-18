<?php

namespace App\Console\Commands;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\Qm\DienstanweisungService;
use App\Services\Qm\QmNotify;
use App\Services\Qm\ReklamationService;
use App\Services\Qm\WachbuchService;
use App\Services\TenantService;
use App\Support\Qm\Qm;
use App\Support\TenantProfile;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Qualitätsmanagement & Wachbuch — everything that depends on TIME, per
 * tenant. Without this command deadlines were only visible to whoever
 * happened to open the page.
 *
 *   da          a released Dienstanweisung reached its "gültig ab" → the
 *               version it replaces is archived
 *   da_pruefung regular review of a Dienstanweisung is due (setting
 *               da_pruefung_monate) → Objektleitung
 *   rek_best    Eingangsbestätigung overdue → Zuständiger + QMB, step
 *               "Eskalation", qm_reklamationen.eskaliert_am
 *   rek_frist   Reklamation over its deadline → Zuständiger + QMB
 *   mn_frist    Maßnahme overdue → Verantwortlicher + QMB (again after an extension)
 *   mn_wirksam  Wirksamkeitsprüfung due → QMB
 *   alarm       an Alarm is still open after ALARM_MIN minutes → Objektleitung + QMB (bell + e-mail)
 *   bericht     Tagesbericht of the finished Wachbuch day → "Empfänger der
 *               Tagesberichte" of the Objekt (customer view of the entries)
 *
 * Every reminder is sent ONCE per record (qm_benachrichtigungen is the
 * marker), so the command can run every few minutes.
 *
 *   php artisan qm:automation [--tenant=slug] [--dry-run] [--nur=da,alarm,…]
 */
class QmAutomation extends Command
{
    public const ALARM_MIN = 15;

    /** Arten a customer may read (same whitelist as the Kundenportal). */
    private const BERICHT_ARTEN = ['dienstbeginn', 'dienstende', 'notiz', 'vorkommnis', 'alarm', 'besucher', 'lieferung', 'schluessel', 'foto', 'hinweis', 'berichtigung'];

    protected $signature = 'qm:automation
                            {--tenant= : Limit to one tenant slug}
                            {--dry-run : Report only, send and change nothing}
                            {--nur= : Comma list of steps (da,da_pruefung,rek_best,rek_frist,mn_frist,mn_wirksam,alarm,bericht)}';

    protected $description = 'QM & Wachbuch: deadlines, escalation, open alarms, Dienstanweisung validity/review, Tagesbericht';

    private bool $dry = false;

    public function handle(): int
    {
        $failed = false;
        $this->dry = (bool) $this->option('dry-run');
        $nur = array_filter(array_map('trim', explode(',', (string) $this->option('nur'))));
        $tenants = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->get()
            : Tenant::where('aktiv', true)->get();
        $steps = ['da' => 'da', 'da_pruefung' => 'daPruefung', 'rek_best' => 'rekBestaetigung', 'rek_frist' => 'rekFrist',
                  'mn_frist' => 'mnFrist', 'mn_wirksam' => 'mnWirksam', 'alarm' => 'alarm', 'bericht' => 'bericht'];

        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                Qm::forget();
                if (!Qm::ready()) continue;
                $res = [];
                foreach ($steps as $key => $fn) {
                    if ($nur && !in_array($key, $nur, true)) continue;
                    try {
                        $res[$key] = $this->$fn($tenant);
                    } catch (\Throwable $e) {
                        $failed = true;
                        $res[$key] = 'ERR';
                        Log::error('qm:automation step failed', ['tenant' => $tenant->slug, 'step' => $key, 'error' => $e->getMessage()]);
                    }
                }
                if (array_filter($res)) {
                    $this->line(sprintf('%s%-28s %s', $this->dry ? '[DRY] ' : '', $tenant->slug, collect($res)->map(fn ($v, $k) => "$k=$v")->implode(' ')));
                }
            } catch (\Throwable $e) {
                $failed = true;
                Log::error('qm:automation failed', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
                $this->error($tenant->slug . ': ' . $e->getMessage());
            } finally {
                Qm::forget();
                TenantService::reset();
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    // ---------------------------------------------------------------
    // Dienstanweisungen
    // ---------------------------------------------------------------

    private function da(Tenant $tenant): int
    {
        if ($this->dry) {
            return Qm::t('qm_dienstanweisungen')->where('status', 'freigegeben')->where('gueltig_ab', '<=', Qm::heute())
                ->selectRaw('obj_id, COUNT(*) AS n')->groupBy('obj_id')->having('n', '>', 1)->get()->count();
        }
        return DienstanweisungService::faelligWerden();
    }

    private function daPruefung(Tenant $tenant): int
    {
        $rows = Qm::t('qm_dienstanweisungen as d')->join('objekte as o', 'o.obj_id', '=', 'd.obj_id')
            ->where('d.status', 'freigegeben')->where('d.gueltig_ab', '<=', Qm::heute())
            ->whereNotNull('d.pruefung_faellig_am')->where('d.pruefung_faellig_am', '<=', Qm::heute())
            ->limit(500)->get(['d.id', 'd.obj_id', 'd.version', 'd.pruefung_faellig_am', 'o.objektname']);
        $n = 0;
        foreach ($rows as $d) {
            if (QmNotify::schonGesendet('dienstanweisung.review', 'dienstanweisung', $d->id)) continue;
            $n++;
            if ($this->dry) continue;
            $titel = 'Dienstanweisung prüfen: ' . $d->objektname;
            $text = 'Version ' . $d->version . ' ist seit ' . Carbon::parse($d->pruefung_faellig_am)->format('d.m.Y')
                . ' zur regelmäßigen Prüfung fällig. Ist sie noch aktuell? Sonst bitte eine neue Version hochladen.';
            $this->melde(QmNotify::objektleitung((string) $d->obj_id), 'dienstanweisung.review', $titel, $text, '#qm?view=objekte', 'dienstanweisung', $d->id);
        }
        return $n;
    }

    // ---------------------------------------------------------------
    // Reklamationen
    // ---------------------------------------------------------------

    private function rekBestaetigung(Tenant $tenant): int
    {
        $rows = Qm::t('qm_reklamationen as r')->join('objekte as o', 'o.obj_id', '=', 'r.obj_id')
            ->where('r.status', '!=', 'abgeschlossen')->whereNull('r.bestaetigt_am')->whereNull('r.eskaliert_am')
            ->whereNotNull('r.frist_bestaetigung')->where('r.frist_bestaetigung', '<', now())
            ->limit(500)->get(['r.*', 'o.objektname']);
        if ($this->dry) return $rows->count();
        foreach ($rows as $r) {
            Qm::t('qm_reklamationen')->where('id', $r->id)->update(['eskaliert_am' => now(), 'updated_at' => now()]);
            $text = 'Der Eingang wurde nicht innerhalb der Frist (' . Carbon::parse($r->frist_bestaetigung)->setTimezone('Europe/Berlin')->format('d.m.Y H:i')
                . ') bestätigt.';
            ReklamationService::schritt($r, 'eskalation', $text, null);
            $this->melde($this->rekEmpfaenger($r), 'reklamation.unconfirmed', 'Eingangsbestätigung überfällig: ' . $r->nr . ' · ' . $r->objektname,
                $text . ' Bitte dem Kunden den Eingang bestätigen.', '#qm?view=reklamationen&id=' . $r->id, 'reklamation', $r->id, true);
            Qm::changed('reklamation', (string) $r->obj_id);
        }
        return $rows->count();
    }

    private function rekFrist(Tenant $tenant): int
    {
        $rows = Qm::t('qm_reklamationen as r')->join('objekte as o', 'o.obj_id', '=', 'r.obj_id')
            ->where('r.status', '!=', 'abgeschlossen')->whereNotNull('r.frist_erledigung')->where('r.frist_erledigung', '<', Qm::heute())
            ->limit(500)->get(['r.*', 'o.objektname']);
        $n = 0;
        foreach ($rows as $r) {
            // keyed by the deadline: a changed Schwere (new deadline) reminds again
            $key = $r->id . ':' . substr((string) $r->frist_erledigung, 0, 10);
            if (QmNotify::schonGesendet('reklamation.overdue', 'reklamation', $key)) continue;
            $n++;
            if ($this->dry) continue;
            $text = 'Die Frist zur Erledigung (' . Carbon::parse($r->frist_erledigung)->format('d.m.Y') . ') ist überschritten.';
            ReklamationService::schritt($r, 'eskalation', $text, null);
            $this->melde($this->rekEmpfaenger($r), 'reklamation.overdue', 'Reklamation über der Frist: ' . $r->nr . ' · ' . $r->objektname,
                $text, '#qm?view=reklamationen&id=' . $r->id, 'reklamation', $key, true);
            Qm::changed('reklamation', (string) $r->obj_id);
        }
        return $n;
    }

    /** Zuständiger (else Objektleitung) plus every QMB. */
    private function rekEmpfaenger(object $r): array
    {
        $ids = $r->zustaendig_id ? [(int) $r->zustaendig_id] : QmNotify::objektleitung((string) $r->obj_id);
        return array_values(array_unique(array_merge($ids, QmNotify::rollenUser(['qmb']))));
    }

    // ---------------------------------------------------------------
    // Maßnahmen
    // ---------------------------------------------------------------

    private function mnFrist(Tenant $tenant): int
    {
        $rows = Qm::t('qm_massnahmen')->where('status', 'offen')->where('frist', '<', Qm::heute())->limit(500)->get();
        $n = 0;
        foreach ($rows as $m) {
            $key = $m->id . ':' . substr((string) $m->frist, 0, 10);      // an extended deadline reminds again
            if (QmNotify::schonGesendet('massnahme.overdue', 'massnahme', $key)) continue;
            $n++;
            if ($this->dry) continue;
            $ids = array_values(array_unique(array_merge($m->verantwortlich_id ? [(int) $m->verantwortlich_id] : [], QmNotify::rollenUser(['qmb']))));
            $this->melde($ids, 'massnahme.overdue', 'Maßnahme überfällig: ' . $m->nr,
                $m->titel . ' — Frist ' . Carbon::parse($m->frist)->format('d.m.Y') . ' überschritten.', '#qm?view=massnahmen&id=' . $m->id, 'massnahme', $key);
        }
        return $n;
    }

    private function mnWirksam(Tenant $tenant): int
    {
        $rows = Qm::t('qm_massnahmen')->where('status', 'erledigt')->whereNotNull('wirksam_pruefung_am')
            ->where('wirksam_pruefung_am', '<=', Qm::heute())->limit(500)->get();
        $n = 0;
        foreach ($rows as $m) {
            if (QmNotify::schonGesendet('massnahme.review', 'massnahme', $m->id)) continue;
            $n++;
            if ($this->dry) continue;
            $this->melde(QmNotify::rollenUser(['qmb'], 'massnahme_wirksamkeit'), 'massnahme.review', 'Wirksamkeit prüfen: ' . $m->nr,
                $m->titel . ' — erledigt am ' . Carbon::parse($m->erledigt_am)->setTimezone('Europe/Berlin')->format('d.m.Y') . '. Hat die Maßnahme gewirkt?',
                '#qm?view=massnahmen&id=' . $m->id, 'massnahme', $m->id);
        }
        return $n;
    }

    // ---------------------------------------------------------------
    // Wachbuch
    // ---------------------------------------------------------------

    private function alarm(Tenant $tenant): int
    {
        $rows = Qm::t('qm_wb_eintraege as w')->join('objekte as o', 'o.obj_id', '=', 'w.obj_id')
            ->where('w.art', 'alarm')->where('w.status', 'offen')
            ->where('w.zeit', '<', now()->subMinutes(self::ALARM_MIN))->where('w.zeit', '>', now()->subDay())
            ->limit(200)->get(['w.id', 'w.obj_id', 'w.zeit', 'w.text', 'w.erfasst_von', 'o.objektname']);
        $n = 0;
        foreach ($rows as $e) {
            if (QmNotify::schonGesendet('wachbuch.alarm_offen', 'wachbuch_eintrag', $e->id)) continue;
            $n++;
            if ($this->dry) continue;
            $ids = array_values(array_unique(array_merge(QmNotify::objektleitung((string) $e->obj_id), QmNotify::rollenUser(['qmb']))));
            $this->melde($ids, 'wachbuch.alarm_offen', 'Alarm seit ' . self::ALARM_MIN . ' Minuten offen: ' . $e->objektname,
                'Gemeldet ' . Carbon::parse($e->zeit)->setTimezone('Europe/Berlin')->format('H:i') . ' Uhr'
                . ($e->erfasst_von ? ' von ' . $e->erfasst_von : '') . ': ' . mb_substr((string) $e->text, 0, 300)
                . "\n\nBitte im Leitstand prüfen und als erledigt markieren.", '#qm_wachbuch', 'wachbuch_eintrag', $e->id, true);
        }
        return $n;
    }

    /** Tagesbericht of the Wachbuch day that just ended, once per Objekt. */
    private function bericht(Tenant $tenant): int
    {
        if (!Qm::boolSetting('modul_wachbuch')) return 0;
        // Do not report an unfinished operational day between midnight and tag_beginn.
        if (Carbon::now('Europe/Berlin')->format('H:i') < Qm::tagBeginn()) return 0;
        $tag = Carbon::parse(Qm::wachbuchTag())->subDay()->toDateString();
        $objekte = Qm::t('qm_objekt_einstellungen as q')->join('objekte as o', 'o.obj_id', '=', 'q.obj_id')
            ->where('q.wachbuch_aktiv', 1)->whereNotNull('q.bericht_empfaenger')->where('q.bericht_empfaenger', '!=', '')
            ->get(['q.obj_id', 'q.bericht_empfaenger', 'o.objektname']);
        $label = TenantProfile::objektbuchLabel($tenant);
        $n = 0;
        foreach ($objekte as $o) {
            $key = $o->obj_id . '|' . $tag;
            if (QmNotify::schonGesendet('wachbuch.tagesbericht', 'objekt', $key)) continue;
            $blaetter = Qm::t('qm_wb_blaetter')->where('obj_id', $o->obj_id)->where('datum', $tag)->get();
            $eintraege = collect(WachbuchService::eintraege($blaetter->pluck('id')->all()))->whereIn('art', self::BERICHT_ARTEN)->values();
            if ($eintraege->isEmpty()) {
                // nothing happened — no empty mail, but remember the day as done
                if (!$this->dry) QmNotify::merke('wachbuch.tagesbericht', 'objekt', $key, 'Kein Tagesbericht (keine Einträge) ' . $tag);
                continue;
            }
            $n++;
            if ($this->dry) continue;
            $namen = $blaetter->pluck('dienst_nr')->filter()->unique()->map(fn ($d) => Qm::workerName($d))->implode(', ');
            $vk = $eintraege->whereIn('art', ['vorkommnis', 'alarm'])->count();
            $zeilen = $eintraege->map(function ($e) {
                $t = Carbon::parse($e['zeit'])->setTimezone('Europe/Berlin')->format('H:i');
                return $t . '  ' . $e['art_label'] . ($e['ort'] ? ' (' . $e['ort'] . ')' : '') . ($e['text'] ? ': ' . $e['text'] : '')
                    . ($e['berichtigt_durch'] ? '  [berichtigt]' : '') . (count($e['fotos']) ? '  [' . count($e['fotos']) . ' Foto(s)]' : '');
            })->implode("\n");
            $datum = Carbon::parse($tag)->format('d.m.Y');
            $text = "$label – Tagesbericht\nObjekt: {$o->objektname}\nTag: $datum (ab " . Qm::tagBeginn() . " Uhr)\n"
                . ($namen !== '' ? "Im Dienst: $namen\n" : '')
                . 'Einträge: ' . $eintraege->count() . ' · Vorkommnisse/Alarme: ' . $vk . "\n\n" . $zeilen
                . "\n\nFotos und Details sehen Sie im Kundenportal.";
            $sent = QmNotify::mail(explode(',', (string) $o->bericht_empfaenger), 'wachbuch.tagesbericht',
                "$label Tagesbericht $datum: {$o->objektname}", $text, 'kunde', $o->obj_id, 'objekt', $key);
            // A queue failure must not consume the idempotency key: retry on the next run.
            if (!$sent && !array_filter(array_map('trim', explode(',', (string) $o->bericht_empfaenger)),
                fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))) {
                QmNotify::merke('wachbuch.tagesbericht', 'objekt', $key, 'Tagesbericht nicht versendet (keine gültige Adresse) ' . $tag);
            }
        }
        return $n;
    }

    // ---------------------------------------------------------------

    /** Bell (+ app push) for the users; optionally an e-mail as well. Always leaves the "sent once" marker. */
    private function melde(array $ids, string $ereignis, string $titel, string $text, string $link, string $bezugTyp, $bezugId, bool $mail = false): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            QmNotify::merke($ereignis, $bezugTyp, $bezugId, $titel . ' (kein Empfänger mit QM-Rolle)');
            return;
        }
        QmNotify::users($ids, $ereignis, $titel, $text, $link, $bezugTyp, $bezugId);
        if ($mail) {
            QmNotify::mail(CentralUser::whereIn('id', $ids)->where('aktiv', 1)->pluck('email')->all(), $ereignis, $titel, $text, 'user', implode(',', $ids), $bezugTyp, $bezugId);
        }
        // QmNotify swallows delivery errors — make sure the marker exists so nothing repeats every run.
        if (!QmNotify::schonGesendet($ereignis, $bezugTyp, $bezugId)) QmNotify::merke($ereignis, $bezugTyp, $bezugId, $titel);
    }
}
