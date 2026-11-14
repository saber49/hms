<?php

namespace App\Helpers;

use App\Events\AdminNotificationCreated;
use App\Events\TenantDataChanged;
use Illuminate\Support\Facades\Log;

/**
 * Thin fire-and-forget wrapper around broadcast(). WebSocket delivery is
 * strictly best-effort: if Reverb is down or broadcasting is disabled,
 * the app keeps working on its existing polling — a realtime failure must
 * never break the request that triggered it.
 */
class Realtime
{
    /** Push a fresh notification row to one user's private channel. */
    public static function notification(int $userId, array $notification): void
    {
        try {
            broadcast(new AdminNotificationCreated($userId, $notification));
        } catch (\Throwable $e) {
            Log::debug('Realtime::notification skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Signal that tenant data changed. $tenantId is resolved from the
     * current tenant context when omitted (controllers run under
     * ResolveTenant, so TenantService::current() is populated).
     */
    public static function changed(string $area, array $hint = [], ?int $tenantId = null): void
    {
        try {
            if ($tenantId === null) {
                $tenantId = \App\Services\TenantService::current()->id ?? null;
            }
            if (!$tenantId) return;
            broadcast(new TenantDataChanged((int) $tenantId, $area, $hint));
        } catch (\Throwable $e) {
            Log::debug('Realtime::changed skipped', ['area' => $area, 'error' => $e->getMessage()]);
        }
    }
}
