<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WorkerPushService;
use Illuminate\Http\Request;

/**
 * Web-Push subscription management for the worker app.
 *
 * Endpoints (middleware: worker.auth + tenant.boundary):
 *  GET  /worker-app/push/vapid-key    public VAPID key for PushManager.subscribe
 *  POST /worker-app/push/subscribe    store this device's subscription
 *  POST /worker-app/push/unsubscribe  remove this device's subscription
 */
class WorkerAppPushController extends Controller
{
    public function vapidKey()
    {
        $key = WorkerPushService::publicKey();
        if (!$key) return response()->json(['error' => 'Push nicht konfiguriert'], 404);
        return response()->json(['key' => $key]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'provider'    => 'nullable|string|in:webpush,fcm,apns',
            'endpoint'    => 'required|string|max:2000',   // push URL (webpush) or device token (fcm)
            'keys.p256dh' => 'required_if:provider,webpush|nullable|string|max:255',
            'keys.auth'   => 'required_if:provider,webpush|nullable|string|max:100',
        ]);
        $provider = $data['provider'] ?? 'webpush';

        if (!WorkerPushService::ready()) {
            return response()->json(['error' => 'Push nicht verfügbar'], 409);
        }

        $dienstNr = $request->attributes->get('worker_dienst_nr');
        WorkerPushService::subscribe(
            $dienstNr,
            $data['endpoint'],
            $data['keys']['p256dh'] ?? null,
            $data['keys']['auth'] ?? null,
            $provider
        );

        return response()->json(['success' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);
        $dienstNr = $request->attributes->get('worker_dienst_nr');
        WorkerPushService::unsubscribe($dienstNr, $data['endpoint']);
        return response()->json(['success' => true]);
    }
}
