<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WorkerAppZeitController;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\WorkerPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-shift reminders for workers who are still clocked in
 * (zeiterfassung.status IN aktiv/pause):
 *
 *  1. PRE-WARNING  — LEAD minutes (default 15) before the effective end:
 *     "Deine Schicht endet um HH:MM — bitte pünktlich beenden."
 *     Sent once per shift (ende_vorwarnung_at).
 *
 *  2. END REMINDER — at the effective end (and again after every extension):
 *     "Schicht beenden oder um 30 Min verlängern?"
 *     The effective end is verlaengert_bis if the worker tapped "Verlängern",
 *     otherwise datum + ist_ende. Dedup: ende_erinnerung_at must be older
 *     than the current effective end, so each extension re-arms exactly one
 *     new reminder when its 30 minutes are up.
 *
 * Runs every minute (see App\Console\Kernel). Both go out as in-app
 * notification + real device push (works with the app closed).
 *
 *   php artisan shifts:notify-ending [--tenant=slug] [--lead=15] [--dry-run]
 */
class NotifyShiftEnding extends Command
{
    protected $signature = 'shifts:notify-ending
                            {--tenant= : Limit to a single tenant slug}
                            {--lead=15 : Minutes before the end for the pre-warning}
                            {--grace=30 : The 12 h hard cap never closes earlier than end + grace minutes}
                            {--dry-run : Report only; do not send notifications}';

    protected $description = 'Remind clocked-in workers shortly before and at the (extended) end of their shift';

    public function handle(): int
    {
        $slug   = $this->option('tenant');
        $lead   = max(1, (int) $this->option('lead'));
        $grace  = max(1, (int) $this->option('grace'));
        $dryRun = (bool) $this->option('dry-run');

        $tenants = $slug ? Tenant::where('slug', $slug)->get() : Tenant::where('aktiv', true)->get();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        $now  = Carbon::now();
        $sent = ['pre' => 0, 'end' => 0, 'stale' => 0, 'grace' => 0];

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            if (!Schema::connection('tenant')->hasTable('zeiterfassung')
                || !Schema::connection('tenant')->hasColumn('zeiterfassung', 'ende_erinnerung_at')
                || !Schema::connection('tenant')->hasTable('worker_notifications')) {
                continue;
            }

            $rows = DB::connection('tenant')->select("
                SELECT z.id, z.einsatz_id, z.dienst_nr, z.clock_in, z.status, z.notiz,
                       z.pause_total, z.pause_start, z.verlaengert_bis, z.verlaengerungen,
                       z.ende_vorwarnung_at, z.ende_erinnerung_at,
                       e.datum, e.ist_start, e.ist_ende,
                       m.id AS m_id, k.kundenname, o.objektname
                FROM zeiterfassung z
                INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
                INNER JOIN mitarbeiter m ON m.dienst_nr = z.dienst_nr
                LEFT JOIN kunden k ON k.kunde_id = e.kunden_id
                LEFT JOIN objekte o ON o.obj_id = e.obj_id
                WHERE z.status IN ('aktiv', 'pause') AND e.ist_ende IS NOT NULL
            ");

            foreach ($rows as $r) {
                $end = WorkerAppZeitController::currentShiftEnd($r);
                if (!$end) continue;

                // Hard cap never closes a worker before the official/extended
                // end plus grace, even when an early check-in reaches 12 hours.
                $hardCloseAt = Carbon::parse($r->clock_in)->addHours(WorkerAppZeitController::MAX_SHIFT_HOURS);
                $plannedCloseAt = $end->copy()->addMinutes($grace);
                if ($plannedCloseAt->gt($hardCloseAt)) $hardCloseAt = $plannedCloseAt;
                if ($now->gt($hardCloseAt)) {
                    if (!$dryRun) {
                        WorkerAppZeitController::forceCloseShift($r, $hardCloseAt);
                        $title = 'Schicht automatisch beendet';
                        $body  = 'Deine Schicht wurde automatisch beendet (Ausstempeln vergessen). '
                               . 'Bitte melde Abweichungen bei der Disposition.';
                        $this->notify($r, 'shift.auto_closed', $title, $body);
                    }
                    $sent['stale']++;
                    continue;
                }

                $endLocal = $end->copy()->setTimezone('Europe/Berlin')->format('H:i');
                $where    = $r->objektname ?: ($r->kundenname ?: 'Einsatz');
                $minsLeft = ($end->getTimestamp() - $now->getTimestamp()) / 60;

                // 1) Pre-warning: once, inside [end-lead, end).
                if ($r->ende_vorwarnung_at === null && $minsLeft <= $lead && $minsLeft > 0 && (int) $r->verlaengerungen === 0) {
                    $title = "Schicht endet in " . (int) ceil($minsLeft) . " Min";
                    $body  = "{$where} · geplantes Ende {$endLocal} Uhr.\nBitte beende die Schicht pünktlich in der App — oder verlängere sie, falls du länger gebraucht wirst.";
                    if ($this->send($r, 'shift.ending_soon', $title, $body, $dryRun, ['ende_vorwarnung_at' => $now])) {
                        $sent['pre']++;
                    }
                    continue; // never both messages in the same minute
                }

                // 2) End reminder: at/after the effective end, once per (extended) end.
                $alreadyForThisEnd = $r->ende_erinnerung_at !== null && Carbon::parse($r->ende_erinnerung_at)->gte($end);
                if ($minsLeft <= 0 && !$alreadyForThisEnd) {
                    $ext   = WorkerAppZeitController::EXTEND_MINUTES;
                    $n     = (int) $r->verlaengerungen;
                    $title = $n > 0 ? "Verlängerung abgelaufen ({$endLocal} Uhr)" : "Schichtende erreicht ({$endLocal} Uhr)";
                    $body  = "{$where}\nBitte jetzt die Schicht beenden — oder um {$ext} Minuten verlängern, wenn du noch gebraucht wirst.";
                    if ($this->send($r, 'shift.end_reached', $title, $body, $dryRun, ['ende_erinnerung_at' => $now])) {
                        $sent['end']++;
                    }
                    continue;
                }
                // No close at end + grace any more: the shift stays open until
                // the worker taps "Beenden" (counted only up to the planned /
                // extended end) or the hard cap above closes it.
            }
        }

        TenantService::reset();
        // Runs every minute — only log when something happened (or -v / dry-run).
        if (array_sum($sent) > 0 || $dryRun || $this->output->isVerbose()) $this->info(sprintf('%s pre-warnings=%d end-reminders=%d stale-closed=%d grace-closed=%d (lead %d, grace %d min).',
            $dryRun ? '[DRY-RUN]' : 'Sent', $sent['pre'], $sent['end'], $sent['stale'], $sent['grace'], $lead, $grace));
        return 0;
    }

