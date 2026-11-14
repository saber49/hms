<?php

namespace App\Console\Commands;

use App\Jobs\SendWorkerPush;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\WorkerPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Broadcast an "update the app" push notification to every registered
 * worker device across all active tenants.
 *
 * Use this after publishing a new app version to Google Play / App Store so
 * workers know they should update. The push arrives as a real system
 * notification (FCM on Android, APNs on iOS, Web-Push on PWA) even when the
 * app is closed, and a row is inserted into worker_notifications so it also
 * surfaces inside the app's notification feed.
 *
 * Usage:
 *   php artisan worker:broadcast-app-update              # all tenants
 *   php artisan worker:broadcast-app-update --tenant=hms-gruppe-gmbh-co-kg
 *   php artisan worker:broadcast-app-update --dry-run    # report only
 *   php artisan worker:broadcast-app-update --title="..." --body="..."
 */
class BroadcastAppUpdate extends Command
{
    protected $signature = 'worker:broadcast-app-update
                            {--tenant= : Limit to a single tenant slug}
                            {--dry-run : Report only; do not send pushes or insert notifications}
                            {--title= : Custom notification title}
                            {--body= : Custom notification body}';

    protected $description = 'Broadcast an "update the app" push notification to all worker devices across all tenants';

    /** Default message shown to workers. */
    private const DEFAULT_TITLE = 'App-Update verfügbar';
    private const DEFAULT_BODY  = 'Eine neue Version der Clood-X App ist verfügbar. Bitte aktualisieren Sie die App im Google Play Store / Apple App Store, um die neuesten Funktionen und Fehlerbehebungen zu erhalten.';

    public function handle(): int
    {
        $slug   = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');
        $title  = (string) ($this->option('title') ?? self::DEFAULT_TITLE);
        $body   = (string) ($this->option('body')  ?? self::DEFAULT_BODY);

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        $this->info(sprintf(
            '%s broadcast "app update" to workers across %d tenant(s)…',
            $dryRun ? '[DRY-RUN] Checking' : 'Broadcasting',
            $tenants->count(),
        ));
        $this->line(sprintf('  Title: %s', $title));
        $this->line(sprintf('  Body:  %s', mb_substr($body, 0, 80) . (mb_strlen($body) > 80 ? '…' : '')));
        $this->newLine();

        $grandWorkers  = 0;
        $grandDevices  = 0;
        $grandPushed   = 0;
        $tenantsHit    = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            // Skip tenants that don't have the push infrastructure set up.
            if (!Schema::connection('tenant')->hasTable('worker_push_subscriptions')) {
                $this->line(sprintf('  %-30s (no push table)', substr($tenant->slug, 0, 30)));
                continue;
            }

            if (!WorkerPushService::ready()) {
                $this->line(sprintf('  %-30s (push not ready)', substr($tenant->slug, 0, 30)));
                continue;
            }

            // Get every unique worker that has at least one registered device.
            $workers = DB::connection('tenant')->table('worker_push_subscriptions')
                ->select('dienst_nr', DB::raw('COUNT(*) as devices'))
                ->groupBy('dienst_nr')
                ->get();

            if ($workers->isEmpty()) {
                $this->line(sprintf('  %-30s 0 workers', substr($tenant->slug, 0, 30)));
                continue;
            }

            $workerCount = $workers->count();
            $deviceCount = (int) $workers->sum('devices');
            $grandWorkers += $workerCount;
            $grandDevices += $deviceCount;
            $tenantsHit++;

            $this->line(sprintf(
                '  %-30s %d worker(s), %d device(s)',
                substr($tenant->slug, 0, 30),
                $workerCount,
                $deviceCount,
            ));

            if ($dryRun) {
                continue;
            }

            // Insert a worker_notifications row for each worker AND dispatch
            // a queued device push. The queue worker handles the slow FCM /
            // APNs / Web-Push HTTP round-trips so this command returns fast.
            foreach ($workers as $w) {
                $dienstNr = (string) $w->dienst_nr;

                // Resolve the mitarbeiter row for the notification feed row.
                $ma = DB::connection('tenant')->table('mitarbeiter')
                    ->where('dienst_nr', $dienstNr)
                    ->first();

                if ($ma) {
                    try {
                        DB::connection('tenant')->table('worker_notifications')->insert([
                            'mitarbeiter_id' => $ma->id,
                            'dienst_nr'      => $dienstNr,
                            'type'           => 'app.update',
                            'title'          => $title,
                            'body'           => $body,
                            'payload'        => json_encode([
                                'kind' => 'app_update',
                            ], JSON_UNESCAPED_UNICODE),
                            'gelesen'        => 0,
                            'created_at'     => now(),
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('BroadcastAppUpdate: worker_notifications insert failed', [
                            'tenant'    => $tenant->slug,
                            'dienst_nr' => $dienstNr,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                // Queued device push — arrives even when the app is closed.
                SendWorkerPush::dispatch((int) $tenant->id, $dienstNr, $title, $body, 'feed');
                $grandPushed++;
            }
        }

        TenantService::reset();

        $this->newLine();
        $this->info(sprintf(
            '%s %d worker(s) with %d device(s) across %d tenant(s).%s',
            $dryRun ? '[DRY-RUN] Found' : 'Broadcast to',
            $grandWorkers,
            $grandDevices,
            $tenantsHit,
            $dryRun ? '' : " Dispatched {$grandPushed} push job(s) to the queue.",
        ));

        if (!$dryRun && $grandPushed > 0) {
            $this->line('  Pushes are processed asynchronously by the hms-queue worker.');
            $this->line('  Check storage/logs/laravel.log for any delivery failures.');
        }

        return 0;
    }
}
