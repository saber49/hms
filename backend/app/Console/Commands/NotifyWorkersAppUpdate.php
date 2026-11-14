<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\WorkerPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notify every worker who has the app installed (i.e. has at least one
 * registered push subscription) that an app update is available.
 *
 * Iterates ALL tenants (or one via --tenant), picks the distinct dienst_nr
 * values from worker_push_subscriptions, and sends a push to each worker's
 * devices. Workers without the app have no subscription → not notified.
 *
 * Usage:
 *   php artisan push:app-update                    # every tenant
 *   php artisan push:app-update --tenant=ddr       # one tenant only
 */
class NotifyWorkersAppUpdate extends Command
{
    protected $signature = 'push:app-update
                            {--tenant= : Limit to one tenant slug}
                            {--dry-run : List recipients without sending}';

    protected $description = 'Push an app-update notice to all workers with the app installed';

    public function handle(): int
    {
        $tenantQuery = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))
            : Tenant::query();

        $title = 'Wichtiges App-Update';
        $body  = 'Bitte aktualisiere die Clood-X App auf die neueste Version — '
            . 'sie enthält wichtige Verbesserungen für Anrufe und Stabilität.';

        $totalWorkers = 0;
        $totalDevices = 0;

        foreach ($tenantQuery->get() as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
            } catch (\Throwable $e) {
                $this->warn("{$tenant->slug}: tenant switch failed ({$e->getMessage()}) — skipped");
                continue;
            }

            if (!Schema::connection('tenant')->hasTable('worker_push_subscriptions')) {
                continue;
            }

            $dienstNrs = DB::connection('tenant')->table('worker_push_subscriptions')
                ->distinct()->pluck('dienst_nr');

            if ($dienstNrs->isEmpty()) continue;

            $tenantWorkers = 0;
            $tenantDevices = 0;
            foreach ($dienstNrs as $dienstNr) {
                if ($this->option('dry-run')) {
                    $tenantWorkers++;
                    continue;
                }
                $sent = 0;
                try {
                    $sent = WorkerPushService::push(
                        (string) $dienstNr,
                        $title,
                        $body,
                        'feed',
                        ['type' => 'app.update']
                    );
                } catch (\Throwable $e) {
                    $this->warn("{$tenant->slug}/{$dienstNr}: push failed ({$e->getMessage()})");
                }
                if ($sent > 0) { $tenantWorkers++; $tenantDevices += $sent; }
            }

            $this->line("{$tenant->slug}: {$tenantWorkers} workers, {$tenantDevices} devices");
            $totalWorkers += $tenantWorkers;
            $totalDevices += $tenantDevices;
        }

        $this->info($this->option('dry-run')
            ? "Dry run: would notify {$totalWorkers} workers."
            : "Done: notified {$totalWorkers} workers on {$totalDevices} devices.");

        return 0;
    }
}
