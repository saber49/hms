<?php

namespace App\Support;

use App\Http\Controllers\Api\WorkerAppZeitController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [start, end] of a worker's shift as UTC instants (moved out of the old
 * WorkerAppWachbuchController; used to bind site photos to a shift).
 */
class ShiftWindow
{
    /**
     * Times follow the app's display logic (Einsatz times → plan day →
     * order); overnight shifts end the next day and an in-app extension
     * (zeiterfassung.verlaengert_bis) moves the end. Without any times the
     * whole calendar day counts. Null when the worker is not assigned.
     */
    public static function fuer(string $dienstNr, string $einsatzId): ?array
    {
        $s = DB::connection('tenant')->selectOne("
            SELECT e.datum, e.ist_start, e.ist_ende,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   b.zeit_von AS order_von, b.zeit_bis AS order_bis
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE em.dienst_nr = ? AND em.einsatz_id = ?
            LIMIT 1
        ", [$dienstNr, $einsatzId]);
        if (!$s || !$s->datum) return null;

        $datum = substr((string) $s->datum, 0, 10);
        $von = $s->ist_start ?: ($s->plan_start ?: $s->order_von);
        $bis = $s->ist_ende  ?: ($s->plan_end   ?: $s->order_bis);
        if (!$von || !$bis) {
            return [
                Carbon::parse($datum . ' 00:00:00', 'Europe/Berlin')->utc(),
                Carbon::parse($datum . ' 23:59:59', 'Europe/Berlin')->utc(),
            ];
        }
        $von = substr((string) $von, 0, 5);
        $bis = substr((string) $bis, 0, 5);

        $verlaengert = null;
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengert_bis')) {
            $verlaengert = DB::connection('tenant')->table('zeiterfassung')
                ->where('einsatz_id', $einsatzId)->where('dienst_nr', $dienstNr)
                ->max('verlaengert_bis');
        }
        $start = Carbon::parse($datum . ' ' . $von, 'Europe/Berlin')->utc();
        $end = WorkerAppZeitController::currentShiftEnd((object) [
            'datum' => $datum, 'ist_start' => $von, 'ist_ende' => $bis, 'verlaengert_bis' => $verlaengert,
        ]) ?: Carbon::parse($datum . ' 23:59:59', 'Europe/Berlin')->utc();
        return [$start, $end];
    }
}
