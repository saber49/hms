<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Urlaubskonto — the ONE place where vacation balances are computed.
 *
 * Semantics (market standard, Personio-style):
 *   anspruch  = mitarbeiter.urlaubstage        (annual entitlement)
 *   genommen  = SUM(tage) of APPROVED 'urlaub' rows in the year
 *   beantragt = SUM(tage) of PENDING  'urlaub' rows in the year
 *   rest      = anspruch - genommen - beantragt
 *
 * Rejected rows never count. Both the admin portal and the worker app
 * must go through this service so the numbers can never diverge.
 */
class UrlaubskontoService
{
    /**
     * Balance for one worker for a given year.
     */
    public static function balance(string $dienstNr, ?int $year = null): array
    {
        $year      = $year ?: (int) date('Y');
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd   = sprintf('%04d-12-31', $year);

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $dienstNr)
            ->first(['urlaubstage']);

        $anspruch = $worker ? (float) ($worker->urlaubstage ?? 0) : 0.0;

        $row = DB::connection('tenant')->selectOne("
            SELECT
                COALESCE(SUM(CASE WHEN COALESCE(status,'genehmigt') = 'genehmigt' THEN tage ELSE 0 END), 0) AS genommen,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN tage ELSE 0 END), 0) AS beantragt
            FROM mitarbeiter_abwesenheiten
            WHERE dienst_nr = ?
              AND kategorie = 'urlaub'
              AND startdatum BETWEEN ? AND ?
        ", [$dienstNr, $yearStart, $yearEnd]);

        $genommen  = (float) ($row->genommen ?? 0);
        $beantragt = (float) ($row->beantragt ?? 0);

        return [
            'jahr'      => $year,
            'anspruch'  => $anspruch,
            'genommen'  => $genommen,
            'beantragt' => $beantragt,
            'rest'      => round($anspruch - $genommen - $beantragt, 1),
        ];
    }

    /**
     * Number of days between two dates, inclusive. Every calendar day counts
     * (weekends included) — workers also work on Saturdays/Sundays.
     * Half-day handling: a non-full-day absence on a single day counts 0.5.
     */
    public static function workdays(Carbon $start, Carbon $end, bool $ganzerTag = true): float
    {
        $days = 0;
        $cur = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($cur->lte($last)) {
            $days++;
            $cur->addDay();
        }
        if (!$ganzerTag && $days === 1) return 0.5;
        return (float) $days;
    }
}
