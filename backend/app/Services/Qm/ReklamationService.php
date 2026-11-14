<?php

namespace App\Services\Qm;

use App\Support\Qm\Qm;
use App\Support\Qm\QmRechte;
use Carbon\Carbon;

/**
 * Reklamation workflow (spec §7, ISO 9001 8.2.1 / 10.2):
 * Eingang → Eingangsbestätigung (within N h) → Sofortmaßnahme → Ursache
 * (berechtigt?) → Antwort an den Kunden → Abschluss (Kosten). Deadlines per
 * Schwere. Nothing is ever deleted; every step is a qm_reklamation_schritte
 * row and every field change a qm_verlauf row.
 */
class ReklamationService
{
    public const STATUS = [
        'eingegangen' => 'Eingegangen', 'bestaetigt' => 'Bestätigt', 'in_bearbeitung' => 'In Bearbeitung',
        'beantwortet' => 'Beantwortet', 'abgeschlossen' => 'Abgeschlossen',
    ];
    /** What the customer sees per internal status. */
    public const STATUS_KUNDE = [
        'eingegangen' => 'Eingegangen', 'bestaetigt' => 'In Bearbeitung', 'in_bearbeitung' => 'In Bearbeitung',
        'beantwortet' => 'Beantwortet', 'abgeschlossen' => 'Abgeschlossen',
    ];
    public const SCHWERE = ['gering' => 'Gering', 'mittel' => 'Mittel', 'hoch' => 'Hoch', 'kritisch' => 'Kritisch'];
    public const KANAL = ['telefon' => 'Telefon', 'email' => 'E-Mail', 'portal' => 'Kundenportal', 'persoenlich' => 'Persönlich', 'begehung' => 'Begehung / Besuch'];
    public const KATEGORIEN = [
        'verhalten' => 'Verhalten / Auftreten', 'anwesenheit' => 'Anwesenheit / Pünktlichkeit',
        'kontrolle' => 'Kontrollgang / Rundgang', 'dienstanweisung' => 'Dienstanweisung nicht befolgt',
        'dokumentation' => 'Dokumentation / Wachbuch', 'erscheinungsbild' => 'Erscheinungsbild',
        'kommunikation' => 'Kommunikation', 'sonstiges' => 'Sonstiges',
    ];
    public const SCHRITTE = [
        'eingang' => 'Eingang', 'bestaetigung' => 'Eingangsbestätigung', 'sofortmassnahme' => 'Sofortmaßnahme',
        'ursache' => 'Ursache', 'antwort' => 'Antwort an Kunde', 'zustaendig' => 'Zuständigkeit',
        'eskalation' => 'Eskalation', 'abschluss' => 'Abschluss', 'notiz' => 'Notiz', 'massnahme' => 'Maßnahme',
    ];

