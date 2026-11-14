<?php

namespace App\Console\Commands;

use App\Helpers\NotificationDispatcher;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts the Dispo-responsible users (owner + auftraege.dispo.*) when an
 * assigned worker has NOT clocked in although their planned shift start
 * (einsaetze.datum + ist_start) is more than GRACE minutes in the past —
 * so the dispatcher can call the worker before the customer notices.
 *
 * A worker counts as clocked in when a `zeiterfassung` row exists for the
 * (einsatz_id, dienst_nr) pair. Only shifts that are actually planned
 * remind: pruefung = 'geplant' OR hms_bestaetigt = 'ja' (same rule as the
 * worker-side reminder in RemindUpcomingShifts).
 *
 * One alert (bell + email) per worker/shift pair. Deduplication needs no
 * schema change: before sending we check the `notifications` table for an
 * existing 'dispo.late_clockin' row whose payload references the same
 * pair. The lookback window is bounded (default 180 min) so the first
 * deploy does not flood alerts for long-past shifts.
 *
 * Usage:
 *   php artisan dispo:notify-late-clockins                 # all active tenants
 *   php artisan dispo:notify-late-clockins --tenant=acme
 *   php artisan dispo:notify-late-clockins --grace=10 --lookback=180
 *   php artisan dispo:notify-late-clockins --dry-run       # report only
 */
class NotifyLateClockIns extends Command
{
    protected $signature = 'dispo:notify-late-clockins
                            {--tenant= : Limit to a single tenant slug}
                            {--grace=10 : Minutes after planned start before a worker counts as late}
                            {--lookback=180 : Ignore shifts whose start is further in the past (minutes)}
                            {--tz=Europe/Berlin : Wall-clock timezone the shift times are entered in}
                            {--dry-run : Report only; do not send notifications/emails}';

    protected $description = 'Notify Dispo managers when an assigned worker has not clocked in after shift start';

    public function handle(): int
    {
        $slug     = $this->option('tenant');
        $grace    = max(1, (int) $this->option('grace'));
        $lookback = max($grace + 1, (int) $this->option('lookback'));
        $dryRun   = (bool) $this->option('dry-run');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        // Shift times are German wall-clock values while app.timezone is
        // UTC — compare in the same wall-clock timezone (see RemindUpcomingShifts).
        $tz          = (string) ($this->option('tz') ?: 'Europe/Berlin');
        $now         = Carbon::now($tz);
        $windowEnd   = $now->copy()->subMinutes($grace);     // start must be at least GRACE min ago
        $windowStart = $now->copy()->subMinutes($lookback);  // …but not older than the lookback
        $totalSent   = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            if (!Schema::connection('tenant')->hasTable('einsaetze')
                || !Schema::connection('tenant')->hasTable('einsatz_mitarbeiter')
                || !Schema::connection('tenant')->hasTable('zeiterfassung')
                || !Schema::connection('tenant')->hasTable('notifications')) {
                continue;
            }

            // The window may cross midnight, so both dates are considered.
            $dates = array_values(array_unique([$windowStart->toDateString(), $windowEnd->toDateString()]));

            $rows = DB::connection('tenant')
                ->table('einsatz_mitarbeiter as em')
                ->join('einsaetze as e', 'em.einsatz_id', '=', 'e.einsatz_id')
                ->join('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
                ->leftJoin('kunden as k', 'e.kunden_id', '=', 'k.kunde_id')
                ->leftJoin('objekte as o', 'e.obj_id', '=', 'o.obj_id')
                // Not clocked in: no zeiterfassung row for this worker+shift.
                ->leftJoin('zeiterfassung as z', function ($j) {
                    $j->on('z.einsatz_id', '=', 'em.einsatz_id')
                      ->on('z.dienst_nr', '=', 'em.dienst_nr');
                })
                ->whereNull('z.id')
                ->whereIn('e.datum', $dates)
                ->whereNotNull('e.ist_start')
                ->where('e.hms_bestaetigt', '!=', 'nein')
                ->where(function ($q) {
                    $q->where('e.pruefung', 'geplant')->orWhere('e.hms_bestaetigt', 'ja');
                })
                ->whereRaw(
                    'TIMESTAMP(e.datum, e.ist_start) BETWEEN ? AND ?',
                    [$windowStart->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')]
                )
                ->select('em.einsatz_id', 'em.dienst_nr',
                         'm.vorname', 'm.name', 'm.telefon',
                         'e.datum', 'e.ist_start', 'e.ist_ende', 'e.ort',
                         'k.kundenname', 'o.objektname')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $due = 0;
            foreach ($rows as $r) {
                // Dedup: has this worker/shift pair already been alerted?
                // NOTE: payload is a JSON column — MySQL normalizes stored
                // JSON to '"key": "value"' (with space), so a LIKE pattern
                // '"key":"value"' never matches. JSON-path where is exact.
                $already = DB::connection('tenant')->table('notifications')
                    ->where('type', 'dispo.late_clockin')
                    ->where('payload->einsatz_id', (string) $r->einsatz_id)
                    ->where('payload->dienst_nr', (string) $r->dienst_nr)
                    ->exists();
                if ($already) continue;
                $due++;

                if ($dryRun) {
                    $this->line(sprintf(
                        '  [DRY] %-10s %-8s %s %s (%s)',
                        substr($tenant->slug, 0, 10), $r->dienst_nr,
                        $r->datum, substr((string) $r->ist_start, 0, 5),
                        $r->objektname ?: $r->kundenname ?: '-'
                    ));
                    continue;
                }

                $wName = trim(($r->vorname ?? '') . ' ' . ($r->name ?? '')) ?: $r->dienst_nr;
                $start = substr((string) $r->ist_start, 0, 5);
                $where = $r->objektname ?: ($r->kundenname ?: 'Einsatz');

                $title = "Nicht eingestempelt: {$wName} ({$r->dienst_nr})";
                $body  = "{$wName} hat den Einsatz um {$start} Uhr bei {$where} noch nicht gestartet "
                       . "(geplanter Beginn vor mehr als {$grace} Minuten)."
                       . ($r->telefon ? "\nTelefon: {$r->telefon}" : '')
                       . ($r->ort ? "\nOrt: {$r->ort}" : '')
                       . "\nBitte Kontakt aufnehmen und die Schicht prüfen.";

                $totalSent += NotificationDispatcher::notifyDispoManagers(
                    (int) $tenant->id,
                    'dispo.late_clockin',
                    $title,
                    $body,
                    '#tasks?einsatz=' . urlencode((string) $r->einsatz_id),
                    [
                        'einsatz_id' => (string) $r->einsatz_id,
                        'dienst_nr'  => (string) $r->dienst_nr,
                        'datum'      => (string) $r->datum,
                        'start'      => $start,
                    ]
                );
            }

            if ($due > 0) {
                $this->line(sprintf('  %-30s late=%d', substr($tenant->slug, 0, 30), $due));
            }
        }

        TenantService::reset();

        // Runs every 5 minutes — only log when something happened (or -v / dry-run).
        if ($totalSent > 0 || $dryRun || $this->output->isVerbose()) $this->info(sprintf(
            '%s %d late clock-in alert row(s) (grace %d min, lookback %d min).',
            $dryRun ? '[DRY-RUN] Would create' : 'Created',
            $totalSent,
            $grace,
            $lookback,
        ));

        return 0;
    }
}
