<?php

namespace App\Support\Qm;

/**
 * Rechte-Tabelle of the QM module (spec §3). Three roles come from the
 * permission keys; WHAT each role may do is this table — editable by the QMB
 * in QM-Einstellungen › Zuständigkeit & Rechte (stored as JSON in
 * qm_einstellungen.rechte, merged over STANDARD).
 *
 * The Inhaber holds every key, so he is always QMB; everyone else gets the
 * roles only by his explicit grant (PermissionRegistry::OWNER_GRANT).
 */
class QmRechte
{
    public const ROLLEN = [
        'qmb'          => 'qm.qmb',
        'buero'        => 'qm.buero',
        'objektleiter' => 'qm.objektleitung',
    ];

    public const ROLLEN_LABEL = ['qmb' => 'QMB', 'objektleiter' => 'Objektleiter', 'buero' => 'Büro'];

    /** aktion => [label, allowed roles] */
    public const STANDARD = [
        'reklamation_anlegen'      => ['Reklamation anlegen', ['qmb', 'objektleiter', 'buero']],
        'reklamation_antworten'    => ['Eingangsbestätigung / Antwort an Kunde senden', ['qmb', 'buero']],
        'reklamation_bearbeiten'   => ['Sofortmaßnahme, Ursache eintragen', ['qmb', 'objektleiter']],
        'reklamation_zustaendig'   => ['Zuständigkeit ändern', ['qmb', 'objektleiter', 'buero']],
        'reklamation_abschliessen' => ['Reklamation abschließen', ['qmb']],
        'massnahme_bearbeiten'     => ['Maßnahme anlegen / bearbeiten / Frist verlängern', ['qmb', 'objektleiter']],
        'massnahme_wirksamkeit'    => ['Wirksamkeit bestätigen', ['qmb']],
        'da_anlegen'               => ['Dienstanweisung anlegen (Entwurf)', ['qmb', 'objektleiter']],
        'da_freigeben'             => ['Dienstanweisung freigeben', ['qmb']],
        'wachbuch_eintrag'         => ['Wachbuch: Eintrag / Berichtigung aus dem Büro', ['qmb', 'objektleiter', 'buero']],
        'wachbuch_gegenzeichnen'   => ['Wachbuch gegenzeichnen', ['qmb', 'objektleiter']],
        'terminal_pin'             => ['Terminal-PIN und Mitarbeiter-PIN erzeugen / sperren', ['qmb', 'objektleiter']],
        'kundenzugaenge'           => ['Kundenzugänge verwalten', ['qmb', 'buero']],
        'objekt_einstellungen'     => ['QM-Einstellung je Objekt ändern', ['qmb']],
        'einstellungen'            => ['QM-Einstellungen ändern', ['qmb']],
    ];

    /** Roles held by the user (qmb / buero / objektleiter). */
    public static function rollen($u): array
    {
        if (!$u || !method_exists($u, 'hasPermission')) return [];
        return array_values(array_keys(array_filter(self::ROLLEN, fn ($perm) => $u->hasPermission($perm))));
    }

    /** Effective table: STANDARD merged with the tenant's override. */
    public static function tabelle(): array
    {
        return Qm::memo('rechte', function () {
            $over = json_decode((string) Qm::setting('rechte', ''), true);
            $out = [];
            foreach (self::STANDARD as $k => [$label, $rollen]) {
                $r = is_array($over[$k] ?? null) ? array_values(array_intersect(array_keys(self::ROLLEN), $over[$k])) : $rollen;
                // QM settings stay QMB-only — nobody can lock himself out or escalate.
                if ($k === 'einstellungen') $r = ['qmb'];
                // The QMB can do everything: no action may end up without any role.
                if (!in_array('qmb', $r, true)) array_unshift($r, 'qmb');
                $out[$k] = ['label' => $label, 'rollen' => $r];
            }
            return $out;
        });
    }

    public static function darf($u, string $aktion): bool
    {
        $erlaubt = self::tabelle()[$aktion]['rollen'] ?? [];
        return (bool) array_intersect(self::rollen($u), $erlaubt);
    }

    /** All actions of the user as a flat map for the frontend. */
    public static function fuer($u): array
    {
        $out = [];
        foreach (array_keys(self::STANDARD) as $k) $out[$k] = self::darf($u, $k);
        return $out;
    }
}
