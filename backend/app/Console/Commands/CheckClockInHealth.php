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
 * System-level watchdog for the worker check-in. Individual late workers
 * are handled by dispo:notify-late-clockins; this command detects that
 * check-in as a whole is broken and alerts the Dispo managers once:
 *
 *   1. server errors: >= --errors 5xx answers on clock-in/out for the
 *      tenant within the last --window minutes (logs/clockin.log), or
 *   2. silence: >= --min-shifts planned shifts started --grace..--window
 *      minutes ago and NOT ONE of them was clocked in.
 *
 * One alert per tenant per --cooldown minutes ('system.clockin_health').
 *
 *   php artisan zeit:clockin-health [--tenant=slug] [--dry-run]
 */
class CheckClockInHealth extends Command
{
    protected $signature = 'zeit:clockin-health
                            {--tenant= : Limit to a single tenant slug}
                            {--window=75 : Look-back window in minutes}
                            {--grace=15 : Minutes after planned start before a shift counts}
                            {--min-shifts=3 : Shifts needed before silence counts as an outage}
                            {--errors=3 : 5xx answers in the window that count as an outage}
                            {--cooldown=120 : Minutes between two alerts for the same tenant}
                            {--dry-run : Report only; do not send notifications}';

    protected $description = 'Alert Dispo when worker check-in fails system-wide (server errors or nobody clocked in)';

    public function handle(): int
    {
        $window    = max(20, (int) $this->option('window'));
        $grace     = max(1, (int) $this->option('grace'));
        $minShifts = max(1, (int) $this->option('min-shifts'));
        $minErrors = max(1, (int) $this->option('errors'));
        $cooldown  = max(10, (int) $this->option('cooldown'));
        $dryRun    = (bool) $this->option('dry-run');

        $nowUtc   = Carbon::now('UTC');
        $nowLocal = Carbon::now('Europe/Berlin');
        $errorsByDb = $this->recentServerErrors($nowUtc->copy()->subMinutes($window));

        $slug = $this->option('tenant');
        $tenants = $slug ? Tenant::where('slug', $slug)->get() : Tenant::where('aktiv', true)->get();

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);
            $db = DB::connection('tenant');
            if (!Schema::connection('tenant')->hasTable('zeiterfassung')
                || !Schema::connection('tenant')->hasTable('notifications')) continue;

            $errors = $errorsByDb[$db->getDatabaseName()] ?? 0;

            $from = $nowLocal->copy()->subMinutes($window);
            $to   = $nowLocal->copy()->subMinutes($grace);
            $dates = array_values(array_unique([$from->toDateString(), $to->toDateString()]));
            $shift = $db->table('einsatz_mitarbeiter as em')
                ->join('einsaetze as e', 'e.einsatz_id', '=', 'em.einsatz_id')
                ->leftJoin('zeiterfassung as z', function ($j) {
                    $j->on('z.einsatz_id', '=', 'em.einsatz_id')->on('z.dienst_nr', '=', 'em.dienst_nr');
                })
                ->whereIn('e.datum', $dates)
                ->where('e.pruefung', 'geplant')
                ->whereNotNull('e.ist_start')
                ->whereRaw('TIMESTAMP(e.datum, e.ist_start) BETWEEN ? AND ?',
                    [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
                ->selectRaw('COUNT(*) AS expected, COUNT(z.id) AS clocked')
                ->first();
            $expected = (int) ($shift->expected ?? 0);
            $clocked  = (int) ($shift->clocked ?? 0);

            $reason = null;
            if ($errors >= $minErrors) {
                $reason = "{$errors} Serverfehler beim Ein-/Ausstempeln in den letzten {$window} Minuten.";
            } elseif ($expected >= $minShifts && $clocked === 0) {
                $reason = "{$expected} geplante Schichten haben in den letzten {$window} Minuten begonnen — niemand hat eingestempelt.";
            }
            if (!$reason) continue;

            $recent = $db->table('notifications')
                ->where('type', 'system.clockin_health')
                ->where('created_at', '>=', $nowUtc->copy()->subMinutes($cooldown))
                ->exists();
            $this->line(sprintf('%s %-30s %s%s', $nowUtc->toDateTimeString(), substr($tenant->slug, 0, 30), $reason,
                $recent ? ' (cooldown)' : ($dryRun ? ' [DRY]' : '')));
            if ($recent || $dryRun) continue;

            NotificationDispatcher::notifyDispoManagers(
                (int) $tenant->id,
                'system.clockin_health',
                'Störung Zeiterfassung: Einstempeln prüfen',
                $reason . "\nBitte sofort prüfen (Mitarbeiter-App / Server) und Mitarbeiter ggf. telefonisch erfassen.",
                '#tasks',
                ['errors' => $errors, 'expected' => $expected, 'clocked' => $clocked, 'at' => $nowUtc->toDateTimeString()]
            );
        }

        TenantService::reset();
        return 0;
    }

    /** 5xx clock answers per tenant database since $since (reads only the log tail). */
    private function recentServerErrors(Carbon $since): array
    {
        $path = storage_path('logs/clockin.log');
        if (!is_file($path)) return [];
        $fh = @fopen($path, 'r');
        if (!$fh) return [];
        $size = filesize($path);
        if ($size > 2_000_000) fseek($fh, $size - 2_000_000);
        $counts = [];
        $sinceStr = $since->toDateTimeString();
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row) || ($row['at'] ?? '') < $sinceStr || (int) ($row['status'] ?? 0) < 500) continue;
            $db = (string) ($row['db'] ?? '');
            $counts[$db] = ($counts[$db] ?? 0) + 1;
        }
        fclose($fh);
        return $counts;
    }
}