    /**
     * In-app notification + push without a dedup stamp — used for the
     * stale-close message where the state change itself is the idempotence.
     */
    private function notify(object $r, string $type, string $title, string $body): void
    {
        try {
            DB::connection('tenant')->table('worker_notifications')->insert([
                'mitarbeiter_id' => $r->m_id,
                'dienst_nr'      => $r->dienst_nr,
                'type'           => $type,
                'title'          => $title,
                'body'           => $body,
                'payload'        => json_encode(['einsatz_id' => $r->einsatz_id, 'datum' => $r->datum, 'action' => 'zeit'], JSON_UNESCAPED_UNICODE),
                'gelesen'        => 0,
                'created_at'     => now(),
            ]);
            WorkerPushService::push((string) $r->dienst_nr, $title, $body, 'zeit');
        } catch (\Throwable $e) {
            Log::warning('shifts:notify-ending notify failed', ['einsatz_id' => $r->einsatz_id, 'dienst_nr' => $r->dienst_nr, 'error' => $e->getMessage()]);
        }
    }

    private function send(object $r, string $type, string $title, string $body, bool $dryRun, array $stamp): bool
    {
        if ($dryRun) {
            $this->line(sprintf('  [DRY] %-8s %-20s %s', $r->dienst_nr, $type, $title));
            return true;
        }
        // Claim the slot first so a slow push can never double-send.
        $claimed = DB::connection('tenant')->table('zeiterfassung')
            ->where('id', $r->id)
            ->where(array_key_first($stamp), $r->{array_key_first($stamp)}) // optimistic: unchanged since read
            ->update($stamp);
        if (!$claimed) return false;

        try {
            DB::connection('tenant')->table('worker_notifications')->insert([
                'mitarbeiter_id' => $r->m_id,
                'dienst_nr'      => $r->dienst_nr,
                'type'           => $type,
                'title'          => $title,
                'body'           => $body,
                'payload'        => json_encode(['einsatz_id' => $r->einsatz_id, 'datum' => $r->datum, 'action' => 'zeit'], JSON_UNESCAPED_UNICODE),
                'gelesen'        => 0,
                'created_at'     => now(),
            ]);
            WorkerPushService::push((string) $r->dienst_nr, $title, $body, 'zeit');
            return true;
        } catch (\Throwable $e) {
            Log::warning('shifts:notify-ending send failed', ['einsatz_id' => $r->einsatz_id, 'dienst_nr' => $r->dienst_nr, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
