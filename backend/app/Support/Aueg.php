<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AÜG deadlines per (Leiharbeitnehmer, Entleiher) computed from the planned /
 * worked shifts (einsatz_mitarbeiter × einsaetze.kunden_id).
 *
 * Rules (§ 1 Abs. 1b, § 8 Abs. 4 AÜG; FW AÜG 1.5.1):
 * - Überlassungshöchstdauer: N months (default 18) per worker and Entleiher,
 *   a month period from the first day (§ 187 Abs. 2, § 188 Abs. 2 BGB):
 *   start 03.04.2026 → allowed until 02.10.2027.
 * - Earlier periods count fully when no more than 3 months lie between them;
 *   a longer break restarts the count. The break itself is not counted.
 *   Added-up periods use 30 days per month (§ 191 BGB).
 * - Equal Pay: same calculation with M months (default 9).
 *
 * Shift data has no contract periods, so shifts with gaps of up to
 * LUECKE_TAGE free days are treated as ONE continuous Überlassung
 * (weekends / days off inside an assignment).
 */
class Aueg
{
    public const LUECKE_TAGE = 7;
    public const UNTERBRECHUNG_MONATE = 3;
    public const WARN_TAGE = 60;

    /** Calendar day in UTC (no DST effects on day differences). */
    private static function day(string $ymd): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', substr($ymd, 0, 10), 'UTC');
    }

    /** Whole days from $a to $b (Carbon 3 returns signed floats). */
    private static function days(Carbon $a, Carbon $b): int
    {
        return (int) round($a->diffInDays($b, false));
    }

    /** Tenant switch; default on for the "Personaldienstleistung" profile only. */
    public static function enabled(?Tenant $tenant): bool
    {
        return false;
    }

    public static function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('aueg_entleiher');
    }

    /** Active Entleiher: kunde_id => ['max' => months, 'ep' => months]. */
    public static function entleiher(?array $kundeIds = null): array
    {
        if (!self::ready()) return [];
        $q = DB::connection('tenant')->table('aueg_entleiher')->where('aktiv', 1);
        if ($kundeIds !== null) $q->whereIn('kunde_id', $kundeIds ?: ['']);
        $out = [];
        foreach ($q->get() as $r) {
            $out[$r->kunde_id] = ['max' => (int) $r->hoechstdauer_monate, 'ep' => (int) $r->equal_pay_monate];
        }
        return $out;
    }

    /**
     * Distinct shift dates per worker at the given customers.
     * @return array [kunde_id][dienst_nr] => sorted list of Y-m-d
     */
    public static function dates(array $kundeIds, ?array $dienstNrs = null): array
    {
        if (!$kundeIds) return [];
        $q = DB::connection('tenant')->table('einsatz_mitarbeiter as em')
            ->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
            ->whereIn('e.kunden_id', $kundeIds)
            ->where('e.hms_bestaetigt', '!=', 'nein')
            ->whereNotNull('e.datum');
        if (Schema::connection('tenant')->hasColumn('einsaetze', 'bestell_storno_at')) $q->whereNull('e.bestell_storno_at');
        if ($dienstNrs !== null) $q->whereIn('em.dienst_nr', $dienstNrs ?: ['']);
        $out = [];
        foreach ($q->distinct()->get(['e.kunden_id', 'em.dienst_nr', 'e.datum']) as $r) {
            $out[$r->kunden_id][$r->dienst_nr][] = substr((string) $r->datum, 0, 10);
        }
        foreach ($out as &$byWorker) {
            foreach ($byWorker as &$list) { $list = array_values(array_unique($list)); sort($list); }
        }
        return $out;
    }

    /**
     * Groups sorted dates into Überlassungs-Ketten (chains), each a list of
     * continuous periods [Carbon start, Carbon end].
     */
    public static function chains(array $dates): array
    {
        $periods = [];
        foreach ($dates as $d) {
            $day = self::day($d);
            $n = count($periods);
            if ($n && self::days($periods[$n - 1][1], $day) <= self::LUECKE_TAGE + 1) {
                if ($day->gt($periods[$n - 1][1])) $periods[$n - 1][1] = $day;
            } else {
                $periods[] = [$day, $day->copy()];
            }
        }
        $chains = [];
        foreach ($periods as $p) {
            $c = count($chains);
            $lastEnd = $c ? end($chains[$c - 1])[1] : null;
            if ($lastEnd && $p[0]->lte($lastEnd->copy()->addMonthsNoOverflow(self::UNTERBRECHUNG_MONATE))) {
                $chains[$c - 1][] = $p;
            } else {
                $chains[] = [$p];
            }
        }
        return $chains;
    }

    /**
     * Day on which `months` are reached inside a chain. $isEnd = last allowed
     * day (Höchstdauer), otherwise the first day of the new state (Equal Pay).
     * Beyond the planned periods the last period is projected forward.
     */
    public static function reachDate(array $chain, int $months, bool $isEnd): Carbon
    {
        $prior = 0;
        $last = count($chain) - 1;
        foreach ($chain as $i => [$s, $e]) {
            if ($i === 0) {
                // § 188 Abs. 2/3 BGB: ends the day before the same date N months
                // later; if that date does not exist (31.08. → Feb.), on the last
                // day of that month.
                $plus = $s->copy()->addMonthsNoOverflow($months);
                $end = $plus->day < $s->day ? $plus : $plus->copy()->subDay();
                $cand = $isEnd ? $end : $end->copy()->addDay();
            } else {
                $rem = max(0, $months * 30 - $prior);
                $cand = $s->copy()->addDays($isEnd ? max(0, $rem - 1) : $rem);
            }
            if ($cand->lte($e) || $i === $last) return $cand;
            $prior += self::days($s, $e) + 1;
        }
        return Carbon::now(); // unreachable (empty chain)
    }

    /** Counted Überlassungstage of a chain up to (and including) $asOf. */
    public static function countedDays(array $chain, Carbon $asOf): int
    {
        $n = 0;
        foreach ($chain as [$s, $e]) {
            if ($s->gt($asOf)) break;
            $n += self::days($s, $e->lt($asOf) ? $e : $asOf) + 1;
        }
        return $n;
    }

    /**
     * Status of one worker at one Entleiher on $asOf (Y-m-d).
     * $includeAsOf: count $asOf as a shift day (Dispo: the shift being planned).
     * Returns null when no chain is relevant on that day.
     */
    public static function evaluate(array $dates, string $asOf, array $cfg, bool $includeAsOf = false): ?array
    {
        if ($includeAsOf) { $dates[] = $asOf; $dates = array_values(array_unique($dates)); sort($dates); }
        if (!$dates) return null;
        $day = self::day($asOf);
        $chains = self::chains($dates);

        // Chain running on $asOf (started, and not broken off by > 3 months);
        // otherwise the next planned one.
        $chain = null; $geplant = false;
        foreach ($chains as $c) {
            if ($c[0][0]->lte($day)) {
                $chain = $c;
            } elseif (!$chain || end($chain)[1]->copy()->addMonthsNoOverflow(self::UNTERBRECHUNG_MONATE)->lt($day)) {
                $chain = $c; $geplant = true; break;
            } else break;
        }
        if (!$chain) return null;
        if (!$geplant && end($chain)[1]->copy()->addMonthsNoOverflow(self::UNTERBRECHUNG_MONATE)->lt($day)) return null;

        $limit = self::reachDate($chain, $cfg['max'], true);
        $ep = self::reachDate($chain, $cfg['ep'], false);
        $lastPlanned = end($chain)[1];
        $ref = $geplant ? $chain[0][0] : $day;
        $status = 'ok';
        if ($ref->gte($ep)) $status = 'equal_pay';
        if ($ref->gt($limit->copy()->subDays(self::WARN_TAGE))) $status = 'bald';
        if ($ref->gt($limit)) $status = 'ueberschritten';

        return [
            'beginn'            => $chain[0][0]->toDateString(),
            'tage'              => $geplant ? 0 : self::countedDays($chain, $day),
            'unterbrechungen'   => count($chain) - 1,
            'equal_pay_ab'      => $ep->toDateString(),
            'hoechstdauer_bis'  => $limit->toDateString(),
            'letzter_einsatz'   => $lastPlanned->toDateString(),
            'geplant_ueber_frist' => $lastPlanned->gt($limit),
            'status'            => $geplant ? ($lastPlanned->gt($limit) ? 'ueberschritten' : 'geplant') : $status,
            'max_monate'        => $cfg['max'],
            'ep_monate'         => $cfg['ep'],
        ];
    }
}
