<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class WorkerAppShiftController extends Controller
{
    public function index(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        // Determine week range
        try {
            $weekStart = $request->week_start ? Carbon::parse($request->week_start) : Carbon::now();
        } catch (\Exception $e) {
            $weekStart = Carbon::now();
        }
        $weekStart = $weekStart->startOfWeek(Carbon::MONDAY);
        $weekEnd   = $weekStart->copy()->addDays(6);

        // Build day labels
        $dayNames     = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $dayNamesLong = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $days = [];
        $cursor = $weekStart->copy();
        for ($i = 0; $i < 7; $i++) {
            $days[] = [
                'date'          => $cursor->toDateString(),
                'day_name'      => $dayNames[$i],
                'day_name_long' => $dayNamesLong[$i],
                'day_number'    => $cursor->day,
                'is_today'      => $cursor->isToday(),
            ];
            $cursor->addDay();
        }

        // Per-worker task column may be missing on a lagging tenant DB.
        $aufgabeCol = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')
            ? 'em.aufgabe AS worker_aufgabe'
            : 'NULL AS worker_aufgabe';

        // Query shifts
        $rawShifts = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.bestell_id, e.datum, e.kunden_id, e.obj_id, e.artikel_id, e.nu_id,
                   e.ist_start, e.ist_ende, e.ist_std_menge, e.ist_betrag, e.pause_minuten,
                   e.hms_bestaetigt, e.beschreibung, e.bemerkung, e.ort,
                   {$aufgabeCol},
                   k.kundenname,
                   o.objektname, o.obj_nr_intern,
                   n.firmenname AS nu_name,
                   b.eingang_id, b.zeit_von AS order_zeit_von, b.zeit_bis AS order_zeit_bis,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   d.soll_std AS plan_std, d.beschreibung AS plan_beschreibung
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN nachunternehmer n ON e.nu_id = n.nu_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE em.dienst_nr = ? AND e.datum BETWEEN ? AND ? AND e.pruefung = 'geplant'
            ORDER BY e.datum ASC, COALESCE(e.ist_start, d.zeit_von) ASC, e.id ASC
        ", [$dienstNr, $weekStart->toDateString(), $weekEnd->toDateString()]);

        $shifts = [];
        foreach ($rawShifts as $s) {
            $displayStart = $s->ist_start ?: ($s->plan_start ?: $s->order_zeit_von);
            $displayEnd   = $s->ist_ende  ?: ($s->plan_end   ?: $s->order_zeit_bis);
            $displayStd   = $s->ist_std_menge ?: $s->plan_std;
            // The worker sees THEIR OWN task (em.aufgabe). The internal Dispo
            // note (e.bemerkung) is intentionally never exposed to the app.
            $workerTask   = ($s->worker_aufgabe ?? null) ?: null;

            $pauseMin = 0;
            if ($s->pause_minuten !== null && intval($s->pause_minuten) >= 0) {
                $pauseMin = intval($s->pause_minuten);
            } elseif ($displayStart && $displayEnd && $displayStd !== null && $displayStd !== '') {
                $startMin = intval(substr($displayStart, 0, 2)) * 60 + intval(substr($displayStart, 3, 2));
                $endMin   = intval(substr($displayEnd, 0, 2)) * 60 + intval(substr($displayEnd, 3, 2));
                if ($endMin < $startMin) $endMin += 24 * 60;
                $totalMin  = $endMin - $startMin;
                $workedMin = intval(round(floatval($displayStd) * 60));
                $pauseMin  = max(0, min($totalMin - $workedMin, 120));
            }

            $shifts[] = [
                'einsatz_id'     => $s->einsatz_id,
                'bestell_id'     => $s->bestell_id,
                'datum'          => $s->datum,
                'display_start'  => $displayStart ? substr($displayStart, 0, 5) : null,
                'display_end'    => $displayEnd ? substr($displayEnd, 0, 5) : null,
                'display_std'    => $displayStd,
                'pause_minuten'  => $pauseMin,
                'kundenname'     => $s->kundenname,
                'objektname'     => $s->objektname,
                'obj_nr_intern'  => $s->obj_nr_intern,
                'nu_name'        => $s->nu_name,
                'aufgabe'        => $workerTask,
                'beschreibung'   => null,
                'ort'            => $s->ort ?: null,
                'bemerkung'      => null,
                'hms_bestaetigt' => $s->hms_bestaetigt,
            ];
        }

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end'   => $weekEnd->toDateString(),
            'kw'         => intval($weekStart->format('W')),
            'objektbuch_label' => \App\Support\TenantProfile::objektbuchLabel(\App\Services\TenantService::current()),
            'days'       => $days,
            'shifts'     => $shifts,
        ]);
    }

}
