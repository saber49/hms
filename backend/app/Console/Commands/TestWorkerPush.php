<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\WorkerPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fire a test Web-Push to a worker's registered devices — verifies the whole
 * chain (VAPID keys, subscription, service worker) end to end.
 *
 * Usage:
 *   php artisan push:test --tenant=ddr --dienst-nr=MA013
 *   php artisan push:test --tenant=ddr            # list registered devices
 */
class TestWorkerPush extends Command
{
    protected $signature = 'push:test
                            {--tenant= : Tenant slug (required)}
                            {--dienst-nr= : Worker to push to; omit to just list subscriptions}';

    protected $description = 'Send a test Web-Push notification to a worker';

    public function handle(): int
    {
        $slug = $this->option('tenant');
        if (!$slug) { $this->error('--tenant= is required'); return 1; }

        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) { $this->error("Tenant '{$slug}' not found."); return 1; }

        TenantService::switchToTenant($tenant);

        if (!WorkerPushService::ready()) {
            $this->error('Push not ready: VAPID keys missing or worker_push_subscriptions table absent.');
            return 1;
        }

        $subs = DB::connection('tenant')->table('worker_push_subscriptions')
            ->select('dienst_nr', DB::raw('COUNT(*) as devices'))
            ->groupBy('dienst_nr')->get();

        if ($subs->isEmpty()) {
            $this->warn('No registered devices in this tenant yet.');
            $this->line('A device registers automatically when the worker opens the app and grants notification permission.');
            return 0;
        }

        $this->table(['dienst_nr', 'devices'], $subs->map(fn($s) => [(string) $s->dienst_nr, (int) $s->devices])->all());

        $dienstNr = $this->option('dienst-nr');
        if (!$dienstNr) return 0;

        $sent = WorkerPushService::push(
            $dienstNr,
            'Test-Benachrichtigung',
            'Web-Push funktioniert! Diese Nachricht kam bei geschlossener App an. 🎉',
            'feed'
        );

        $this->info("Push accepted for {$sent} device(s) of {$dienstNr}.");
        TenantService::reset();
        return 0;
    }
}
