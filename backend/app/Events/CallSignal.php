<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Generic WebRTC signaling event. Broadcast as 'call.<type>' on a private
 * channel chosen by the caller:
 *
 *   worker.{tenant}.{workerId}  → office → worker (invite/offer/ice/cancel/end)
 *   user.{centralUserId}        → worker → office (answer/ice/rejected/end)
 *
 * ShouldBroadcastNow so signaling never waits for a queue worker.
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $channelName,
        public string $type,
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channelName)];
    }

    public function broadcastAs(): string
    {
        return 'call.' . $this->type;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
