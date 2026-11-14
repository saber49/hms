<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\WorkerPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued device-push to one worker. Device pushes are slow HTTP round-trips
 * (FCM/APNs/WebPush, up to 10s timeout each) — sending them inline made bulk
 * actions like "Woche planen" block for many seconds. This job moves the
 * sending to the redis queue worker so API responses return immediately.
 *
 * The tenant connection is NOT serialized with the job, so the tenant id is
 * captured explicitly and re-switched inside handle().
 */
class SendWorkerPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Push delivery is best-effort — never retry-storm a dead endpoint. */
    public int $tries = 1;

    public function __construct(
        public int $tenantId,
        public string $dienstNr,
        public string $title,
        public string $body,
        public string $tab = 'feed',
    ) {
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (!$tenant) return;

        TenantService::switchToTenant($tenant);
        try {
            WorkerPushService::push($this->dienstNr, $this->title, $this->body, $this->tab);
        } finally {
            TenantService::reset();
        }
    }
}
