<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class WorkerAppFeedController extends Controller
{
    public function index(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $today = Carbon::today()->toDateString();

        // Per-worker task column may be missing on a lagging tenant DB.
        $aufgabeCol = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')
            ? 'em.aufgabe AS worker_aufgabe'
            : 'NULL AS worker_aufgabe';

        // 1. Today's shifts
        $todayRaw = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.bestell_id, e.datum,
                   e.ist_start, e.ist_ende, e.ist_std_menge, e.ist_betrag, e.pause_minuten,
                   e.beschreibung, e.bemerkung, e.ort, e.hms_bestaetigt,
                   {$aufgabeCol},
                   k.kundenname, o.objektname,
                   b.eingang_id, b.zeit_von AS order_zeit_von, b.zeit_bis AS order_zeit_bis,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   d.soll_std AS plan_std, d.beschreibung AS plan_beschreibung
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE em.dienst_nr = ? AND e.datum = ? AND e.pruefung = 'geplant'
            ORDER BY COALESCE(e.ist_start, d.zeit_von) ASC
        ", [$dienstNr, $today]);

        $todayShifts = [];
        foreach ($todayRaw as $s) {
            $displayStart = $s->ist_start ?: ($s->plan_start ?: $s->order_zeit_von);
            $displayEnd   = $s->ist_ende  ?: ($s->plan_end   ?: $s->order_zeit_bis);
            // Worker sees THEIR OWN task (em.aufgabe); the internal Dispo note
            // (e.bemerkung) is intentionally not exposed to the app.
            $workerTask   = ($s->worker_aufgabe ?? null) ?: null;

            $pauseMin = 0;
            $displayStd = $s->ist_std_menge ?: $s->plan_std;
            if ($s->pause_minuten !== null && intval($s->pause_minuten) >= 0) {
                $pauseMin = intval($s->pause_minuten);
            } elseif ($displayStart && $displayEnd && $displayStd) {
                $sMin = intval(substr($displayStart, 0, 2)) * 60 + intval(substr($displayStart, 3, 2));
                $eMin = intval(substr($displayEnd, 0, 2)) * 60 + intval(substr($displayEnd, 3, 2));
                if ($eMin < $sMin) $eMin += 24 * 60;
                $pauseMin = max(0, min(($eMin - $sMin) - intval(round(floatval($displayStd) * 60)), 120));
            }

            $todayShifts[] = [
                'einsatz_id'     => $s->einsatz_id,
                'datum'          => $s->datum,
                'display_start'  => $displayStart ? substr($displayStart, 0, 5) : null,
                'display_end'    => $displayEnd ? substr($displayEnd, 0, 5) : null,
                'pause_minuten'  => $pauseMin,
                'kundenname'     => $s->kundenname,
                'objektname'     => $s->objektname,
                'aufgabe'        => $workerTask,
                'beschreibung'   => null,
                'ort'            => $s->ort ?: null,
                'hms_bestaetigt' => $s->hms_bestaetigt,
            ];
        }

        // 2. Feed items — upcoming weeks summary
        $weeks = DB::connection('tenant')->select("
            SELECT MIN(e.datum) AS first_date, MAX(e.datum) AS last_date,
                   COUNT(*) AS shift_count,
                   WEEK(e.datum, 1) AS kw, YEAR(e.datum) AS jahr
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            WHERE em.dienst_nr = ? AND e.datum >= ? AND e.pruefung = 'geplant'
            GROUP BY YEAR(e.datum), WEEK(e.datum, 1)
            ORDER BY first_date ASC
            LIMIT 5
        ", [$dienstNr, $today]);

        $feedItems = [];
        foreach ($weeks as $w) {
            $firstDate = Carbon::parse($w->first_date);
            $monday = $firstDate->copy()->startOfWeek(Carbon::MONDAY);
            $sunday = $monday->copy()->addDays(6);

            $feedItems[] = [
                'title'      => "KW {$w->kw} — {$w->shift_count} Schicht" . ($w->shift_count > 1 ? 'en' : ''),
                'subtitle'   => $monday->format('d.m.') . ' - ' . $sunday->format('d.m.Y'),
                'date'       => $firstDate->format('d.m.'),
                // The app's openWeekPlan(item.week_start) needs this to jump to
                // THIS week in the plan tab — it was never sent, so every feed
                // item silently opened the current week instead.
                'week_start' => $monday->toDateString(),
            ];
        }

        // 3. Next upcoming shift
        $next = DB::connection('tenant')->selectOne("
            SELECT e.einsatz_id, e.datum, e.ist_start, e.ist_ende,
                   e.beschreibung, e.bemerkung, e.ort,
                   {$aufgabeCol},
                   k.kundenname, o.objektname,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end, d.beschreibung AS plan_beschreibung
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE em.dienst_nr = ? AND e.pruefung = 'geplant'
              AND (e.datum > ? OR (e.datum = ? AND COALESCE(e.ist_start, d.zeit_von) >= CURTIME()))
            ORDER BY e.datum ASC, COALESCE(e.ist_start, d.zeit_von) ASC
            LIMIT 1
        ", [$dienstNr, $today, $today]);

        $nextShift = null;
        if ($next) {
            $ns = $next->ist_start ?: $next->plan_start;
            $ne = $next->ist_ende  ?: $next->plan_end;
            $nextTask = ($next->worker_aufgabe ?? null) ?: null;
            $nextShift = [
                'einsatz_id'    => $next->einsatz_id,
                'datum'         => $next->datum,
                'display_start' => $ns ? substr($ns, 0, 5) : null,
                'display_end'   => $ne ? substr($ne, 0, 5) : null,
                'kundenname'    => $next->kundenname,
                'objektname'    => $next->objektname,
                'aufgabe'       => $nextTask,
                'beschreibung'  => null,
                'ort'           => $next->ort ?: null,
            ];
        }

        // Return current worker profile snapshot so the app can sync changes (e.g. photo)
        $currentWorker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $dienstNr)
            ->select('dienst_nr', 'vorname', 'name', 'bereich', 'foto')
            ->first();

        return response()->json([
            'today_shifts' => $todayShifts,
            'feed_items'   => $feedItems,
            'next_shift'   => $nextShift,
            'today'        => $today,
            'worker'       => $currentWorker,
        ]);
    }
}
