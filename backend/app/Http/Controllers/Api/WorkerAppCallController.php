<?php

namespace App\Http\Controllers\Api;

use App\Events\CallSignal;
use App\Http\Controllers\Controller;
use App\Services\CallService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Worker-app side of office→worker WebRTC calls. Runs under worker.auth —
 * the worker is a mitarbeiter row, not a CentralUser, so private-channel
 * authorization is signed manually here instead of Broadcast::auth().
 *
 *   POST /worker-app/broadcasting/auth   pusher auth for private-worker.{t}.{w}
 *   GET  /worker-app/realtime/config     ws key + own channel name
 *   GET  /worker-app/calls/pending       ringing invite (push-tap entry)
 *   GET  /worker-app/calls/{id}          state + offer + queued admin ICE
 *   POST /worker-app/calls/{id}/answer   {sdp}
 *   POST /worker-app/calls/{id}/reject
 *   POST /worker-app/calls/{id}/ice      {candidate}
 *   POST /worker-app/calls/{id}/end
 *   GET  /worker-app/calls/ice-servers
 */
class WorkerAppCallController extends Controller
{
    /**
     * Pusher-style auth signature for `private-worker.{tenant}.{workerId}`.
     * worker.auth already resolved worker_id + tenant into request attrs —
     * the requested channel must match exactly, otherwise 403.
     */
    public function broadcastAuth(Request $request)
    {
        $workerId  = (int) $request->attributes->get('worker_id');
        $channel   = (string) $request->input('channel_name', '');
        $socketId  = (string) $request->input('socket_id', '');
        $expected  = 'private-' . CallService::workerChannel($workerId);

        if ($channel !== $expected || $socketId === '') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $key    = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');
        if (!$key || !$secret) {
            return response()->json(['error' => 'Realtime disabled'], 503);
        }

        return response()->json([
            'auth' => $key . ':' . hash_hmac('sha256', $socketId . ':' . $channel, $secret),
        ]);
    }

    /** WS bootstrap for the worker app: key + its private channel name. */
    public function realtimeConfig(Request $request)
    {
        $workerId = (int) $request->attributes->get('worker_id');
        $enabled  = config('broadcasting.default') === 'reverb'
            && !empty(config('broadcasting.connections.reverb.key'));

        return response()->json([
            'enabled' => $enabled,
            'key'     => config('broadcasting.connections.reverb.key'),
            'channel' => CallService::workerChannel($workerId),
        ]);
    }

    /** Ringing invite for this worker (opened via push, or WS re-sync). */
    public function pending(Request $request)
    {
        $call = CallService::pendingForWorker((int) $request->attributes->get('worker_id'));
        if (!$call) return response()->json(['call' => null]);
        return response()->json(['call' => CallService::publicView($call)]);
    }

    /** Call state + offer SDP + drain queued admin ICE (join/bootstrap path). */
    public function show(Request $request, string $id)
    {
        $call = $this->ownCall($request, $id);
        if (!$call) return response()->json(['error' => 'Anruf nicht gefunden'], 404);

        $view = CallService::publicView($call);
        $view['offer_sdp'] = $call['offer_sdp'] ?? null;
        $view['admin_ice'] = CallService::drainAdminIce($id);
        return response()->json(['call' => $view]);
    }

    /** Worker answered — store answer SDP + notify the office channel. */
    public function answer(Request $request, string $id)
    {
        $call = $this->ownCall($request, $id);
        if (!$call || $call['state'] !== 'ringing') {
            return response()->json(['error' => 'Anruf nicht gefunden'], 404);
        }
        $sdp = (string) $request->input('sdp', '');
        if (strlen($sdp) > 64 * 1024) return response()->json(['error' => 'sdp too large'], 422);
        // Request input is LF-normalized and edge-trimmed — rebuild real
        // CRLF endings plus the mandatory final terminator for WebRTC.
        $sdp = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $sdp));
        if ($sdp !== '') $sdp = rtrim($sdp) . "\r\n";

        CallService::update($id, ['state' => 'active', 'answer_sdp' => $sdp]);
        $this->toAdmin($call, 'answer', ['call_id' => $id, 'sdp' => $sdp]);
        return response()->json(['ok' => true]);
    }

    /** Worker declined the ringing call. */
    public function reject(Request $request, string $id)
    {
        $call = $this->ownCall($request, $id);
        if (!$call) return response()->json(['ok' => true]);
        $this->toAdmin($call, 'rejected', ['call_id' => $id]);
        CallService::end($id);
        return response()->json(['ok' => true]);
    }

    /** Relay worker→office ICE candidate (queued for the poll fallback too). */
    public function ice(Request $request, string $id)
    {
        $call = $this->ownCall($request, $id);
        if (!$call) return response()->json(['error' => 'Anruf nicht gefunden'], 404);
        $candidate = $request->input('candidate');
        if (!is_array($candidate)) return response()->json(['error' => 'candidate required'], 422);

        CallService::queueWorkerIce($id, $candidate);
        $this->toAdmin($call, 'ice', ['call_id' => $id, 'candidate' => $candidate]);
        return response()->json(['ok' => true]);
    }

    /** Worker hangs up. */
    public function end(Request $request, string $id)
    {
        $call = $this->ownCall($request, $id);
        if (!$call) return response()->json(['ok' => true]);
        $this->toAdmin($call, 'end', ['call_id' => $id]);
        CallService::end($id);
        return response()->json(['ok' => true]);
    }

    public function iceServers(Request $request)
    {
        return response()->json(
            CallService::iceServers('worker' . (int) $request->attributes->get('worker_id'))
        );
    }

    private function ownCall(Request $request, string $id): ?array
    {
        $call = CallService::get($id);
        if (!$call || (int) $call['worker_id'] !== (int) $request->attributes->get('worker_id')) {
            return null;
        }
        return $call;
    }

    private function toAdmin(array $call, string $type, array $payload): void
    {
        try {
            broadcast(new CallSignal(
                CallService::adminChannel((int) $call['admin_id']), $type, $payload
            ));
        } catch (\Throwable $e) {
            Log::debug("call.{$type} broadcast skipped", ['error' => $e->getMessage()]);
        }
    }
}
