<?php

namespace App\Jobs;

use App\Services\CentralPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued device-push to one central_user. Same rationale as
 * SendWorkerPush: device pushes are slow HTTP round-trips (FCM/APNs/
 * WebPush, up to 10s each) and must never block the API request that
 * triggered the notification.
 *
 * No tenant re-switch needed — the token table lives on the central
 * `mysql` connection which the queue worker always has.
 */
class SendCentralPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Push delivery is best-effort — never retry-storm a dead endpoint. */
    public int $tries = 1;

    public function __construct(
        public int $centralUserId,
        public string $title,
        public string $body,
        public string $link = '#',
    ) {
    }

    public function handle(): void
    {
        CentralPushService::push($this->centralUserId, $this->title, $this->body, $this->link);
    }
}
