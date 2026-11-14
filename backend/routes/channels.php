<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels (Reverb / WebSocket)
|--------------------------------------------------------------------------
|
| Admin-panel realtime channels. The authenticated model is the Sanctum
| CentralUser. Channel names are deliberately generic:
|
|   private-user.{id}     → personal events (new notification rows)
|   private-tenant.{id}   → tenant-scoped data changes (dispo, eingang,
|                           chat, workers) — every logged-in colleague
|                           sees lists refresh live.
|
*/

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{tenantId}', function ($user, $tenantId) {
    return (int) $user->tenant_id === (int) $tenantId;
});
