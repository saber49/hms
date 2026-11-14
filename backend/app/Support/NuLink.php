<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * einsaetze.nu_id is the "this shift is (partly) NU work" flag Controlling and
 * the NU cost use. It must always mirror the NU positions of the shift —
 * never a stale client field, never a NU that cancelled/rejected.
 */
class NuLink
{
    /**
     * NU that should be on the einsatz: the current one if it still has
     * positions, else the first NU that has positions; null without positions.
     * Legacy direct links (nu_id without any positions) are left untouched.
     */
    public static function resolve(string $einsatzId, ?string $current): ?string
    {
        // First NU by position creation order (no DISTINCT+ORDER BY: strict mode).
        $nuIds = DB::connection('tenant')->table('nu_schicht_positionen')
            ->where('einsatz_id', $einsatzId)->orderBy('id')->pluck('nu_id')->filter()->unique()->values()->all();
        if (!$nuIds) return $current;
        return ($current !== null && $current !== '' && in_array($current, $nuIds, true)) ? $current : $nuIds[0];
    }

    /** Write the resolved NU (only when it differs). Returns the value. */
    public static function sync(string $einsatzId, bool $positionsJustRemoved = false): ?string
    {
        $current = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->value('nu_id');
        $hasPositions = DB::connection('tenant')->table('nu_schicht_positionen')->where('einsatz_id', $einsatzId)->exists();
        // Positions were just removed and none remain → the link is gone.
        $target = (!$hasPositions && $positionsJustRemoved) ? null : self::resolve($einsatzId, $current);
        if ($target !== $current) {
            DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->update(['nu_id' => $target]);
        }
        return $target;
    }

    /** [start, end) of a shift as timestamps (overnight → end next day). */
    public static function window(string $datum, ?string $von, ?string $bis): ?array
    {
        if (!$von || !$bis) return null;
        $s = strtotime(substr($datum, 0, 10) . ' ' . substr($von, 0, 5));
        $e = strtotime(substr($datum, 0, 10) . ' ' . substr($bis, 0, 5));
        if ($s === false || $e === false) return null;
        if ($e <= $s) $e += 86400;
        return [$s, $e];
    }

    /**
     * Other live shifts the NU worker is already assigned to that overlap the
     * given shift. Returns e.g. ["20.10.2026 18:00–06:00 (E-…)"].
     */
    public static function overlaps(int $nuWorkerId, object $einsatz): array
    {
        $win = self::window($einsatz->datum, $einsatz->ist_start, $einsatz->ist_ende);
        if (!$win) return [];
        $d = substr($einsatz->datum, 0, 10);
        $rows = DB::connection('tenant')->table('nu_position_zuweisungen as z')
            ->join('nu_schicht_positionen as p', 'p.id', '=', 'z.position_id')
            ->join('einsaetze as e', 'e.einsatz_id', '=', 'p.einsatz_id')
            ->where('z.nu_mitarbeiter_id', $nuWorkerId)
            ->where('e.einsatz_id', '!=', $einsatz->einsatz_id)
            ->whereBetween('e.datum', [date('Y-m-d', strtotime($d . ' -1 day')), date('Y-m-d', strtotime($d . ' +1 day'))])
            ->whereNull('e.bestell_storno_at')
            ->get(['e.einsatz_id', 'e.datum', 'e.ist_start', 'e.ist_ende']);
        $out = [];
        foreach ($rows as $r) {
            $w = self::window($r->datum, $r->ist_start, $r->ist_ende);
            if ($w && $w[0] < $win[1] && $win[0] < $w[1]) {
                $out[] = date('d.m.Y', strtotime($r->datum)) . ' ' . substr($r->ist_start, 0, 5) . '–' . substr($r->ist_ende, 0, 5) . ' (' . $r->einsatz_id . ')';
            }
        }
        return $out;
    }
}
