<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\GeoLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class WorkerAppZeitController extends Controller
{
    /** Hard cap: shifts running longer than this are auto-closed. */
    public const MAX_SHIFT_HOURS = 12;
    public const END_GRACE_MINUTES = 30;

    /** One tap on "Verlängern" pushes the shift end by this many minutes. */
    public const EXTEND_MINUTES = 30;
    // Ending up to this many minutes before the shift end counts to the end.
    // EARLY_END_GRACE_MIN removed: quarter-hour rounding replaced the 10-min early-end grace.
    /** Extending is only allowed this many minutes before the shift end (or after it). */
    public const EXTEND_WINDOW_MIN = 15;
    /** Every extension needs a written reason of at least this many characters. */
    public const EXTEND_REASON_MIN = 3;

    /** GPS fixes with a worse reported accuracy (metres) are rejected. */
    private const MAX_GPS_ACCURACY_M = 100;

    /**
     * A shift is startable from this many minutes before its planned start
     * until its effective end — independent of the calendar date, so a
     * night shift stays startable after midnight and a 00:00 shift dated
     * tomorrow is startable at 23:45.
     */
    public const CLOCK_IN_EARLY_MIN = 15;
    /**
     * The app counts minutes_until (rounded) down once a minute and unlocks
     * the button at 15 — up to 30 s before the exact 15:00 mark. The server
     * accepts that half minute so the unlocked button is never refused.
     */
    public const CLOCK_IN_TOLERANCE_SEC = 30;
    /** Tomorrow-dated shifts appear in the app this many minutes ahead. */
    public const UPCOMING_TOMORROW_MIN = 60;

    /**
     * GET — Current state + upcoming + completed shifts for today.
     */
    public function index(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $this->autoCloseStaleShifts($dienstNr);
        $now      = Carbon::now();
        $nowLocal = Carbon::now('Europe/Berlin');
        $today    = $nowLocal->toDateString();
        $yesterday = $nowLocal->copy()->subDay()->toDateString();
        $tomorrow = $nowLocal->copy()->addDay()->toDateString();

        $aufgabeCol = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'aufgabe')
            ? 'em.aufgabe AS worker_aufgabe'
            : 'NULL AS worker_aufgabe';

        // 1. Active clock-in
        $active = DB::connection('tenant')->selectOne("
            SELECT z.*, e.datum, e.obj_id, e.kunden_id,
                   k.kundenname, o.objektname,
                   e.beschreibung, e.bemerkung, e.ort,
                   e.ist_start, e.ist_ende,
                   COALESCE(e.pause_minuten, 0) AS plan_pause,
                   {$aufgabeCol},
                   b.zeit_von AS order_zeit_von, b.zeit_bis AS order_zeit_bis,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end
            FROM zeiterfassung z
            INNER JOIN einsaetze e ON z.einsatz_id = e.einsatz_id
            LEFT JOIN einsatz_mitarbeiter em ON em.einsatz_id = e.einsatz_id AND em.dienst_nr = z.dienst_nr
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
            WHERE z.dienst_nr = ? AND z.status IN ('aktiv', 'pause')
            ORDER BY z.clock_in DESC LIMIT 1
        ", [$dienstNr]);

        if ($active) {
            $clockIn = Carbon::parse($active->clock_in);
            // The app timer runs from the real tap (clock_in_real), so the
            // worker sees it start the moment he presses "Start". Paid time
            // still counts from clock_in (= planned start on an early tap);
            // counting_from/gestempelt tell the app to show both.
            $realIn = !empty($active->clock_in_real) ? Carbon::parse($active->clock_in_real) : $clockIn;
            $active->elapsed_seconds = max(0, $now->getTimestamp() - $realIn->getTimestamp());
            $early = $realIn->lt($clockIn);
            $active->counting_from = $early ? $clockIn->copy()->setTimezone('Europe/Berlin')->format('H:i') : null;
            $active->gestempelt = $early ? $realIn->copy()->setTimezone('Europe/Berlin')->format('H:i') : null;
            $activeStart = $active->ist_start ?: ($active->plan_start ?: $active->order_zeit_von);
            $activeEnd   = $active->ist_ende  ?: ($active->plan_end   ?: $active->order_zeit_bis);
            $active->display_start = $activeStart ? substr($activeStart, 0, 5) : null;
            $active->display_end   = $activeEnd ? substr($activeEnd, 0, 5) : null;
            // Extension state for the "Verlängern" button + "Endet um" label.
            $active->verlaengerungen = (int) ($active->verlaengerungen ?? 0);
            $active->extend_minutes  = self::EXTEND_MINUTES;
            $active->extend_window_min = self::EXTEND_WINDOW_MIN;
            $effEnd = self::currentShiftEnd((object) ['verlaengert_bis' => $active->verlaengert_bis ?? null,
                'datum' => $active->datum, 'ist_start' => $activeStart, 'ist_ende' => $activeEnd]);
            $active->display_end = $effEnd ? $effEnd->copy()->setTimezone('Europe/Berlin')->format('H:i') : $active->display_end;
            $active->minutes_to_end = $effEnd ? (int) round(($effEnd->getTimestamp() - $now->getTimestamp()) / 60) : null;
            // Exact remaining time: the app opens the extend sheet in the last minute.
            $active->seconds_to_end = $effEnd ? $effEnd->getTimestamp() - $now->getTimestamp() : null;
            $active->extend_reason_min = self::EXTEND_REASON_MIN;
            $active->can_extend = $active->minutes_to_end !== null && $active->minutes_to_end <= self::EXTEND_WINDOW_MIN;
            if (!$active->can_extend) {
                $active->display_end = null;
                $active->extend_minutes = null;
            }
            $active->display_note  = null;
            $active->beschreibung  = null;
            $active->bemerkung     = null;

        }

        // 2. Upcoming shifts (not yet clocked in)
        $upcomingRaw = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum, e.ist_start, e.ist_ende,
                   e.beschreibung, e.bemerkung, e.ort, e.kunden_id, e.obj_id,
                   k.kundenname, o.objektname,
                   {$aufgabeCol},
                   b.zeit_von AS order_zeit_von, b.zeit_bis AS order_zeit_bis,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   d.beschreibung AS plan_beschreibung
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
            WHERE em.dienst_nr = ? AND e.datum IN (?, ?, ?) AND e.pruefung = 'geplant'
              AND e.einsatz_id NOT IN (
                  SELECT z2.einsatz_id FROM zeiterfassung z2
                  WHERE z2.dienst_nr = ? AND z2.status IN ('aktiv', 'pause', 'beendet')
              )
            ORDER BY e.datum ASC, COALESCE(e.ist_start, d.zeit_von, b.zeit_von) ASC
        ", [$dienstNr, $yesterday, $today, $tomorrow, $dienstNr]);

        $upcoming = [];
        foreach ($upcomingRaw as $s) {
            $shiftStart = $s->ist_start ?: ($s->plan_start ?: $s->order_zeit_von);
            $shiftEnd   = $s->ist_ende  ?: ($s->plan_end   ?: $s->order_zeit_bis);
            $displayNote = null;

            // Same rule as clockIn(): a shift whose end already passed is
            // gone, the start window opens CLOCK_IN_EARLY_MIN before start.
            $win = (object) ['datum' => $s->datum, 'ist_start' => $shiftStart, 'ist_ende' => $shiftEnd];
            $state = self::clockInWindowState($win, $now);
            if ($state === 'over') continue;

            $start = self::plannedShiftStart($win);
            $minutesUntil = $start ? (int) round(($start->getTimestamp() - $now->getTimestamp()) / 60) : null;
            // Tomorrow's shifts only show up shortly before they start
            // (a 00:00 shift at 23:00), never the whole evening before.
            if ($s->datum === $tomorrow && ($minutesUntil === null || $minutesUntil > self::UPCOMING_TOMORROW_MIN)) continue;
            $canClockIn = $state === 'open';

            $upcoming[] = [
                'einsatz_id'    => $s->einsatz_id,
                'datum'         => $s->datum,
                'display_start' => $shiftStart ? substr($shiftStart, 0, 5) : null,
                'display_end'   => $shiftEnd ? substr($shiftEnd, 0, 5) : null,
                'kundenname'    => $s->kundenname,
                'objektname'    => $s->objektname,
                'ort'           => $s->ort ?: null,
                'beschreibung'  => $displayNote,
                'can_clock_in'  => $canClockIn,
                'minutes_until' => $minutesUntil,

            ];
        }

        // 3. Today's completed — today's shifts plus yesterday's night shift
        // that ended this morning (it is dated yesterday).
        $completedRaw = DB::connection('tenant')->select("
            SELECT z.einsatz_id, z.clock_in, z.clock_out, z.pause_total, z.status,
                   e.datum, o.objektname, k.kundenname
            FROM zeiterfassung z
            INNER JOIN einsaetze e ON z.einsatz_id = e.einsatz_id
            LEFT JOIN kunden k ON e.kunden_id = k.kunde_id
            LEFT JOIN objekte o ON e.obj_id = o.obj_id
            WHERE z.dienst_nr = ? AND z.status = 'beendet'
              AND (e.datum = ? OR (e.datum = ? AND z.clock_out >= ?))
            ORDER BY z.clock_in ASC
        ", [$dienstNr, $today, $yesterday, $nowLocal->copy()->startOfDay()->setTimezone('UTC')->toDateTimeString()]);

        $completed = [];
        foreach ($completedRaw as $c) {
            // Stored in UTC; convert to local (Europe/Berlin) for display so the
            // times match what the worker actually saw on the clock.
            $cin  = Carbon::parse($c->clock_in)->setTimezone('Europe/Berlin');
            $cout = Carbon::parse($c->clock_out)->setTimezone('Europe/Berlin');
            $totalSec = $cin->diffInSeconds($cout);
            $workSec  = $totalSec - ($c->pause_total * 60);

            $completed[] = array_merge((array)$c, [
                'worked_minutes'  => max(0, round($workSec / 60)),
                'clock_in_time'   => $cin->format('H:i'),
                'clock_out_time'  => $cout->format('H:i'),
            ]);
        }

        return response()->json([
            'active'      => $active ?: null,
            'upcoming'    => $upcoming,
            'completed'   => $completed,
            'server_time' => $now->toDateTimeString(),
        ]);
    }

    /**
     * Clock in. Every answer (and every crash) lands in logs/clockin.log so
     * a failing check-in is visible within minutes, not hours.
     */
    public function clockIn(Request $request)
    {
        return $this->logged('clock-in', $request, fn () => $this->handleClockIn($request));
    }

    private function handleClockIn(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $einsatzId = $request->input('einsatz_id');
        $geo       = $request->input('geo');
        $now       = Carbon::now();

        if (!$einsatzId) {
            return response()->json(['error' => 'einsatz_id erforderlich'], 400);
        }

        // Forgotten shifts must not block today's clock-in.
        $this->autoCloseStaleShifts($dienstNr);

        // Verify assignment. The calendar date only pre-filters (yesterday's
        // night shift running past midnight, tomorrow's 00:00 shift); the
        // time window below decides whether the shift is startable now.
        $berlinNow = Carbon::now('Europe/Berlin');
        $check = DB::connection('tenant')->selectOne("
            SELECT em.id, e.datum, e.ist_start, e.ist_ende, e.obj_id FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            WHERE em.einsatz_id = ? AND em.dienst_nr = ? AND e.pruefung = 'geplant'
              AND e.datum IN (?, ?, ?)
        ", [$einsatzId, $dienstNr, $berlinNow->copy()->subDay()->toDateString(),
            $berlinNow->toDateString(), $berlinNow->copy()->addDay()->toDateString()]);

        if (!$check) {
            return response()->json(['error' => 'Nicht zugewiesen, nicht geplant oder nicht für heute'], 403);
        }

        // A shift whose end already passed is over — a late "Start" must not
        // silently turn it into a night job. Dispo adjusts the times or
        // creates a new shift when the work is real.
        $state = self::clockInWindowState($check, $now);
        if ($state === 'over') {
            return response()->json([
                'error' => 'Diese Schicht ist bereits vorbei (Ende ' .
                    self::currentShiftEnd($check)->copy()->setTimezone('Europe/Berlin')->format('H:i') .
                    ' Uhr). Bitte wende dich an die Disposition.',
            ], 409);
        }
        if ($state === 'too_early') {
            $start = self::plannedShiftStart($check);
            return response()->json([
                'error' => 'Diese Schicht kann erst ab ' .
                    ($start ? $start->copy()->subMinutes(self::CLOCK_IN_EARLY_MIN)->setTimezone('Europe/Berlin')->format('H:i') . ' Uhr' : 'Schichtbeginn') .
                    ' gestartet werden (' . self::CLOCK_IN_EARLY_MIN . ' Minuten vor Beginn).',
            ], 409);
        }

        // Cheap pre-checks (the authoritative ones run again under the lock).
        $conflict = $this->clockInConflict($einsatzId, $dienstNr);
        if ($conflict) return $conflict;

        // QM: the current Dienstanweisung of this Objekt must be confirmed first.
        // Only clients that can show it (qm_client=1: current worker app,
        // Objekt-Terminal) are blocked — older installed native/desktop apps
        // would otherwise be stuck without a way to read it; for them the
        // missing confirmation is logged instead.
        $daSperre = $check->obj_id ? \App\Services\Qm\DienstanweisungService::sperre($dienstNr, (string) $check->obj_id) : null;
        if ($daSperre && ($request->boolean('qm_client') || $request->attributes->get('qm_terminal_obj'))) {
            return response()->json([
                'error' => 'Check-in noch nicht möglich: Bitte zuerst die Dienstanweisung lesen und bestätigen.',
                'da_sperre' => ['id' => (int) $daSperre->id, 'obj_id' => $daSperre->obj_id, 'version' => (int) $daSperre->version],
            ], 409);
        }
        if ($daSperre) {
            \App\Support\Qm\Qm::log('dienstanweisung', $daSperre->id, 'checkin_ohne_bestaetigung', null,
                ['dienst_nr' => $dienstNr, 'einsatz_id' => $einsatzId], null, 'App ohne Dienstanweisung-Funktion');
        }

        // Strict, always-on location verification: a worker can only clock in
        // when their GPS position is confirmed within the object's radius.
        // On the Objekt-Terminal of this very Objekt the device is on site.
        $amTerminal = $request->attributes->get('qm_terminal_obj') && $request->attributes->get('qm_terminal_obj') === (string) $check->obj_id;
        if (!$amTerminal) {
            $geoError = $this->verifyGeoAgainstEinsatz($einsatzId, $geo, $request->input('geo_accuracy'));
            if ($geoError) return $geoError;
        }

        // Early start counts from the planned start: a worker tapping "Start"
        // at 06:45 for a 07:00 shift is recorded (and paid) from 07:00. A late
        // start stays real. The raw tap is kept in clock_in_real.
        $countedIn = self::countedClockIn($check->datum, $check->ist_start, $now);

        $insert = [
            'einsatz_id' => $einsatzId,
            'dienst_nr'  => $dienstNr,
            'clock_in'   => $countedIn,
            'status'     => 'aktiv',
            'geo_in'     => $geo,
        ];
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_in_real')) {
            $insert['clock_in_real'] = $now;
        }

        // Double-tap / retry on a slow network: two requests could both pass
        // the checks above and insert two records. The worker row lock
        // serialises clock-ins per worker; the unique index
        // (uq_zeit_einsatz_dienst) is the last line of defence.
        try {
            $conflict = DB::connection('tenant')->transaction(function () use ($einsatzId, $dienstNr, $insert, $countedIn) {
                DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $dienstNr)->lockForUpdate()->first();
                if ($conflict = $this->clockInConflict($einsatzId, $dienstNr)) return $conflict;

                DB::connection('tenant')->table('zeiterfassung')->insert($insert);

                // Keep the Einsatz-level start/end as the official Bestelleingang plan;
                // the worker's real start lives in zeiterfassung and einsatz_mitarbeiter.

                // Stamp the worker's own start on einsatz_mitarbeiter too, so the
                // Aktive-Mitarbeiter panel marks it live (●) with the plan line while
                // the shift is still running — clock-out fills ist_ende/ist_std.
                // whereNull keeps a dispatcher-set ist_start untouched.
                if (Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'ist_start')) {
                    DB::connection('tenant')->table('einsatz_mitarbeiter')
                        ->where('einsatz_id', $einsatzId)
                        ->where('dienst_nr', $dienstNr)
                        ->whereNull('ist_start')
                        ->update(['ist_start' => $countedIn->copy()->setTimezone('Europe/Berlin')->format('H:i')]);
                }
                return null;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $conflict = response()->json(['error' => 'Bereits für diesen Einsatz gestempelt'], 409);
        }
        if ($conflict) return $conflict;

        \App\Services\Qm\WachbuchService::nachCheckIn($einsatzId, $dienstNr, $countedIn, $now, $amTerminal ? 'terminal' : 'app');

        \App\Helpers\Realtime::changed('workers');
        \App\Helpers\Realtime::changed('dispo');
        return response()->json([
            'success'       => true,
            'clock_in'      => $countedIn->toDateTimeString(),
            'clock_in_real' => $now->toDateTimeString(),
            'counting_from' => $countedIn->gt($now) ? $countedIn->copy()->setTimezone('Europe/Berlin')->format('H:i') : null,
        ]);
    }

    /** 409 when the worker already runs a shift or already clocked this one. */
    private function clockInConflict(string $einsatzId, string $dienstNr)
    {
        $active = DB::connection('tenant')->selectOne("SELECT id FROM zeiterfassung WHERE dienst_nr = ? AND status IN ('aktiv', 'pause')", [$dienstNr]);
        if ($active) return response()->json(['error' => 'Sie haben bereits eine aktive Schicht'], 409);
        $dup = DB::connection('tenant')->selectOne("SELECT id FROM zeiterfassung WHERE einsatz_id = ? AND dienst_nr = ?", [$einsatzId, $dienstNr]);
        if ($dup) return response()->json(['error' => 'Bereits für diesen Einsatz gestempelt'], 409);
        return null;
    }

    /**
     * Counted start of a shift (UTC): the real tap rounded to the nearest
     * quarter hour (±7:30 min wins, an exact tie rounds up: 08:08 → 08:15,
     * 08:52 → 08:45, 08:53 → 09:00) — but never before the planned start
     * (einsaetze.datum + ist_start, Europe/Berlin — the official
     * Bestelleingang plan): an early tap still counts from the plan, not
     * from an earlier rounded quarter. The raw tap lives in clock_in_real.
     */
    public static function countedClockIn(?string $datum, ?string $planStart, Carbon $tap): Carbon
    {
        $rounded = Carbon::createFromTimestampUTC((int) round($tap->getTimestamp() / 900) * 900);
        if (empty($datum) || empty($planStart)) return $rounded;
        $planned = Carbon::parse(substr((string) $datum, 0, 10) . ' ' . substr((string) $planStart, 0, 5), 'Europe/Berlin')
            ->setTimezone('UTC');
        return $planned->gt($rounded) ? $planned : $rounded;
    }

    /**
     * Counted end of a shift (UTC): the real tap rounded to the nearest
     * quarter hour (tie rounds up: 19:38 → 19:45, 19:53 → 20:00), never
     * beyond the effective end (planned end or last extension) and never
     * before the counted start. Without a planned end the rounded tap
     * counts. The raw tap lives in clock_out_real ("beendet HH:MM").
     * $round = false gives cap-only (no rounding): real-time consumers
     * like the running pause must not inflate to a quarter boundary.
     */
    public static function countedClockOut(object $record, Carbon $tap, bool $round = true): Carbon
    {
        $out = $round
            ? Carbon::createFromTimestampUTC((int) round($tap->getTimestamp() / 900) * 900)
            : $tap->copy();
        $end = self::currentShiftEnd($record);
        if ($end && $out->gt($end)) $out = $end->copy();
        $clockIn = Carbon::parse($record->clock_in);
        return $out->lt($clockIn) ? $clockIn : $out;
    }

    /**
     * Clock out.
     */
    public function clockOut(Request $request)
    {
        return $this->logged('clock-out', $request, fn () => $this->handleClockOut($request));
    }

    private function handleClockOut(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $geo      = $request->input('geo');
        $notiz    = $request->input('notiz');
        $now      = Carbon::now();

        // A forgotten shift may already be past the hard cap — close it
        // properly instead of validating a clock-out hours later.
        $this->autoCloseStaleShifts($dienstNr);

        $record = DB::connection('tenant')->selectOne("
            SELECT z.*, e.datum, e.ist_start, e.ist_ende
            FROM zeiterfassung z INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
            WHERE z.dienst_nr = ? AND z.status IN ('aktiv', 'pause')
            ORDER BY z.clock_in DESC LIMIT 1", [$dienstNr]);
        if (!$record) {
            return response()->json(['error' => 'Keine aktive Schicht gefunden'], 404);
        }

        // Same strict location verification as clock-in: the worker must be
        // on site (within the object's radius) to end the shift.
        $terminalObj = $request->attributes->get('qm_terminal_obj');
        $amTerminal = $terminalObj && $terminalObj === (string) DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $record->einsatz_id)->value('obj_id');
        if (!$amTerminal) {
            $geoError = $this->verifyGeoAgainstEinsatz($record->einsatz_id, $geo, $request->input('geo_accuracy'));
            if ($geoError) return $geoError;
        }

        // Counted end: the tap rounded to the nearest quarter hour, capped
        // at the planned (or extended) end — never beyond it, never before
        // the counted start. The tap is kept in clock_out_real ("beendet HH:MM").
        $clockIn  = Carbon::parse($record->clock_in);
        $countedOut = self::countedClockOut($record, $now);

        $pauseTotal = (int) $record->pause_total;
        if ($record->status === 'pause' && !empty($record->pause_start)) {
            $pauseStart = Carbon::parse($record->pause_start);
            // A running pause ends at the real tap (capped at the shift end).
            $pauseUntil = $countedOut->lt($now) ? $countedOut : $now;
            if ($pauseStart->lt($pauseUntil)) {
                $pauseTotal += max(0, (int) round($pauseStart->diffInSeconds($pauseUntil) / 60));
            }
        }

        $clockOutUpdate = [
            'clock_out'   => $countedOut,
            'status'      => 'beendet',
            'geo_out'     => $geo,
            'notiz'       => $notiz,
            'pause_total' => $pauseTotal,
        ];
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')) {
            $clockOutUpdate['pause_start'] = null;
        }
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'clock_out_real')) {
            $clockOutUpdate['clock_out_real'] = $now;
        }
        DB::connection('tenant')->table('zeiterfassung')->where('id', $record->id)->update($clockOutUpdate);

        $workedHours = self::writeWorkerActuals($record->einsatz_id, $dienstNr, $clockIn, $countedOut, $pauseTotal);

        \App\Services\Qm\WachbuchService::nachCheckOut($record->einsatz_id, $dienstNr, $now, $request->input('uebergabe'), $amTerminal ? 'terminal' : 'app');

        \App\Helpers\Realtime::changed('workers');
        \App\Helpers\Realtime::changed('dispo');
        return response()->json([
            'success'        => true,
            'clock_out'      => $countedOut->toDateTimeString(),
            'worked_hours'   => $workedHours,
            'pause_minutes'  => $pauseTotal,
        ]);
    }

    /**
     * Extend the running shift by EXTEND_MINUTES from its current end
     * (planned end or previous extension). Called from the "Verlängern"
     * button the end-of-shift notification offers; the notifier then waits
     * until the new end before asking again.
     */
    public function extend(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        if (!Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengert_bis')) {
            return response()->json(['error' => 'Verlängerung nicht verfügbar'], 409);
        }

        // A written reason is mandatory. Old app builds send none → they are
        // asked to update instead of extending silently.
        $grund = trim((string) $request->input('grund', ''));
        if (mb_strlen($grund) < self::EXTEND_REASON_MIN) {
            return response()->json([
                'error' => $request->has('grund')
                    ? 'Bitte gib einen Grund für die Verlängerung an.'
                    : 'Bitte aktualisiere die App — für die Verlängerung ist jetzt eine Begründung nötig.',
            ], 422);
        }
        $grund = mb_substr($grund, 0, 500);

        $record = DB::connection('tenant')->selectOne("
            SELECT z.*, e.datum, e.ist_start, e.ist_ende
            FROM zeiterfassung z INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
            WHERE z.dienst_nr = ? AND z.status IN ('aktiv', 'pause')
            ORDER BY z.clock_in DESC LIMIT 1", [$dienstNr]);
        if (!$record) {
            return response()->json(['error' => 'Keine aktive Schicht gefunden'], 404);
        }

        $currentEnd = self::currentShiftEnd($record);
        if (!$currentEnd) {
            return response()->json(['error' => 'Schicht hat keine geplante Endzeit'], 409);
        }
        // Only near / after the end: an extension in the middle of the shift
        // is meaningless (and the app hides the button outside this window).
        $minutesLeft = (int) ceil(Carbon::now()->diffInSeconds($currentEnd, false) / 60);
        if ($minutesLeft > self::EXTEND_WINDOW_MIN) {
            return response()->json([
                'error' => 'Verlängern ist erst ' . self::EXTEND_WINDOW_MIN . ' Minuten vor Schichtende möglich (Ende '
                    . $currentEnd->copy()->setTimezone('Europe/Berlin')->format('H:i') . ')',
            ], 409);
        }
        // Always from the current (planned / extended) end — a late tap never
        // turns the unextended overrun into counted time.
        $newEnd = $currentEnd->copy()->addMinutes(self::EXTEND_MINUTES);

        $count = (int) ($record->verlaengerungen ?? 0) + 1;
        $update = ['verlaengert_bis' => $newEnd, 'verlaengerungen' => $count];
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'verlaengerung_gruende')) {
            $gruende = json_decode((string) ($record->verlaengerung_gruende ?? ''), true);
            $gruende = is_array($gruende) ? $gruende : [];
            $gruende[] = ['am' => Carbon::now()->toDateTimeString(), 'bis' => $newEnd->toDateTimeString(), 'grund' => $grund];
            $update['verlaengerung_gruende'] = json_encode($gruende, JSON_UNESCAPED_UNICODE);
        }
        DB::connection('tenant')->table('zeiterfassung')->where('id', $record->id)->update($update);

        \App\Helpers\Realtime::changed('dispo');
        return response()->json([
            'success'         => true,
            'verlaengert_bis' => $newEnd->toDateTimeString(),
            'display_end'     => $newEnd->copy()->setTimezone('Europe/Berlin')->format('H:i'),
            'verlaengerungen' => $count,
        ]);
    }

    /**
     * Effective end of a running shift as a UTC instant: the later of the
     * official plan and the last extension. Overnight plans move to the next
     * calendar day.
     */
    public static function currentShiftEnd(object $record): ?Carbon
    {
        $plannedEnd = null;
        if (!empty($record->ist_ende) && !empty($record->datum)) {
            $plannedEnd = Carbon::parse(substr((string) $record->datum, 0, 10) . ' ' . substr((string) $record->ist_ende, 0, 5), 'Europe/Berlin');
            // End at or before the start = next day (night shift; equal
            // times = 24-h shift — same rule as SchichtartCalculator).
            if (!empty($record->ist_start) && substr((string) $record->ist_ende, 0, 5) <= substr((string) $record->ist_start, 0, 5)) {
                $plannedEnd->addDay();
            }
            $plannedEnd->setTimezone('UTC');
        }

        $extendedEnd = !empty($record->verlaengert_bis) ? Carbon::parse($record->verlaengert_bis) : null;
        if ($plannedEnd && $extendedEnd) return $extendedEnd->gt($plannedEnd) ? $extendedEnd : $plannedEnd;
        return $extendedEnd ?: $plannedEnd;
    }

    /** Planned start of a shift as a UTC instant (Europe/Berlin wall clock), null without a start time. */
    public static function plannedShiftStart(object $record): ?Carbon
    {
        if (empty($record->datum) || empty($record->ist_start)) return null;
        return Carbon::parse(substr((string) $record->datum, 0, 10) . ' ' . substr((string) $record->ist_start, 0, 5), 'Europe/Berlin')
            ->setTimezone('UTC');
    }

    /**
     * The single "can this shift be started now?" rule shared by index()
     * and clockIn(): 'over' once the effective end passed, 'too_early'
     * before CLOCK_IN_EARLY_MIN ahead of the planned start, else 'open'.
     * A shift without a start time is open on its own day (or while it
     * still runs), never ahead of its date.
     */
    public static function clockInWindowState(object $record, Carbon $now): string
    {
        $end = self::currentShiftEnd($record);
        if ($end && $now->gt($end)) return 'over';
        $start = self::plannedShiftStart($record);
        if ($start) {
            return $start->getTimestamp() - $now->getTimestamp() > self::CLOCK_IN_EARLY_MIN * 60 + self::CLOCK_IN_TOLERANCE_SEC
                ? 'too_early' : 'open';
        }
        $today = $now->copy()->setTimezone('Europe/Berlin')->toDateString();
        return substr((string) ($record->datum ?? ''), 0, 10) > $today ? 'too_early' : 'open';
    }

    /**
     * Run a clock action and append one JSON line per answer to
     * storage/logs/clockin.log (status, error text, worker, shift, app
     * build). Logging can never break the action itself.
     */
    private function logged(string $action, Request $request, \Closure $handler)
    {
        try {
            $response = $handler();
        } catch (\Throwable $e) {
            $this->writeClockLog($action, $request, 500, get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300));
            throw $e;
        }
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        $error = null;
        if ($status >= 400 && method_exists($response, 'getContent')) {
            $error = (json_decode((string) $response->getContent(), true) ?: [])['error'] ?? null;
        }
        $this->writeClockLog($action, $request, $status, $error);
        return $response;
    }

    private function writeClockLog(string $action, Request $request, int $status, ?string $error): void
    {
        try {
            $line = json_encode([
                'at'         => Carbon::now()->toDateTimeString(),
                'action'     => $action,
                'db'         => DB::connection('tenant')->getDatabaseName(),
                'dienst_nr'  => $request->attributes->get('worker_dienst_nr'),
                'einsatz_id' => $request->input('einsatz_id'),
                'status'     => $status,
                'error'      => $error,
                'app'        => $request->header('X-App-Version'),
                'ua'         => mb_substr((string) $request->userAgent(), 0, 120),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            @file_put_contents(storage_path('logs/clockin.log'), $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // never let logging affect a clock action
        }
    }

    /**
     * Persist THIS worker's actual times on einsatz_mitarbeiter so the
     * Monatsabschluss (COALESCE(em.ist_start, e.ist_start) …) bills exactly
     * what the worker clocked — not the shift-level values shared by the
     * whole team. The pause deducted is always the planned pause of the
     * shift (the app's own pause only when the shift plans none), so a
     * skipped or longer break never changes the paid hours.
     */
    public static function writeWorkerActuals(string $einsatzId, string $dienstNr, Carbon $clockIn, Carbon $clockOut, int $appPauseMin): float
    {
        $planned = DB::connection('tenant')->table('einsaetze')
            ->where('einsatz_id', $einsatzId)
            ->select('pause_minuten', 'gesetzl_pause')
            ->first();
        $plannedPause = (int) ($planned->pause_minuten ?? 0);
        if ($clockOut->lt($clockIn)) $clockOut = $clockIn->copy();

        $totalSec    = $clockOut->getTimestamp() - $clockIn->getTimestamp();
        // No working time → no pause to deduct (keeps ist_start = ist_ende, 0 h).
        $pauseMin    = $totalSec > 0 ? self::deductedPause($appPauseMin, $plannedPause) : 0;
        $workedHours = round(max(0, $totalSec - $pauseMin * 60) / 3600, 2);

        $update = [];
        $emCols = ['ist_start', 'ist_ende', 'pause_minuten', 'ist_std'];
        foreach ($emCols as $c) {
            if (!Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', $c)) return $workedHours;
        }
        $update['ist_start']     = $clockIn->copy()->setTimezone('Europe/Berlin')->format('H:i');
        $update['ist_ende']      = $clockOut->copy()->setTimezone('Europe/Berlin')->format('H:i');
        $update['pause_minuten'] = $pauseMin;
        $update['ist_std']       = $workedHours;

        // A late clock write on a row the office already approved revokes
        // the Freigabe — only approved values may reach the Leistungsnachweis.
        $hasKorr = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_korrektur');
        $emRow = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe')
            ? DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $einsatzId)->where('dienst_nr', $dienstNr)
                ->first(array_merge(['ist_start', 'ist_ende', 'pause_minuten', 'ist_std', 'zeit_freigabe'], $hasKorr ? ['zeit_korrektur'] : []))
            : null;

        // Office corrections (Korrekturbuchung) win over the app: fields the
        // office set by hand stay; the rest comes from the clock. Hours are
        // re-derived from the merged times unless the office typed them.
        $korr = $emRow && $hasKorr ? (json_decode((string) ($emRow->zeit_korrektur ?? ''), true) ?: []) : [];
        if ($korr) {
            foreach (['ist_start', 'ist_ende', 'pause_minuten'] as $f) {
                if (in_array($f, $korr, true) && $emRow->{$f} !== null && $emRow->{$f} !== '') $update[$f] = $emRow->{$f};
            }
            if (in_array('ist_std', $korr, true) && $emRow->ist_std !== null) {
                $update['ist_std'] = (float) $emRow->ist_std;
            } elseif (array_intersect(['ist_start', 'ist_ende', 'pause_minuten'], $korr)) {
                $datum = (string) DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->value('datum');
                $span = \App\Support\ShiftSpan::minutes($datum, (string) $update['ist_start'], (string) $update['ist_ende']);
                $update['ist_std'] = round(max(0, $span - (int) $update['pause_minuten']) / 60, 2);
            }
            $workedHours = (float) $update['ist_std'];
        }
        if ($emRow && (int) ($emRow->zeit_freigabe ?? 0) === 1) {
            $differs = substr((string) $update['ist_start'], 0, 5) !== substr((string) ($emRow->ist_start ?? ''), 0, 5)
                || substr((string) $update['ist_ende'], 0, 5) !== substr((string) ($emRow->ist_ende ?? ''), 0, 5)
                || (int) $update['pause_minuten'] !== (int) ($emRow->pause_minuten ?? 0)
                || round((float) $update['ist_std'], 2) !== round((float) ($emRow->ist_std ?? 0), 2);
            if ($differs) {
                $update['zeit_freigabe'] = 0;
                $update['zeit_freigabe_am'] = null;
            }
        }

        DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatzId)
            ->where('dienst_nr', $dienstNr)
            ->update($update);

        return $workedHours;
    }

    /** Pause deducted from paid hours: the planned pause; the app pause only without a plan. */
    public static function deductedPause(int $appPauseMin, int $plannedPauseMin): int
    {
        return $plannedPauseMin > 0 ? $plannedPauseMin : max(0, $appPauseMin);
    }

    /**
     * Re-attach a finished clock record to an assignment row that lost (or
     * never received) its actual hours — e.g. the einsatz_mitarbeiter row
     * was re-created AFTER the worker had already clocked out, or a later
     * planning save wiped ist_std to NULL.
     *
     * Only fills rows whose ist_std is currently empty: hours the planner
     * typed by hand or an intact clock write are never overwritten.
     * Returns true when actuals were restored.
     */
    public static function backfillWorkerActuals(string $einsatzId, string $dienstNr): bool
    {
        $needsFill = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatzId)
            ->where('dienst_nr', $dienstNr)
            ->whereNull('ist_std')
            ->exists();
        if (!$needsFill) return false;

        $z = DB::connection('tenant')->table('zeiterfassung')
            ->where('einsatz_id', $einsatzId)
            ->where('dienst_nr', $dienstNr)
            ->where('status', 'beendet')
            ->whereNotNull('clock_in')
            ->whereNotNull('clock_out')
            ->orderByDesc('clock_in')
            ->first();
        if (!$z) return false;

        self::writeWorkerActuals(
            $einsatzId,
            $dienstNr,
            Carbon::parse($z->clock_in),
            Carbon::parse($z->clock_out),
            (int) ($z->pause_total ?? 0)
        );

        return true;
    }

    /**
     * When the PLANNED pause of an einsatz changes after workers already
     * clocked out, rows whose stored deduction mirrored the old plan value
     * are re-derived against the new pause (pause_minuten +, when the
     * stored ist_std provably came from the old deduction, ist_std).
     * Rows that carry a worker-recorded (different) pause or a manually
     * typed hour value are left untouched.
     */
    public static function reconcilePauseAfterPlanChange(string $einsatzId, ?int $oldPause, ?int $newPause): void
    {
        $oldPause = (int) ($oldPause ?? 0);
        $newPause = (int) ($newPause ?? 0);
        if ($oldPause === $newPause) return;

        $rows = DB::connection('tenant')->table('einsatz_mitarbeiter')
            ->where('einsatz_id', $einsatzId)
            ->where(function ($q) use ($oldPause) {
                $q->whereNull('pause_minuten')->orWhere('pause_minuten', $oldPause);
            })
            ->get();

        $datum = (string) DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $einsatzId)->value('datum');
        foreach ($rows as $r) {
            // A pause / hours the office corrected by hand stay untouched.
            $korr = json_decode((string) ($r->zeit_korrektur ?? ''), true) ?: [];
            if (array_intersect(['pause_minuten', 'ist_std'], $korr)) continue;
            $upd = ['pause_minuten' => $newPause];
            if (!empty($r->ist_start) && !empty($r->ist_ende)) {
                $spanMin = \App\Support\ShiftSpan::minutes($datum, (string) $r->ist_start, (string) $r->ist_ende);
                $derivedOld = round(max(0, $spanMin - $oldPause) / 60, 2);
                // Only rows whose stored hours equal "span − old pause"
                // were plan-derived; a hand-typed correction stays.
                if ($r->ist_std === null || abs(((float) $r->ist_std) - $derivedOld) <= 0.011) {
                    $upd['ist_std'] = round(max(0, $spanMin - $newPause) / 60, 2);
                }
            }
            // Pause/hours changed on an approved row → Freigabe zurücknehmen.
            if ((int) ($r->zeit_freigabe ?? 0) === 1
                && (array_key_exists('ist_std', $upd) || (int) ($r->pause_minuten ?? 0) !== $newPause)) {
                $upd['zeit_freigabe'] = 0;
                $upd['zeit_freigabe_am'] = null;
            }
            DB::connection('tenant')->table('einsatz_mitarbeiter')
                ->where('einsatz_id', $einsatzId)
                ->where('dienst_nr', $r->dienst_nr)
                ->update($upd);
        }
    }

    /**
     * Pause start.
     */
    public function pauseStart(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $record = DB::connection('tenant')->selectOne("
            SELECT z.id, z.pause_total, z.clock_in, z.verlaengert_bis,
                   e.datum, e.ist_start, e.ist_ende,
                   COALESCE(e.pause_minuten, 0) AS plan_pause
            FROM zeiterfassung z
            INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
            WHERE z.dienst_nr = ? AND z.status = 'aktiv'
            ORDER BY z.clock_in DESC LIMIT 1", [$dienstNr]);
        if (!$record) {
            return response()->json(['error' => 'Keine aktive Schicht'], 404);
        }

        // Before the counted (planned) start there is no working time to pause.
        $countedIn = Carbon::parse($record->clock_in);
        if (Carbon::now()->lt($countedIn)) {
            return response()->json([
                'error' => 'Deine Arbeitszeit zählt erst ab ' . $countedIn->copy()->setTimezone('Europe/Berlin')->format('H:i') . ' Uhr.',
            ], 409);
        }
        // After the (extended) end nothing counts any more — no pause either.
        $end = self::currentShiftEnd($record);
        if ($end && Carbon::now()->gte($end)) {
            return response()->json([
                'error' => 'Deine Schicht ist um ' . $end->copy()->setTimezone('Europe/Berlin')->format('H:i') . ' Uhr zu Ende — bitte beenden oder verlängern.',
            ], 409);
        }

        // The planned pause is a per-shift budget shared across all pause
        // segments. Once pause_total reaches it, further pauses are rejected.
        $planned = (int) $record->plan_pause;
        if ($planned > 0 && (int) $record->pause_total >= $planned) {
            return response()->json([
                'error' => "Deine geplante Pause ({$planned} Min) ist bereits aufgebraucht.",
            ], 409);
        }

        $update = ['status' => 'pause'];
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')) {
            $update['pause_start'] = Carbon::now();
        }
        DB::connection('tenant')->table('zeiterfassung')->where('id', $record->id)->update($update);

        return response()->json(['success' => true, 'pause_start' => Carbon::now()->toDateTimeString()]);
    }

    /**
     * Pause end.
     */
    public function pauseEnd(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $record = DB::connection('tenant')->selectOne("
            SELECT z.*, e.datum, e.ist_start, e.ist_ende
            FROM zeiterfassung z INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
            WHERE z.dienst_nr = ? AND z.status = 'pause' ORDER BY z.clock_in DESC LIMIT 1", [$dienstNr]);
        if (!$record) {
            return response()->json(['error' => 'Keine pausierte Schicht'], 404);
        }

        // A pause running past the (extended) end only counts up to that end.
        $pauseMinutes = 0;
        if (!empty($record->pause_start)) {
            $pauseStart = Carbon::parse($record->pause_start);
            // Cap only — rounding must not lengthen a real pause.
            $until = self::countedClockOut($record, Carbon::now(), false);
            if ($pauseStart->lt($until)) $pauseMinutes = max(0, (int) round($pauseStart->diffInSeconds($until) / 60));
        }

        $newPauseTotal = (int) $record->pause_total + $pauseMinutes;
        $update = [
            'status'      => 'aktiv',
            'pause_total' => $newPauseTotal,
        ];
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')) {
            $update['pause_start'] = null;
        }
        DB::connection('tenant')->table('zeiterfassung')->where('id', $record->id)->update($update);

        return response()->json(['success' => true, 'pause_total' => $newPauseTotal, 'pause_added' => $pauseMinutes]);
    }

    /**
     * GET — Day-by-day time sheet for a date range (Zeiten view).
     * Returns one row per day that has at least one shift: planned minutes,
     * actually worked minutes and whether the admin has confirmed
     * (zeit_freigabe) ALL of that day's shifts in the Monatsabschluss.
     */
    public function zeiten(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $isoRe = '/^\d{4}-\d{2}-\d{2}$/';
        $von = trim((string) $request->query('von', ''));
        $bis = trim((string) $request->query('bis', ''));
        if (!preg_match($isoRe, $von) || !preg_match($isoRe, $bis)) {
            // Default: 1st of the current month → today (local).
            $nowLocal = Carbon::now('Europe/Berlin');
            $von = $nowLocal->copy()->startOfMonth()->toDateString();
            $bis = $nowLocal->toDateString();
        }
        if (strcmp($von, $bis) > 0) { [$von, $bis] = [$bis, $von]; }
        // Cap the window to keep the query cheap (max ~6 months).
        if (Carbon::parse($von)->diffInDays(Carbon::parse($bis)) > 185) {
            $bis = Carbon::parse($von)->addDays(185)->toDateString();
        }

        $hasFreigabe = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'zeit_freigabe');
        $freigabeCol = $hasFreigabe ? 'em.zeit_freigabe' : '0 AS zeit_freigabe';
        $hasEmPause  = Schema::connection('tenant')->hasColumn('einsatz_mitarbeiter', 'pause_minuten');
        $emPauseCol  = $hasEmPause ? 'em.pause_minuten AS em_pause' : 'NULL AS em_pause';

        $rows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum,
                   em.ist_std,
                   em.ist_start AS em_start, em.ist_ende AS em_end,
                   z.clock_in, z.clock_out, z.clock_in_real, z.clock_out_real,
                   z.pause_total, z.notiz,
                   {$emPauseCol},
                   {$freigabeCol},
                   e.pause_minuten AS plan_pause,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   b.zeit_von AS order_start, b.zeit_bis AS order_end
            FROM einsatz_mitarbeiter em
            INNER JOIN einsaetze e ON em.einsatz_id = e.einsatz_id
            LEFT JOIN zeiterfassung z ON z.einsatz_id = e.einsatz_id
                AND z.dienst_nr = em.dienst_nr AND z.status = 'beendet'
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE em.dienst_nr = ? AND e.datum BETWEEN ? AND ?
              AND e.pruefung = 'geplant'
            ORDER BY e.datum ASC
        ", [$dienstNr, $von, $bis]);

        // Shifts the worker was pulled off after clocking time: the
        // einsatz_mitarbeiter row is gone but the finished zeiterfassung
        // record still holds real worked hours — they must stay visible
        // in the worker's own time sheet (Arbeitszeitaufzeichnung).
        $orphanRows = DB::connection('tenant')->select("
            SELECT e.einsatz_id, e.datum,
                   NULL AS ist_std,
                   NULL AS em_start, NULL AS em_end,
                   z.clock_in, z.clock_out, z.clock_in_real, z.clock_out_real,
                   z.pause_total, z.notiz,
                   NULL AS em_pause,
                   0 AS zeit_freigabe,
                   e.pause_minuten AS plan_pause,
                   d.zeit_von AS plan_start, d.zeit_bis AS plan_end,
                   b.zeit_von AS order_start, b.zeit_bis AS order_end
            FROM zeiterfassung z
            INNER JOIN einsaetze e ON z.einsatz_id = e.einsatz_id
            LEFT JOIN bestellungen b ON e.bestell_id = b.bestell_id
            LEFT JOIN bestelleingang_tage d ON d.eingang_id = b.eingang_id AND d.datum = e.datum
                AND (
                    (e.tag_nr IS NOT NULL AND d.tag_nr = e.tag_nr)
                 OR (e.tag_nr IS NULL AND d.tag_nr = (
                        SELECT MIN(d2.tag_nr) FROM bestelleingang_tage d2
                         WHERE d2.eingang_id = b.eingang_id AND d2.datum = e.datum
                    ))
                )
            WHERE z.dienst_nr = ? AND e.datum BETWEEN ? AND ?
              AND z.status = 'beendet' AND z.clock_out IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM einsatz_mitarbeiter em2
                              WHERE em2.einsatz_id = z.einsatz_id
                                AND em2.dienst_nr = z.dienst_nr)
        ", [$dienstNr, $von, $bis]);

        $rows = array_merge($rows, $orphanRows);

        $spanMinutes = function (?string $start, ?string $end): int {
            if (!$start || !$end) return 0;
            $s = Carbon::parse('2000-01-01 ' . $start);
            $e = Carbon::parse('2000-01-01 ' . $end);
            if ($e->lte($s)) $e->addDay(); // overnight shift
            return $s->diffInMinutes($e);
        };

        $days = [];
        foreach ($rows as $r) {
            $key = $r->datum;
            if (!isset($days[$key])) {
                $days[$key] = ['datum' => $key, 'plan_minutes' => 0, 'ist_minutes' => 0, 'pause_minutes' => 0, 'shifts' => 0, 'confirmed' => true, 'items' => []];
            }

            // Plan: dispo day plan wins, then order times, then nothing.
            // Net of the planned pause so it compares like-for-like with Ist.
            $planSpan = $spanMinutes(
                $r->plan_start ?: $r->order_start,
                $r->plan_end   ?: $r->order_end
            );
            $days[$key]['plan_minutes'] += $planSpan > 0 ? max(0, $planSpan - (int) ($r->plan_pause ?? 0)) : 0;

            // Ist: ONLY real sources — the per-worker hours entered/corrected
            // in the Monatsabschluss (em.ist_std) or the worker's own finished
            // clock record. Shift-level ist fields are pre-filled with planned
            // times at creation and would fake an 'Ist' for unstamped days.
            if ($r->ist_std !== null && $r->ist_std !== '') {
                $days[$key]['ist_minutes'] += (int) round(floatval($r->ist_std) * 60);
            } elseif (!empty($r->clock_in) && !empty($r->clock_out)) {
                $sec = Carbon::parse($r->clock_in)->diffInSeconds(Carbon::parse($r->clock_out));
                $days[$key]['ist_minutes'] += max(0, (int) round($sec / 60) - (int) $r->pause_total);
            }

            // The pause shown is the one actually deducted: once the record
            // is finalized, em.pause_minuten holds max(recorded, planned);
            // before that (or for orphan rows) the app's own pause_total.
            $pauseMin = $r->em_pause !== null ? (int) $r->em_pause : (int) ($r->pause_total ?? 0);
            $days[$key]['pause_minutes'] += $pauseMin;

            // Per-shift detail for the Zeiten card: plan times (order),
            // counted times (office ist / capped clock), real app stamps
            // ("gest.") only when they differ, and the deducted pause.
            $hasIst = $r->ist_std !== null && $r->ist_std !== '' || !empty($r->clock_out);
            $istStart = $r->em_start !== null && $r->em_start !== ''
                ? substr($r->em_start, 0, 5)
                : (!empty($r->clock_in) ? substr($r->clock_in, 11, 5) : null);
            $istEnde = $r->em_end !== null && $r->em_end !== ''
                ? substr($r->em_end, 0, 5)
                : (!empty($r->clock_out) ? substr($r->clock_out, 11, 5) : null);
            $autoClosed = empty($r->clock_out_real)
                && !empty($r->clock_in) && !empty($r->clock_out)
                && is_string($r->notiz ?? null) && str_contains($r->notiz, 'Automatisch');
            $gestIn = !empty($r->clock_in_real) ? substr($r->clock_in_real, 11, 5) : null;
            if ($gestIn !== null && $gestIn === $istStart) $gestIn = null;
            $gestOut = $autoClosed ? 'autom.'
                : (!empty($r->clock_out_real) ? substr($r->clock_out_real, 11, 5) : null);
            if ($gestOut !== null && $gestOut === $istEnde) $gestOut = null;
            $itemIst = 0;
            if ($r->ist_std !== null && $r->ist_std !== '') {
                $itemIst = (int) round(floatval($r->ist_std) * 60);
            } elseif (!empty($r->clock_in) && !empty($r->clock_out)) {
                $sec = Carbon::parse($r->clock_in)->diffInSeconds(Carbon::parse($r->clock_out));
                $itemIst = max(0, (int) round($sec / 60) - (int) $r->pause_total);
            }
            $days[$key]['items'][] = [
                'plan_start' => $r->plan_start ?: $r->order_start,
                'plan_end'   => $r->plan_end   ?: $r->order_end,
                'ist_start'  => $hasIst ? $istStart : null,
                'ist_ende'   => $hasIst ? $istEnde : null,
                'ist_minutes' => $hasIst ? $itemIst : 0,
                'pause'      => $pauseMin,
                'gest_in'    => $gestIn,
                'gest_out'   => $gestOut,
                'confirmed'  => (bool) (int) $r->zeit_freigabe,
            ];

            $days[$key]['shifts']++;
            if (!(int) $r->zeit_freigabe) $days[$key]['confirmed'] = false;
        }

        return response()->json([
            'von'  => $von,
            'bis'  => $bis,
            'days' => array_values($days),
        ]);
    }

    // --- Shift lifecycle helpers ---

    /**
     * Auto-close a forgotten shift only after both the runtime cap and the
     * official/extended end plus grace have passed. The worker actuals are
     * stored without changing the official Einsatz plan.
     */
    private function autoCloseStaleShifts(string $dienstNr): void
    {
        $now = Carbon::now();
        $cutoff = $now->copy()->subHours(self::MAX_SHIFT_HOURS);
        $stale = DB::connection('tenant')->select(
            "SELECT z.*, e.datum, e.ist_start, e.ist_ende
             FROM zeiterfassung z
             INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
             WHERE z.dienst_nr = ? AND z.status IN ('aktiv', 'pause') AND z.clock_in < ?",
            [$dienstNr, $cutoff]
        );

        foreach ($stale as $record) {
            $closeAt = Carbon::parse($record->clock_in)->addHours(self::MAX_SHIFT_HOURS);
            $effectiveEnd = self::currentShiftEnd($record);
            if ($effectiveEnd && $effectiveEnd->copy()->addMinutes(self::END_GRACE_MINUTES)->gt($closeAt)) {
                $closeAt = $effectiveEnd->copy()->addMinutes(self::END_GRACE_MINUTES);
            }
            if ($now->gt($closeAt)) self::forceCloseShift($record, $closeAt);
        }
    }

    /**
     * Close ONE stale shift at the selected safe boundary and propagate the
     * actuals to einsatz_mitarbeiter. Public so the every-minute scheduler
     * (NotifyShiftEnding) can enforce the same rule even when the
     * worker never opens the app again — without it, a forgotten shift
     * would show "Arbeitet" in Dispo forever.
     */
    public static function forceCloseShift(object $record, ?Carbon $clockOut = null, ?string $notizText = null): void
    {
        if (!property_exists($record, 'datum')) {
            $plan = DB::connection('tenant')->table('einsaetze')->where('einsatz_id', $record->einsatz_id)
                ->select('datum', 'ist_start', 'ist_ende')->first();
            $record = (object) array_merge((array) $record, (array) ($plan ?? []));
        }
        $clockIn  = Carbon::parse($record->clock_in);
        // Same rule as a manual clock-out: never counted past the planned /
        // extended end, never before the counted start.
        $clockOut = self::countedClockOut($record, $clockOut ?? $clockIn->copy()->addHours(self::MAX_SHIFT_HOURS));

        $pauseTotal = (int) $record->pause_total;
        if ($record->status === 'pause' && !empty($record->pause_start)) {
            $pauseStart = Carbon::parse($record->pause_start);
            if ($pauseStart->lt($clockOut)) {
                $pauseTotal += max(0, (int) round($pauseStart->diffInSeconds($clockOut) / 60));
            }
        }

        $update = [
            'clock_out'   => $clockOut,
            'status'      => 'beendet',
            'pause_total' => $pauseTotal,
            'notiz'       => trim(((string)($record->notiz ?? '') !== '' ? $record->notiz . ' — ' : '')
                . ($notizText ?? 'Automatisch beendet nach ' . self::MAX_SHIFT_HOURS . ' Std. (Ausstempeln vergessen)')),
        ];
        if (Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')) {
            $update['pause_start'] = null;
        }
        DB::connection('tenant')->table('zeiterfassung')->where('id', $record->id)->update($update);

        self::writeWorkerActuals($record->einsatz_id, $record->dienst_nr, $clockIn, $clockOut, $pauseTotal);
        \App\Services\Qm\WachbuchService::nachCheckOut($record->einsatz_id, (string) $record->dienst_nr, $clockOut, null, 'system');
    }

    /**
     * Close open zeiterfassung records when the dispatcher pulls workers off
     * a shift (or deletes/rejects it): a running timer must not outlive the
     * assignment it belongs to. The recorded time is preserved — clock_out
     * is set to the removal moment — and actuals propagate to
     * einsatz_mitarbeiter the same way a manual clock-out would.
     * Pass $dienstNrs to limit the close to specific workers; empty closes
     * every open record on the einsatz (shift deletion).
     */
    public static function closeOpenShiftsForEinsatz(string $einsatzId, array $dienstNrs = [], string $reason = 'Zuweisung entfernt — Schicht automatisch beendet'): int
    {
        $query = DB::connection('tenant')->table('zeiterfassung')
            ->where('einsatz_id', $einsatzId)
            ->whereIn('status', ['aktiv', 'pause']);
        if (!empty($dienstNrs)) $query->whereIn('dienst_nr', $dienstNrs);
        $open = $query->get();
        foreach ($open as $record) {
            self::forceCloseShift($record, Carbon::now(), $reason);
        }
        return $open->count();
    }

    /**
     * Shared strict GPS verification for clock-in AND clock-out:
     *   1. coordinates must be present and well-formed,
     *   2. the reported accuracy must be usable (<= MAX_GPS_ACCURACY_M),
     *   3. the Einsatz must have a resolvable address,
     *   4. the worker must be within the object's radius.
     * Returns a JsonResponse on failure, or null when the position is OK.
     */
    private function verifyGeoAgainstEinsatz(string $einsatzId, $geo, $accuracy)
    {
        // Geofence disabled (WORKER_GEO_ENFORCE=false): workers may stamp
        // from anywhere. geo_in/geo_out are still stored when the client
        // reports a position — the check is skipped, not the recording.
        if (!config('services.worker_geo.enforce', true)) {
            return null;
        }

        if (!$geo || !preg_match('/^-?\d{1,3}(\.\d+)?,-?\d{1,3}(\.\d+)?$/', $geo)) {
            return response()->json([
                'error'     => 'Standortfreigabe erforderlich. Bitte aktivieren Sie GPS und versuchen Sie es erneut.',
                'needs_gps' => true,
            ], 403);
        }

        // Reject unusable fixes: a ±500 m position says nothing about being
        // on site. The app sends the accuracy reported by the OS.
        if ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy)
            && (float) $accuracy > self::MAX_GPS_ACCURACY_M) {
            return response()->json([
                'error'     => 'GPS-Signal zu ungenau (±' . round((float) $accuracy) . ' m). Bitte im Freien erneut versuchen.',
                'needs_gps' => true,
            ], 403);
        }

        $locationAddress = GeoLocation::einsatzAdresse($einsatzId);

        if (!$locationAddress) {
            return response()->json([
                'error' => 'Für diesen Einsatz ist kein Einsatzort hinterlegt. Bitte wenden Sie sich an die Disposition.',
            ], 422);
        }

        // Per-object allowed radius in metres (configurable; default 100).
        $radius = 100;
        if (Schema::connection('tenant')->hasColumn('objekte', 'geo_radius')) {
            $cfg = DB::connection('tenant')->table('einsaetze as e')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->where('e.einsatz_id', $einsatzId)
                ->value('o.geo_radius');
            if ($cfg) {
                $radius = (int) $cfg;
            }
        }

        $geoResult = GeoLocation::geocodeAddress($locationAddress);
        if (!$geoResult) {
            return response()->json([
                'error' => 'Der Einsatzort konnte nicht überprüft werden. Bitte versuchen Sie es erneut oder melden Sie sich bei der Disposition.',
            ], 422);
        }

        [$workerLat, $workerLon] = explode(',', $geo);
        $distance = GeoLocation::haversineDistance(
            (float)$workerLat, (float)$workerLon,
            (float)$geoResult['lat'], (float)$geoResult['lon']
        );
        if ($distance > $radius) {
            return response()->json([
                'error'      => 'Sie sind zu weit vom Einsatzort entfernt (' . round($distance) . 'm). Maximaler Radius: ' . $radius . 'm.',
                'distance'   => round($distance),
                'max_radius' => $radius,
            ], 403);
        }

        return null;
    }
}
