<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tenant-scoped "something changed" signal — broadcast on
 * private-tenant.{id} so every logged-in colleague's open lists refresh
 * live without a full page reload.
 *
 * Payload is deliberately small: {area, hint}. The frontend maps `area`
 * ('dispo' | 'eingang' | 'chat' | 'workers' | 'dashboard' …) to its
 * registered refresh callbacks; `hint` may carry ids for surgical
 * updates later.
 */
class TenantDataChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public string $area,
        public array $hint = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenant.' . $this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'data.changed';
    }

    public function broadcastWith(): array
    {
        return ['area' => $this->area, 'hint' => $this->hint];
    }
}
