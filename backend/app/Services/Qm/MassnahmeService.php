<?php

namespace App\Services\Qm;

use App\Support\Qm\Qm;
use Carbon\Carbon;

/**
 * Maßnahmen (ISO 9001 10.2 / 10.3): source, owner, deadline (extensions need
 * a reason and are capped), completion with proof, effectiveness check after
 * N days, optional follow-up measure when not effective.
 */
class MassnahmeService
{
    public const QUELLEN = [
        'reklamation' => 'Reklamation', 'wachbuch' => 'Wachbuch', 'begehung' => 'Begehung / Besuch',
        'audit' => 'Internes Audit', 'manuell' => 'Manuell',
    ];
    public const ARTEN = ['korrektur' => 'Korrektur', 'korrektiv' => 'Korrekturmaßnahme', 'vorbeugung' => 'Vorbeugemaßnahme'];
    public const STATUS = ['offen' => 'Offen', 'erledigt' => 'Erledigt – Wirksamkeit prüfen', 'wirksam' => 'Wirksam', 'unwirksam' => 'Nicht wirksam'];

    /** $d: titel, text, ursache, art, quelle_typ, quelle_id, obj_id, verantwortlich_id, frist, im_kundenportal */
    public static function anlegen(array $d, $u): object
    {
        $id = Qm::t('qm_massnahmen')->insertGetId([
            'nr' => Qm::nummer('M'), 'quelle_typ' => $d['quelle_typ'] ?? 'manuell', 'quelle_id' => $d['quelle_id'] ?? null,
            'obj_id' => $d['obj_id'] ?? null, 'art' => $d['art'] ?? 'korrektur', 'titel' => $d['titel'],
            'ursache' => $d['ursache'] ?? null, 'text' => $d['text'] ?? null,
            'verantwortlich_id' => $d['verantwortlich_id'] ?? null, 'frist' => $d['frist'],
            'im_kundenportal' => !empty($d['im_kundenportal']), 'status' => 'offen',
            'erstellt_von' => $u->id ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m = Qm::t('qm_massnahmen')->find($id);
        Qm::log('massnahme', $id, 'angelegt', null, ['nr' => $m->nr, 'quelle' => $m->quelle_typ, 'frist' => $m->frist], $u);
        if ($m->quelle_typ === 'reklamation' && $m->quelle_id) {
            $r = Qm::t('qm_reklamationen')->find((int) $m->quelle_id);
            if ($r) ReklamationService::schritt($r, 'massnahme', $m->nr . ': ' . $m->titel, $u);
        }
        self::zugewiesen($m, $u);
        Qm::changed('massnahme', $m->obj_id ? (string) $m->obj_id : null);
        return $m;
    }

    public static function bearbeiten(object $m, array $patch, $u): void
    {
        $patch = array_intersect_key($patch, array_flip(['titel', 'text', 'ursache', 'art', 'verantwortlich_id', 'im_kundenportal', 'obj_id']));
        if (array_key_exists('im_kundenportal', $patch)) $patch['im_kundenportal'] = (bool) $patch['im_kundenportal'];
        $diff = Qm::diff($m, $patch);
        if (!$diff) return;
        Qm::t('qm_massnahmen')->where('id', $m->id)->update($patch + ['updated_at' => now()]);
        Qm::log('massnahme', $m->id, 'bearbeitet', $diff[0], $diff[1], $u);
        if (isset($diff[1]['verantwortlich_id'])) self::zugewiesen(Qm::t('qm_massnahmen')->find($m->id), $u);
        Qm::changed('massnahme', $m->obj_id ? (string) $m->obj_id : null);
    }

    /** @return string|null error */
    public static function verlaengern(object $m, string $frist, string $grund, $u): ?string
    {
        if ($m->status !== 'offen') return 'Nur offene Maßnahmen können verlängert werden.';
        $max = Qm::intSetting('mn_max_verlaengerung', 2);
        if ((int) $m->frist_verlaengert >= $max) return "Die Frist wurde bereits $max-mal verlängert. Bitte eine Folgemaßnahme anlegen.";
        if ($frist <= substr((string) $m->frist, 0, 10)) return 'Die neue Frist muss nach der bisherigen liegen.';
        Qm::t('qm_massnahmen')->where('id', $m->id)->update(['frist' => $frist, 'frist_verlaengert' => (int) $m->frist_verlaengert + 1, 'updated_at' => now()]);
        Qm::log('massnahme', $m->id, 'frist_verlaengert', ['frist' => substr((string) $m->frist, 0, 10)], ['frist' => $frist], $u, $grund);
        Qm::changed('massnahme', $m->obj_id ? (string) $m->obj_id : null);
        return null;
    }

    public static function erledigen(object $m, string $nachweis, $u): ?string
    {
        if ($m->status !== 'offen') return 'Die Maßnahme ist nicht mehr offen.';
        $pruefung = Carbon::parse(Qm::heute())->addDays(max(0, Qm::intSetting('mn_wirksam_tage', 30)))->toDateString();
        Qm::t('qm_massnahmen')->where('id', $m->id)->update([
            'status' => 'erledigt', 'erledigt_am' => now(), 'erledigt_von' => $u->id ?? null, 'nachweis' => $nachweis,
            'wirksam_pruefung_am' => $pruefung, 'updated_at' => now(),
        ]);
        Qm::log('massnahme', $m->id, 'erledigt', ['status' => $m->status], ['status' => 'erledigt', 'wirksam_pruefung_am' => $pruefung], $u, $nachweis);
        Qm::changed('massnahme', $m->obj_id ? (string) $m->obj_id : null);
        return null;
    }

    /** Effectiveness check. Not effective + $folge → a follow-up measure is created. @return array{0: ?string, 1: ?object} */
    public static function wirksamkeit(object $m, string $ergebnis, string $text, $u, ?array $folge = null): array
    {
        if ($m->status !== 'erledigt') return ['Wirksamkeit kann erst nach der Erledigung bewertet werden.', null];
        $neu = null;
        if ($ergebnis === 'unwirksam' && $folge) {
            $neu = self::anlegen($folge + [
                'quelle_typ' => $m->quelle_typ, 'quelle_id' => $m->quelle_id, 'obj_id' => $m->obj_id,
                'ursache' => $m->ursache, 'art' => 'korrektiv',
            ], $u);
        }
        Qm::t('qm_massnahmen')->where('id', $m->id)->update([
            'status' => $ergebnis, 'wirksamkeit' => $ergebnis, 'wirksamkeit_text' => $text,
            'wirksamkeit_am' => now(), 'wirksamkeit_von' => $u->id ?? null, 'folge_id' => $neu->id ?? null, 'updated_at' => now(),
        ]);
        Qm::log('massnahme', $m->id, 'wirksamkeit', ['status' => 'erledigt'], ['status' => $ergebnis, 'folge' => $neu->nr ?? null], $u, $text);
        Qm::changed('massnahme', $m->obj_id ? (string) $m->obj_id : null);
        return [null, $neu];
    }

    private static function zugewiesen(object $m, $u): void
    {
        if (!$m->verantwortlich_id || (int) $m->verantwortlich_id === (int) ($u->id ?? 0)) return;
        QmNotify::users([(int) $m->verantwortlich_id], 'massnahme.assigned', 'Maßnahme ' . $m->nr . ' zugewiesen',
            $m->titel . ' — Frist ' . Carbon::parse($m->frist)->format('d.m.Y'), '#qm?view=massnahmen&id=' . $m->id, 'massnahme', $m->id);
    }

    public static function flags(object $m): array
    {
        $heute = Qm::heute();
        return [
            'ueberfaellig' => $m->status === 'offen' && substr((string) $m->frist, 0, 10) < $heute,
            'pruefung_faellig' => $m->status === 'erledigt' && $m->wirksam_pruefung_am && substr((string) $m->wirksam_pruefung_am, 0, 10) <= $heute,
        ];
    }
}
