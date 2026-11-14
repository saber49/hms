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
 * Ends a running pause automatically once the shift's planned pause
 * duration (einsaetze.pause_minuten, fallback gesetzl_pause) is reached —
 * a worker who forgets "Fortsetzen" returns to status 'aktiv' on schedule.
 *
 * Semantics: the pause ENDS at the planned mark. Exactly `planned` minutes
 * are added to pause_total and the time afterwards counts as work again —
 * consistent with billing, which deducts max(recorded pause, planned pause).
 * Shifts without a planned pause are left untouched (manual Fortsetzen /
 * Beenden still apply).
 *
 * The worker gets an in-app notification + device push so he knows the
 * clock is running again. The optimistic update (status='pause' AND
 * unchanged pause_start) makes overlapping runs idempotent.
 *
 * Runs every minute (see App\Console\Kernel).
 *
 *   php artisan shifts:auto-resume-pauses [--tenant=slug] [--dry-run]
 */
class PauseAutoResume extends Command
{
    protected $signature = 'shifts:auto-resume-pauses
                            {--tenant= : Limit to a single tenant slug}
                            {--dry-run : Report only; do not change anything}';

    protected $description = 'Auto-resume pauses once the planned pause duration is reached';

    public function handle(): int
    {
        $slug   = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $tenants = $slug ? Tenant::where('slug', $slug)->get() : Tenant::where('aktiv', true)->get();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        $now  = Carbon::now();
        $sent = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);
            $sent += self::resumeDue($now, $dryRun, fn (string $l) => $this->line($l), (string) $tenant->slug);
        }

        TenantService::reset();
        // Runs every minute — only log when something happened (or -v / dry-run).
        if ($sent > 0 || $dryRun || $this->output->isVerbose()) $this->info(sprintf('%s %d pause(s) auto-resumed.', $dryRun ? '[DRY-RUN] Would resume' : 'Resumed', $sent));
        return 0;
    }

    /**
     * Resume every due pause on the CURRENT tenant connection. Public so the
     * clock-in scenario matrix can run it against a throw-away database.
     */
    public static function resumeDue(Carbon $now, bool $dryRun = false, ?\Closure $report = null, string $tenantSlug = ''): int
    {
        $sent = 0;
        if (!Schema::connection('tenant')->hasTable('zeiterfassung')
            || !Schema::connection('tenant')->hasColumn('zeiterfassung', 'pause_start')
            || !Schema::connection('tenant')->hasTable('worker_notifications')) {
            return 0;
        }

        $rows = DB::connection('tenant')->select("
            SELECT z.id, z.einsatz_id, z.dienst_nr, z.pause_start, z.pause_total,
                   COALESCE(e.pause_minuten, 0) AS planned_pause,
                   e.datum, m.id AS m_id
            FROM zeiterfassung z
            INNER JOIN einsaetze e ON e.einsatz_id = z.einsatz_id
            INNER JOIN mitarbeiter m ON m.dienst_nr = z.dienst_nr
            WHERE z.status = 'pause' AND z.pause_start IS NOT NULL
        ");

        foreach ($rows as $r) {
            $planned = (int) $r->planned_pause;
            if ($planned <= 0) continue; // no planned pause — pause stays manual

            // The planned pause is a per-shift BUDGET: every earlier pause
            // segment already consumed part of it. A forgotten second pause
            // only gets whatever is left (30 planned − 5 used = 25 min).
            $remaining = max(0, $planned - (int) $r->pause_total);

            $pauseStart = Carbon::parse($r->pause_start);
            if ($now->lt($pauseStart->copy()->addMinutes($remaining))) continue;

            if ($dryRun) {
                if ($report) $report(sprintf('  [DRY] %-8s pause since %s (remaining %d of %d min)',
                    $r->dienst_nr, $r->pause_start, $remaining, $planned));
                continue;
            }

            // Claim the transition first: only if the row is still the
            // same pause we just read (worker may have resumed meanwhile).
            $claimed = DB::connection('tenant')->table('zeiterfassung')
                ->where('id', $r->id)
                ->where('status', 'pause')
                ->where('pause_start', $r->pause_start)
                ->update([
                    'status'      => 'aktiv',
                    'pause_total' => (int) $r->pause_total + $remaining,
                    'pause_start' => null,
                ]);
            if (!$claimed) continue;

            $title = 'Pause beendet — Schicht läuft weiter';
            $body  = "Deine geplante Pause ({$remaining} von {$planned} Min) ist vorbei. "
                   . 'Die Arbeitszeit läuft wieder — bitte nimm die Arbeit auf.';

            try {
                DB::connection('tenant')->table('worker_notifications')->insert([
                    'mitarbeiter_id' => $r->m_id,
                    'dienst_nr'      => $r->dienst_nr,
                    'type'           => 'pause.auto_ended',
                    'title'          => $title,
                    'body'           => $body,
                    'payload'        => json_encode(['einsatz_id' => $r->einsatz_id, 'datum' => $r->datum, 'action' => 'zeit'], JSON_UNESCAPED_UNICODE),
                    'gelesen'        => 0,
                    'created_at'     => now(),
                ]);
                WorkerPushService::push((string) $r->dienst_nr, $title, $body, 'zeit');
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('shifts:auto-resume-pauses notify failed', [
                    'tenant' => $tenantSlug, 'einsatz_id' => $r->einsatz_id, 'error' => $e->getMessage(),
                ]);
            }
        }
        return $sent;
    }
}