    /** $d: obj_id, text, kategorie, schwere, kanal, melder, eingang_am, dienst_nr, zustaendig_id; $zugang = portal access when the customer reports. */
    public static function anlegen(array $d, $u, ?object $zugang = null): object
    {
        $obj = Qm::t('objekte')->where('obj_id', $d['obj_id'])->first(['obj_id', 'kunden_id', 'objektname']);
        $eingang = !empty($d['eingang_am']) ? Carbon::parse($d['eingang_am']) : now();
        $schwere = $d['schwere'] ?? 'mittel';
        $zust = $d['zustaendig_id'] ?? Qm::objektEinstellung($obj->obj_id)->objektleiter_id;
        $id = Qm::t('qm_reklamationen')->insertGetId([
            'nr' => Qm::nummer('R'), 'obj_id' => $obj->obj_id, 'kunde_id' => $obj->kunden_id,
            'eingang_am' => $eingang, 'kanal' => $zugang ? 'portal' : ($d['kanal'] ?? 'telefon'),
            'melder' => $zugang ? ($zugang->name ?: $zugang->username) : ($d['melder'] ?? null),
            'melder_zugang_id' => $zugang->id ?? null,
            'text' => $d['text'], 'kategorie' => $d['kategorie'] ?? null, 'schwere' => $schwere,
            'dienst_nr' => $d['dienst_nr'] ?? null, 'zustaendig_id' => $zust, 'status' => 'eingegangen',
            'frist_bestaetigung' => $eingang->copy()->addHours(max(1, Qm::intSetting('rek_bestaetigung_std', 24))),
            'frist_erledigung' => $eingang->copy()->setTimezone('Europe/Berlin')->addDays(max(1, Qm::intSetting('rek_frist_' . $schwere, 7)))->toDateString(),
            'erstellt_von' => $u->id ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = Qm::t('qm_reklamationen')->find($id);
        self::schritt($r, 'eingang', $d['text'], $u, $zugang ? ($zugang->name ?: 'Kunde') : null);
        Qm::log('reklamation', $id, 'angelegt', null, ['nr' => $r->nr, 'schwere' => $schwere], $u, $zugang ? 'über Kundenportal' : null);

        $empf = $zust ? [(int) $zust] : QmNotify::objektleitung($obj->obj_id);
        $titel = 'Neue Reklamation ' . $r->nr . ': ' . ($obj->objektname ?? $obj->obj_id);
        QmNotify::users($empf, 'reklamation.created', $titel, mb_substr($d['text'], 0, 200), '#qm?view=reklamationen&id=' . $id, 'reklamation', $id);
        QmNotify::mail(\App\Models\CentralUser::whereIn('id', $empf)->pluck('email')->all(), 'reklamation.created', $titel,
            $d['text'] . "\n\nSchwere: " . (self::SCHWERE[$schwere] ?? $schwere), 'user', implode(',', $empf), 'reklamation', $id);
        Qm::changed('reklamation', (string) $obj->obj_id);
        return $r;
    }

    /**
     * One workflow step. @return string|null error
     * $aktion: bestaetigen | sofortmassnahme | ursache | antworten | zustaendig | abschliessen | notiz
     */
    public static function aktion(object $r, string $aktion, array $d, $u): ?string
    {
        if ($r->status === 'abgeschlossen' && $aktion !== 'notiz') return 'Die Reklamation ist abgeschlossen.';
        $recht = [
            'bestaetigen' => 'reklamation_antworten', 'antworten' => 'reklamation_antworten',
            'sofortmassnahme' => 'reklamation_bearbeiten', 'ursache' => 'reklamation_bearbeiten',
            'zustaendig' => 'reklamation_zustaendig', 'abschliessen' => 'reklamation_abschliessen',
            'notiz' => 'reklamation_anlegen',
        ][$aktion] ?? null;
        if (!$recht) return 'Unbekannte Aktion.';
        if (!QmRechte::darf($u, $recht)) return 'Dafür fehlt die Berechtigung (' . QmRechte::STANDARD[$recht][0] . ').';

        $patch = [];
        $text = trim((string) ($d['text'] ?? ''));
        switch ($aktion) {
            case 'bestaetigen':
                if ($r->bestaetigt_am) return 'Der Eingang wurde bereits bestätigt.';
                $text = $text ?: 'Vielen Dank für Ihre Meldung. Wir haben Ihre Reklamation ' . $r->nr . ' erhalten und kümmern uns darum.';
                $patch = ['bestaetigt_am' => now(), 'status' => $r->status === 'eingegangen' ? 'bestaetigt' : $r->status];
                self::anKunde($r, 'Eingangsbestätigung Reklamation ' . $r->nr, $text);
                break;
            case 'sofortmassnahme':
                if ($text === '') return 'Bitte die Sofortmaßnahme beschreiben.';
                $patch = ['sofortmassnahme' => $text, 'status' => in_array($r->status, ['eingegangen', 'bestaetigt'], true) ? 'in_bearbeitung' : $r->status];
                break;
            case 'ursache':
                if ($text === '') return 'Bitte die Ursache eintragen.';
                if (!array_key_exists('berechtigt', $d) || $d['berechtigt'] === null) return 'Bitte angeben, ob die Reklamation berechtigt ist.';
                $patch = ['ursache' => $text, 'berechtigt' => (bool) $d['berechtigt'], 'status' => in_array($r->status, ['eingegangen', 'bestaetigt'], true) ? 'in_bearbeitung' : $r->status];
                break;
            case 'antworten':
                if ($text === '') return 'Bitte die Antwort an den Kunden schreiben.';
                $patch = ['antwort' => $text, 'beantwortet_am' => now(), 'status' => 'beantwortet', 'bestaetigt_am' => $r->bestaetigt_am ?? now()];
                self::anKunde($r, 'Antwort zu Ihrer Reklamation ' . $r->nr, $text);
                break;
            case 'zustaendig':
                $neu = (int) ($d['zustaendig_id'] ?? 0);
                if (!isset(Qm::userNames()[$neu])) return 'Bitte einen gültigen Benutzer wählen.';
                $patch = ['zustaendig_id' => $neu];
                $text = 'Zuständig: ' . Qm::userNames()[$neu] . ($text ? ' — ' . $text : '');
                QmNotify::users([$neu], 'reklamation.assigned', 'Reklamation ' . $r->nr . ' an dich übergeben', mb_substr((string) $r->text, 0, 200), '#qm?view=reklamationen&id=' . $r->id, 'reklamation', $r->id);
                break;
            case 'abschliessen':
                if (!$r->antwort) return 'Vor dem Abschluss muss der Kunde eine Antwort erhalten haben.';
                if ($r->berechtigt === null) return 'Vor dem Abschluss bitte die Ursache und „berechtigt ja/nein" eintragen.';
                $kosten = isset($d['kosten_eur']) && $d['kosten_eur'] !== '' ? round((float) $d['kosten_eur'], 2) : null;
                $patch = ['status' => 'abgeschlossen', 'abgeschlossen_am' => now(), 'abgeschlossen_von' => $u->id ?? null, 'kosten_eur' => $kosten];
                $text = $text ?: 'Reklamation abgeschlossen' . ($kosten !== null ? ' (Kosten ' . number_format($kosten, 2, ',', '.') . ' €)' : '');
                break;
            case 'notiz':
                if ($text === '') return 'Bitte einen Text eingeben.';
                break;
        }

        if ($patch) {
            $diff = Qm::diff($r, $patch);
            Qm::t('qm_reklamationen')->where('id', $r->id)->update($patch + ['updated_at' => now()]);
            if ($diff) Qm::log('reklamation', $r->id, $aktion, $diff[0], $diff[1], $u);
        }
        self::schritt($r, $aktion === 'bestaetigen' ? 'bestaetigung' : ($aktion === 'antworten' ? 'antwort' : ($aktion === 'abschliessen' ? 'abschluss' : $aktion)),
            $text, $u, null, in_array($aktion, ['bestaetigen', 'antworten'], true));
        Qm::changed('reklamation', (string) $r->obj_id);
        return null;
    }

    public static function schritt(object $r, string $art, ?string $text, $u, ?string $name = null, bool $anKunde = false): void
    {
        Qm::t('qm_reklamation_schritte')->insert([
            'reklamation_id' => $r->id, 'art' => $art, 'text' => $text, 'an_kunde' => $anKunde,
            'von_id' => $u->id ?? null, 'von_name' => $name ?? ($u ? Qm::userName($u) : 'System'), 'am' => now(),
        ]);
    }

    /** E-mail to the reporting portal user (or every portal access of the customer for the Objekt). */
    private static function anKunde(object $r, string $titel, string $text): void
    {
        $q = Qm::t('kunden_portal_zugaenge')->where('kunde_id', $r->kunde_id)->where('aktiv', 1);
        $zugaenge = $r->melder_zugang_id ? $q->where('id', $r->melder_zugang_id)->get() : $q->get()->filter(fn ($z) => PortalService::zugangSiehtObjekt($z, $r->obj_id));
        QmNotify::mail($zugaenge->pluck('email')->filter()->all(), 'reklamation.answered', $titel, $text, 'kunde', $r->kunde_id, 'reklamation', $r->id);
    }

    /** Overdue flags (Berlin time). */
    public static function flags(object $r): array
    {
        $offen = $r->status !== 'abgeschlossen';
        return [
            'bestaetigung_ueberfaellig' => $offen && !$r->bestaetigt_am && $r->frist_bestaetigung && now()->gt(Carbon::parse($r->frist_bestaetigung)),
            'ueberfaellig' => $offen && $r->frist_erledigung && Qm::heute() > substr((string) $r->frist_erledigung, 0, 10),
        ];
    }

    /** Customer version: whitelist only — no internal cause, costs, worker names or internal steps. */
    public static function kundenFassung(object $r): array
    {
        $schritte = Qm::t('qm_reklamation_schritte')->where('reklamation_id', $r->id)
            ->where(fn ($q) => $q->where('an_kunde', 1)->orWhere('art', 'eingang'))->orderBy('am')->get();
        return [
            'id' => (int) $r->id, 'nr' => $r->nr, 'obj_id' => $r->obj_id, 'eingang_am' => $r->eingang_am,
            'text' => $r->text, 'status' => $r->status, 'status_label' => self::STATUS_KUNDE[$r->status] ?? $r->status,
            'antwort' => $r->antwort, 'beantwortet_am' => $r->beantwortet_am,
            'frist' => $r->status === 'abgeschlossen' ? null : substr((string) $r->frist_erledigung, 0, 10),
            'verlauf' => $schritte->map(fn ($s) => [
                'art' => $s->art === 'eingang' ? 'Gemeldet' : (self::SCHRITTE[$s->art] ?? $s->art),
                'text' => $s->text, 'am' => $s->am,
            ])->values(),
        ];
    }
}
