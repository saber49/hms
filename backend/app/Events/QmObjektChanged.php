<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * QM live signal for ONE Objekt — broadcast on private-qm-objekt.{tenant}.{obj}
 * so the worker app, the Objekt-Terminal and the Kundenportal refresh the
 * open view without a reload. Payload: {bereich} only (no data — the
 * clients re-fetch through their own scoped endpoints).
 */
class QmObjektChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $tenantId, public string $objId, public string $bereich) {}

    public static function kanal(int $tenantId, string $objId): string
    {
        return 'qm-objekt.' . $tenantId . '.' . preg_replace('/[^A-Za-z0-9_-]/', '', $objId);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::kanal($this->tenantId, $this->objId))];
    }

    public function broadcastAs(): string
    {
        return 'qm.changed';
    }

    public function broadcastWith(): array
    {
        return ['bereich' => $this->bereich];
    }
}
