<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\WorkerPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Reminds assigned workers shortly before their shift starts:
 * an in-app notification (worker_notifications, type 'shift.reminder')
 * plus a real device push (works with the app closed) via WorkerPushService.
 *
 * Runs every 5 minutes (see App\Console\Kernel) and looks LEAD minutes
 * ahead (default 15): any shift starting within [now, now+LEAD] whose
 * assigned workers have not been reminded yet gets one reminder per worker.
 * Deduplication is persisted in `einsatz_reminders_sent`, so restarts or
 * overlapping windows can never double-notify.
 *
 * Only shifts that are actually planned/confirmed remind their workers:
 *     hms_bestaetigt <> 'nein'  AND  (pruefung = 'geplant' OR hms_bestaetigt = 'ja')
 *
 * Usage:
 *   php artisan shifts:remind-upcoming                 # all active tenants
 *   php artisan shifts:remind-upcoming --tenant=acme
 *   php artisan shifts:remind-upcoming --minutes=30
 *   php artisan shifts:remind-upcoming --dry-run       # report only
 */
class RemindUpcomingShifts extends Command
{
    protected $signature = 'shifts:remind-upcoming
                            {--tenant= : Limit to a single tenant slug}
                            {--minutes=15 : Lookahead window in minutes}
                            {--tz=Europe/Berlin : Wall-clock timezone the shift times are entered in}
                            {--dry-run : Report only; do not send notifications}';

    protected $description = 'Push a reminder to assigned workers shortly before their shift starts';

    public function handle(): int
    {
        $slug    = $this->option('tenant');
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun  = (bool) $this->option('dry-run');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        // Shift times (datum + ist_start) are stored as German wall-clock
        // values while app.timezone is UTC — so "now" must be taken in the
        // same wall-clock timezone for the window comparison to be correct.
        $tz  = (string) ($this->option('tz') ?: 'Europe/Berlin');
        $now = Carbon::now($tz);
        $windowEnd = $now->copy()->addMinutes($minutes);
        $totalSent = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            if (!Schema::connection('tenant')->hasTable('einsaetze')
                || !Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')
                || !Schema::connection('tenant')->hasTable('einsatz_reminders_sent')
                || !Schema::connection('tenant')->hasTable('worker_notifications')) {
                continue;
            }

            // Assigned workers of shifts starting within the lookahead window.
            // The window may cross midnight, so both dates are considered.
            $dates = array_values(array_unique([$now->toDateString(), $windowEnd->toDateString()]));

            $rows = DB::connection('tenant')
                ->table('einsatz_mitarbeiter as em')
                ->join('einsaetze as e', 'em.einsatz_id', '=', 'e.einsatz_id')
                ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                ->leftJoin('einsatz_reminders_sent as r', function ($j) {
                    $j->on('r.einsatz_id', '=', 'em.einsatz_id')
                      ->on('r.dienst_nr', '=', 'em.dienst_nr');
                })
                ->whereNull('r.id')
                ->whereIn('e.datum', $dates)
                ->whereNotNull('e.ist_start')
                ->where('e.hms_bestaetigt', '!=', 'nein')
                ->where(function ($q) {
                    $q->where('e.pruefung', 'geplant')->orWhere('e.hms_bestaetigt', 'ja');
                })
                ->whereRaw(
                    "TIMESTAMP(e.datum, e.ist_start) BETWEEN ? AND ?",
                    [$now->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')]
                )
                ->select('em.einsatz_id', 'em.dienst_nr', 'm.id as m_id',
                         'e.datum', 'e.ist_start', 'e.ist_ende', 'e.ort',
                         'k.kundenname', 'o.objektname')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $this->line(sprintf('  %-30s due=%d', substr($tenant->slug, 0, 30), $rows->count()));

            if ($dryRun) {
                $totalSent += $rows->count();
                continue;
            }

            foreach ($rows as $r) {
                // Claim the dedup row FIRST — a unique-key violation means a
                // concurrent run already handled this worker/shift pair.
                try {
                    DB::connection('tenant')->table('einsatz_reminders_sent')->insert([
                        'einsatz_id' => $r->einsatz_id,
                        'dienst_nr'  => $r->dienst_nr,
                        'sent_at'    => now(),
                    ]);
                } catch (\Throwable $e) {
                    continue; // duplicate — already reminded
                }

                $start = substr((string) $r->ist_start, 0, 5);
                $ende  = substr((string) $r->ist_ende, 0, 5);
                $where = $r->objektname ?: ($r->kundenname ?: 'Einsatz');

                $title = "Dein Einsatz beginnt um {$start} Uhr";
                $body  = $where . ' · ' . $start . ($ende !== '' ? ' – ' . $ende : '')
                       . ($r->ort ? "\nOrt: " . $r->ort : '');

                try {
                    DB::connection('tenant')->table('worker_notifications')->insert([
                        'mitarbeiter_id' => $r->m_id,
                        'dienst_nr'      => $r->dienst_nr,
                        'type'           => 'shift.reminder',
                        'title'          => $title,
                        'body'           => $body,
                        'payload'        => json_encode(['einsatz_id' => $r->einsatz_id, 'datum' => $r->datum], JSON_UNESCAPED_UNICODE),
                        'gelesen'        => 0,
                        'created_at'     => now(),
                    ]);
                    WorkerPushService::push((string) $r->dienst_nr, $title, $body, 'plan');
                    $totalSent++;
                } catch (\Throwable $e) {
                    Log::warning('shifts:remind-upcoming send failed', [
                        'tenant'     => $tenant->slug,
                        'einsatz_id' => $r->einsatz_id,
                        'dienst_nr'  => $r->dienst_nr,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        TenantService::reset();

        // Runs every 5 minutes — only log when something happened (or -v / dry-run).
        if ($totalSent > 0 || $dryRun || $this->output->isVerbose()) $this->info(sprintf(
            '%s %d reminder(s) (window: %d min).',
            $dryRun ? '[DRY-RUN] Would send' : 'Sent',
            $totalSent,
            $minutes,
        ));

        return 0;
    }
}
