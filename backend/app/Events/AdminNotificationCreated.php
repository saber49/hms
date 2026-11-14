<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new in-app notification row was stored for one central_user.
 * Broadcast on their private user channel so the bell badge, dropdown,
 * in-app toast and desktop OS toast update instantly — the 30 s poll
 * remains as a silent fallback.
 *
 * ShouldBroadcastNow: delivered synchronously to Reverb — the insert is
 * already committed, and a same-machine HTTP hop is cheap. Any failure
 * is swallowed by the caller (Realtime helper).
 */
class AdminNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public array $notification, // {id,type,title,body,link,created_at}
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
