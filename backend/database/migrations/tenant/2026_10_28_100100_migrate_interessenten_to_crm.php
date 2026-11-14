<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Carries the old Interessentenverwaltung (interessenten +
 * interessent_kommunikation) into the Lead-Modul:
 *   interessent → Firma + Kontakt + Lead (+ Deal for Angebot/Verhandlung/Gewonnen)
 *   kommunikation → Aktivitäten on the Lead (attachments keep their public-disk path)
 *   open wiedervorlage_am → Aufgabe "Wiedervorlage" for the owner
 *
 * Old phase → new state:
 *   Erstkontakt          → Lead Neu (no entries) / Kontaktversuch
 *   Termin, Demo         → Lead Verbunden
 *   Angebot, Verhandlung → Lead Qualifiziert + Deal angebot_versendet / verhandlung
 *   Gewonnen             → Lead Qualifiziert + Deal gewonnen (kunde_id = konvertiert_zu)
 *   Verloren             → Lead Disqualifiziert (Grund Sonstiges)
 *
 * The old tables stay untouched (read-only archive). Idempotent: every
 * interessent already present as crm_leads.alt_interessent_id is skipped.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    private const BRANCHE_MAP = [
        'Industrie & Produktion' => 'industrie', 'Handel' => 'handel', 'Gesundheit & Pflege' => 'gesundheit',
        'Hotel & Gastronomie' => 'hotel_gastro', 'Wohnungswirtschaft' => 'wohnungswirtschaft',
        'Öffentliche Hand' => 'oeffentlich', 'Logistik' => 'logistik', 'Veranstaltung' => 'veranstaltung',
        'Sonstiges' => 'sonstiges',
    ];
    private const QUELLE_MAP = [
        'Empfehlung' => 'empfehlung', 'Kaltakquise' => 'kaltakquise', 'Website' => 'website_formular',
        'Messe' => 'messe_event', 'Sonstiges' => 'sonstiges',
    ];
    private const MA_MAP = ['< 15' => 15, '16-50' => 50, '51-100' => 100, '> 100' => 101];
    private const KOMM_MAP = ['Anruf' => 'anruf', 'E-Mail' => 'email', 'Termin' => 'termin', 'Demo' => 'termin', 'Notiz' => 'notiz'];

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (!$s->hasTable('interessenten') || !$s->hasTable('crm_leads')) {
            return;
        }
        $db = DB::connection($this->connection);

        $sparten = $s->hasTable('bereiche_katalog')
            ? $db->table('bereiche_katalog')->whereNull('parent_id')->pluck('name')->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n])->all()
            : [];
        $done = $db->table('crm_leads')->whereNotNull('alt_interessent_id')->pluck('alt_interessent_id')->flip();
        $hasKomm = $s->hasTable('interessent_kommunikation');
        $hasAufgaben = $s->hasTable('aufgaben') && $s->hasColumn('aufgaben', 'bezug_typ');

        foreach ($db->table('interessenten')->orderBy('created_at')->get() as $i) {
            if (isset($done[$i->interessent_id])) continue;

            try {
                $db->transaction(fn () => $this->migrateOne($db, $i, $sparten, $hasKomm, $hasAufgaben));
            } catch (\Throwable $e) {
                Log::warning('CRM migration skipped interessent', ['id' => $i->interessent_id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function migrateOne($db, object $i, array $sparten, bool $hasKomm, bool $hasAufgaben): void
    {
        $now = now();
        $created = $i->created_at ?: $now;
        $owner = (int) $i->zustaendiger_mitarbeiter;
        $komm = $hasKomm
            ? $db->table('interessent_kommunikation')->where('interessent_id', $i->interessent_id)->orderBy('datum')->get()
            : collect();
        $versuche = $komm->whereIn('typ', ['Anruf', 'E-Mail'])->count();
        $last = $komm->max('datum');

        [$stage, $dealPhase, $lifecycle] = match ($i->pipeline_phase) {
            'Termin', 'Demo'   => ['verbunden', null, 'sql'],
            'Angebot'          => ['qualifiziert', 'angebot_versendet', 'opportunity'],
            'Verhandlung'      => ['qualifiziert', 'verhandlung', 'opportunity'],
            'Gewonnen'         => ['qualifiziert', 'gewonnen', 'kunde'],
            'Verloren'         => ['disqualifiziert', null, 'sql'],
            default            => [$komm->isEmpty() ? 'neu' : 'kontaktversuch', null, 'sql'],
        };
        $sparte = $sparten[mb_strtolower((string) $i->branche)] ?? null;

        $firmaId = (string) Str::uuid();
        $db->table('crm_firmen')->insert([
            'id' => $firmaId, 'name' => $i->firmenname, 'ort' => $i->einsatzgebiet,
            'branche' => self::BRANCHE_MAP[$i->branche] ?? null,
            'mitarbeiterzahl' => self::MA_MAP[$i->mitarbeiterzahl_bereich] ?? null,
            'telefon' => $i->telefon, 'owner_id' => $owner, 'kunde_id' => $i->konvertiert_zu,
            'alt_interessent_id' => $i->interessent_id, 'created_at' => $created, 'updated_at' => $now,
        ]);

        $name = trim((string) $i->ansprechpartner_name);
        $pos = mb_strrpos($name, ' ');
        $kontaktId = (string) Str::uuid();
        $db->table('crm_kontakte')->insert([
            'id' => $kontaktId, 'firma_id' => $firmaId,
            'vorname' => $pos !== false ? mb_substr($name, 0, $pos) : null,
            'nachname' => $pos !== false ? mb_substr($name, $pos + 1) : ($name !== '' ? $name : '—'),
            'position' => $i->ansprechpartner_position, 'email' => $i->email, 'telefon' => $i->telefon,
            'lifecycle_stage' => $lifecycle, 'lifecycle_changed_at' => $i->updated_at ?: $created,
            'quelle' => self::QUELLE_MAP[$i->quelle] ?? 'sonstiges', 'owner_id' => $owner,
            'alt_interessent_id' => $i->interessent_id, 'created_at' => $created, 'updated_at' => $now,
        ]);

        $leadId = (string) Str::uuid();
        $dealId = $dealPhase ? (string) Str::uuid() : null;
        $db->table('crm_leads')->insert([
            'id' => $leadId, 'kontakt_id' => $kontaktId, 'firma_id' => $firmaId, 'sparte' => $sparte,
            'stage' => $stage, 'stage_changed_at' => $i->updated_at ?: $created, 'owner_id' => $owner,
            'prioritaet' => 'normal', 'anzahl_kontaktversuche' => $versuche, 'letzte_aktivitaet_am' => $last,
            'disq_grund' => $stage === 'disqualifiziert' ? 'sonstiges' : null,
            'disq_notiz' => $stage === 'disqualifiziert' ? 'Übernommen aus Interessent ' . $i->interessent_id . ' (Phase „Verloren“).' : null,
            'deal_id' => $dealId, 'alt_interessent_id' => $i->interessent_id,
            'created_at' => $created, 'updated_at' => $now,
        ]);

        if ($dealId) {
            $db->table('crm_deals')->insert([
                'id' => $dealId, 'lead_id' => $leadId, 'firma_id' => $firmaId, 'kontakt_id' => $kontaktId,
                'titel' => $i->firmenname . ($sparte ? ' – ' . $sparte : ''), 'sparte' => $sparte,
                'phase' => $dealPhase, 'phase_changed_at' => $i->updated_at ?: $created,
                'objektadresse' => $i->einsatzgebiet, 'erwarteter_abschluss' => $i->wiedervorlage_am,
                'owner_id' => $owner, 'kunde_id' => $i->konvertiert_zu,
                'created_at' => $created, 'updated_at' => $now,
            ]);
        }

        foreach ($komm as $k) {
            $typ = self::KOMM_MAP[$k->typ] ?? 'notiz';
            $notiz = trim((string) $k->notiz);
            if (!empty($k->naechster_schritt)) $notiz .= "\nNächster Schritt: " . $k->naechster_schritt;
            $db->table('crm_aktivitaeten')->insert([
                'kontakt_id' => $kontaktId, 'lead_id' => $leadId,
                'typ' => $typ, 'richtung' => in_array($typ, ['anruf', 'email'], true) ? 'ausgehend' : null,
                'zeitpunkt' => $k->datum, 'user_id' => $k->erfasst_von, 'notiz' => $notiz,
                'datei_disk' => $k->datei_pfad ? 'public' : null, 'datei_pfad' => $k->datei_pfad, 'datei_name' => $k->datei_name,
                'created_at' => $k->created_at ?: $k->datum, 'updated_at' => $now,
            ]);
        }

        $log = [['crm_kontakte', $kontaktId, 'lifecycle_stage', $lifecycle], ['crm_leads', $leadId, 'stage', $stage]];
        if ($dealId) $log[] = ['crm_deals', $dealId, 'phase', $dealPhase];
        foreach ($log as [$obj, $id, $feld, $neu]) {
            $db->table('crm_protokoll')->insert([
                'objekt' => ['crm_kontakte' => 'kontakt', 'crm_leads' => 'lead', 'crm_deals' => 'deal'][$obj],
                'objekt_id' => $id, 'feld' => $feld, 'alt' => null, 'neu' => $neu,
                'user_name' => 'Übernahme ' . $i->interessent_id . ' (alt: ' . $i->pipeline_phase . ')',
                'regel' => 'MIG', 'created_at' => $now,
            ]);
        }

        $offen = in_array($stage, ['neu', 'kontaktversuch', 'verbunden'], true);
        if ($hasAufgaben && $offen && $i->wiedervorlage_am && $owner) {
            $aid = $db->table('aufgaben')->insertGetId([
                'titel' => mb_substr($i->naechster_schritt ?: 'Wiedervorlage ' . $i->firmenname, 0, 200),
                'beschreibung' => 'Übernommen aus Interessent ' . $i->interessent_id,
                'bereich' => $sparte, 'bezug_typ' => 'lead', 'bezug_id' => $leadId, 'typ' => 'wiedervorlage',
                'prioritaet' => 'normal', 'faellig_am' => $i->wiedervorlage_am,
                'created_by' => $owner, 'created_by_name' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('aufgabe_empfaenger')->insert([
                'aufgabe_id' => $aid, 'typ' => 'user', 'user_id' => $owner, 'status' => 'offen',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Data carry-over only; rolled back together with 2026_10_28_100000.
    }
};
