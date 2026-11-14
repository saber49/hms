<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One definition of "this shift is booked" for every path that could remove
 * or rewrite einsaetze (Bestelleingang edit/delete, Bestellungen delete,
 * Objekt delete, Dispo delete).
 *
 * Booked = confirmed (hms_bestaetigt='ja'), or planned (pruefung='geplant')
 * with internal workers OR NU workers. Grey tombstones (bestell_storno_at)
 * are already dead for their crew and never count.
 */
class StaffedShifts
{
    /**
     * Booked shifts among the given einsaetze rows (needs einsatz_id,
     * hms_bestaetigt, pruefung, bestell_storno_at, datum, ist_start, ist_ende).
     */
    public static function booked(iterable $einsaetze, bool $includeConfirmed = true): array
    {
        $rows = collect($einsaetze)->filter(function ($e) use ($includeConfirmed) {
            if (!empty($e->bestell_storno_at ?? null)) return false;
            if (($e->hms_bestaetigt ?? null) === 'ja') return $includeConfirmed;
            return ($e->pruefung ?? '') === 'geplant';
        });
        if ($rows->isEmpty()) return [];

        $crew = self::crew($rows->pluck('einsatz_id')->all());
        return $rows->filter(fn ($e) => ($e->hms_bestaetigt ?? null) === 'ja' || isset($crew[$e->einsatz_id]))
            ->sortBy(fn ($e) => $e->datum . ' ' . self::hhmm($e->ist_start ?? null))
            ->map(fn ($e) => [
                'einsatz_id' => $e->einsatz_id,
                'confirmed'  => ($e->hms_bestaetigt ?? null) === 'ja',
                'label'      => date('d.m.Y', strtotime((string) $e->datum)) . ' '
                    . self::hhmm($e->ist_start ?? null) . '–' . self::hhmm($e->ist_ende ?? null)
                    . (isset($crew[$e->einsatz_id]) ? ' (' . implode(', ', $crew[$e->einsatz_id]) . ')' : '')
                    . (($e->hms_bestaetigt ?? null) === 'ja' ? ' — bestätigt' : ''),
            ])->values()->all();
    }

    /** einsatz_id → ["Vorname Name", "NU Firma (2)", …] */
    public static function crew(array $einsatzIds): array
    {
        $crew = [];
        if (!$einsatzIds) return $crew;
        $db = DB::connection('tenant');
        foreach ($db->table('einsatz_mitarbeiter as em')
            ->leftJoin('mitarbeiter as m', 'm.dienst_nr', '=', 'em.dienst_nr')
            ->whereIn('em.einsatz_id', $einsatzIds)
            ->get(['em.einsatz_id', 'm.vorname', 'm.name', 'em.dienst_nr']) as $c) {
            $crew[$c->einsatz_id][] = trim(($c->vorname ?? '') . ' ' . ($c->name ?? '')) ?: $c->dienst_nr;
        }
        foreach ($db->table('nu_schicht_positionen as p')
            ->join('nu_position_zuweisungen as z', 'z.position_id', '=', 'p.id')
            ->leftJoin('nachunternehmer as n', 'n.nu_id', '=', 'p.nu_id')
            ->whereIn('p.einsatz_id', $einsatzIds)
            ->selectRaw('p.einsatz_id, COALESCE(n.firmenname, p.nu_id) AS firma, COUNT(*) AS anzahl')
            ->groupBy('p.einsatz_id', 'firma')->get() as $c) {
            $crew[$c->einsatz_id][] = 'NU ' . $c->firma . ' (' . $c->anzahl . ')';
        }
        return $crew;
    }

    /** Booked shifts of the given bestellungen (bestell_ids). */
    public static function bookedForBestellungen(array $bestellIds, bool $includeConfirmed = true): array
    {
        if (!$bestellIds) return [];
        $q = DB::connection('tenant')->table('einsaetze')->whereIn('bestell_id', $bestellIds);
        if (!Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) {
            $q->selectRaw('einsaetze.*, NULL AS bestell_storno_at');
        }
        return self::booked($q->get(), $includeConfirmed);
    }

    public static function hhmm($v): string
    {
        return ($v !== null && preg_match('/(\d{1,2}):(\d{2})/', (string) $v, $m))
            ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] : '–';
    }
}
