<?php

namespace App\Support;

use App\Http\Controllers\Api\WorkerAppZeitController as Z;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only consistency check of the clock records of the CURRENT tenant
 * against the clock rules (WorkerAppZeitController) and against what the
 * Monatsabschluss reads (einsatz_mitarbeiter).
 *
 * Returns [code => [['key' => 'CODE:id', 'msg' => '…'], …]].
 *   Alert codes  — rule violations that should never happen.
 *   Info codes   — EM_DIFF (office correction, expected) and EM_MISSING
 *                  (worker removed after clocking, kept on purpose).
 */
class ZeitAudit
{
    public const INFO_CODES = ['EM_DIFF', 'EM_DIFF_APPROVED', 'EM_MISSING'];

    public static function run(string $fromDate, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $db = DB::connection('tenant');
        $rows = $db->select("
            SELECT z.*, e.datum, e.ist_start AS plan_start, e.ist_ende AS plan_end, e.pause_minuten AS plan_pause, e.pruefung,
                   em.id AS em_id, em.ist_start AS em_start, em.ist_ende AS em_end, em.ist_std AS em_std,
                   em.pause_minuten AS em_pause, em.zeit_freigabe
            FROM zeiterfassung z
            JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
            LEFT JOIN einsatz_mitarbeiter em ON em.einsatz_id = z.einsatz_id AND em.dienst_nr = z.dienst_nr
            WHERE e.datum >= ? ORDER BY z.id", [$fromDate]);

        $issues = [];
        $hm = fn ($utc) => $utc ? Carbon::parse($utc, 'UTC')->setTimezone('Europe/Berlin')->format('H:i') : '–';
        $hms = fn ($utc) => $utc ? Carbon::parse($utc, 'UTC')->setTimezone('Europe/Berlin')->format('H:i:s') : '–';
        $add = function (string $code, $r, string $msg) use (&$issues) {
            $issues[$code][] = ['key' => "$code:{$r->id}", 'msg' => "{$r->dienst_nr} {$r->einsatz_id} ({$r->datum}): $msg"];
        };

        foreach ($rows as $r) {
            $rec = (object) ['datum' => $r->datum, 'ist_start' => $r->plan_start, 'ist_ende' => $r->plan_end, 'verlaengert_bis' => $r->verlaengert_bis];
            $in = Carbon::parse($r->clock_in, 'UTC');
            $planStart = Z::plannedShiftStart($rec);
            $end = Z::currentShiftEnd($rec);

            if ($r->clock_in_real) {
                $tap = Carbon::parse($r->clock_in_real, 'UTC');
                $expect = Z::countedClockIn($r->datum, $r->plan_start, $tap);
                if ($expect->getTimestamp() !== $in->getTimestamp()) $add('IN_RULE', $r, "Beginn {$hms($r->clock_in)} ≠ Regel {$hms($expect)} (Tipp {$hms($r->clock_in_real)})");
                if ($planStart && $planStart->getTimestamp() - $tap->getTimestamp() > Z::CLOCK_IN_EARLY_MIN * 60 + Z::CLOCK_IN_TOLERANCE_SEC) {
                    $add('TAP_EARLY', $r, 'gestempelt ' . round(($planStart->getTimestamp() - $tap->getTimestamp()) / 60) . ' Min vor Beginn');
                }
            }
            if ($planStart && $in->lt($planStart)) $add('IN_BEFORE_PLAN', $r, "Beginn {$hm($r->clock_in)} vor Plan {$r->plan_start}");
            if ($end && $in->gt($end)) $add('IN_AFTER_END', $r, "Beginn {$hm($r->clock_in)} nach Ende {$hm($end)}");

            if ($r->status === 'beendet') {
                if (!$r->clock_out) { $add('NO_OUT', $r, 'beendet ohne Ende'); continue; }
                $out = Carbon::parse($r->clock_out, 'UTC');
                if ($out->lt($in)) $add('OUT_BEFORE_IN', $r, "Ende {$hm($r->clock_out)} vor Beginn {$hm($r->clock_in)}");
                if ($end && $out->gt($end)) $add('OUT_AFTER_END', $r, "Ende {$hm($r->clock_out)} nach Schichtende {$hm($end)}");
                if ($r->clock_out_real) {
                    $exp = Z::countedClockOut((object) array_merge((array) $rec, ['clock_in' => $r->clock_in]), Carbon::parse($r->clock_out_real, 'UTC'));
                    if ($exp->getTimestamp() !== $out->getTimestamp()) $add('OUT_RULE', $r, "Ende {$hms($r->clock_out)} ≠ Regel {$hms($exp)} (Tipp {$hms($r->clock_out_real)})");
                }
                if (!$r->em_id) { $add('EM_MISSING', $r, 'Zuweisung entfernt (Zeit bleibt im Stundenzettel)'); continue; }
                $sec = $out->getTimestamp() - $in->getTimestamp();
                $pause = $sec > 0 ? Z::deductedPause((int) $r->pause_total, (int) ($r->plan_pause ?? 0)) : 0;
                $std = round(max(0, $sec - $pause * 60) / 3600, 2);
                $same = substr((string) $r->em_start, 0, 5) === $hm($r->clock_in) && substr((string) $r->em_end, 0, 5) === $hm($r->clock_out)
                    && abs((float) $r->em_std - $std) < 0.011 && (int) $r->em_pause === $pause;
                if (!$same) {
                    $add((int) $r->zeit_freigabe === 1 ? 'EM_DIFF_APPROVED' : 'EM_DIFF', $r,
                        "Zeiterfassung {$r->em_start}–{$r->em_end} {$r->em_std} h | App {$hm($r->clock_in)}–{$hm($r->clock_out)} {$std} h");
                }
            } else {
                $closeAt = $in->copy()->addHours(Z::MAX_SHIFT_HOURS);
                if ($end && $end->copy()->addMinutes(Z::END_GRACE_MINUTES)->gt($closeAt)) $closeAt = $end->copy()->addMinutes(Z::END_GRACE_MINUTES);
                if ($now->gt($closeAt->copy()->addMinutes(5))) $add('STALE_ACTIVE', $r, "läuft noch, hätte um {$hm($closeAt)} schließen müssen");
                if ($r->pruefung !== 'geplant') $add('ACTIVE_UNPLANNED', $r, "läuft auf Einsatz mit Status {$r->pruefung}");
            }
        }

        foreach ($db->select("SELECT MIN(id) id, einsatz_id, dienst_nr, COUNT(*) n FROM zeiterfassung GROUP BY einsatz_id, dienst_nr HAVING n > 1") as $d) {
            $issues['DUPLICATE'][] = ['key' => "DUPLICATE:{$d->einsatz_id}:{$d->dienst_nr}", 'msg' => "{$d->dienst_nr} {$d->einsatz_id}: {$d->n} Datensätze"];
        }
        foreach ($db->select("SELECT dienst_nr, COUNT(*) n FROM zeiterfassung WHERE status IN ('aktiv','pause') GROUP BY dienst_nr HAVING n > 1") as $d) {
            $issues['MULTI_ACTIVE'][] = ['key' => "MULTI_ACTIVE:{$d->dienst_nr}", 'msg' => "{$d->dienst_nr}: {$d->n} laufende Schichten"];
        }
        return $issues;
    }
}
