<?php

namespace App\Http\Controllers\Api;

use App\Events\CallSignal;
use App\Http\Controllers\Controller;
use App\Services\CallService;
use App\Services\WorkerPushService;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Office → worker WebRTC voice calls. This controller is the admin-panel
 * side: start a call, relay offer/ICE, cancel/end. The worker-app side
 * lives in WorkerAppCallController. Media itself flows peer-to-peer (or
 * via the self-hosted coturn relay) — the server only relays signaling.
 *
 * Channel map:
 *   → worker  private-worker.{tenant}.{workerId}  (invite/offer/ice/cancel/end)
 *   → office  private-user.{centralUserId}        (answer/ice/rejected/end)
 */
class CallController extends Controller
{
    /** POST /api/calls {worker_id | dienst_nr} → ringing session + push invite. */
    public function start(Request $request)
    {
        $workerQuery = DB::connection('tenant')->table('mitarbeiter');
        if ($request->filled('worker_id')) {
            $workerQuery->where('id', (int) $request->input('worker_id'));
        } elseif ($request->filled('dienst_nr')) {
            $workerQuery->where('dienst_nr', (string) $request->input('dienst_nr'));
        } else {
            return response()->json(['error' => 'worker_id oder dienst_nr erforderlich'], 422);
        }
        $worker = $workerQuery->first();
        $user = $request->user();
        if (!$worker || !($worker->aktiv ?? 0) || !($worker->app_zugang ?? 0)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden oder ohne App-Zugang'], 404);
        }
        if (!BereichScope::allowsWorker($user, (string) $worker->dienst_nr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // The caller's own leftover call (e.g. he refreshed mid-call and his
        // UI lost the session) is ended first — "call again" is idempotent,
        // never two sessions for one office user.
        $leftover = CallService::activeForAdmin((int) $user->id);
        if ($leftover && ($leftover['id'] ?? null)) {
            CallService::end($leftover['id']);
            try {
                broadcast(new CallSignal(
                    CallService::workerChannel((int) $leftover['worker_id']),
                    'end',
                    ['call_id' => $leftover['id']]
                ));
            } catch (\Throwable $e) {
                Log::debug('call.end cleanup broadcast skipped', ['error' => $e->getMessage()]);
            }
        }

        // One ringing/active call per worker at a time — second caller gets busy.
        if (CallService::busyForWorker((int) $worker->id)) {
            return response()->json(['error' => 'Mitarbeiter wird bereits angerufen', 'busy' => true], 409);
        }

        $call = CallService::start($user, $worker);

        // The office creates its offer BEFORE ringing: callers send {sdp}
        // upfront so the invite can never reach a worker before offer_sdp
        // exists (early "Annehmen" used to race the separate offer POST).
        // Symfony normalizes \r\n → \n in request input — WebRTC needs real
        // CRLF, so the stored SDP is rebuilt to spec on every write path.
        $sdp = self::normSdp((string) $request->input('sdp', ''));
        if (strlen($sdp) > 64 * 1024) {
            CallService::end($call['id']);
            return response()->json(['error' => 'sdp too large'], 422);
        }
        if ($sdp !== '') {
            CallService::update($call['id'], ['offer_sdp' => $sdp]);
            $call['offer_sdp'] = $sdp;
        }

        try {
            broadcast(new CallSignal(
                CallService::workerChannel((int) $worker->id),
                'invite',
                ['call_id' => $call['id'], 'caller' => $call['admin_name']]
            ));
            if ($sdp !== '') {
                broadcast(new CallSignal(
                    CallService::workerChannel((int) $worker->id),
                    'offer',
                    ['call_id' => $call['id'], 'sdp' => $sdp]
                ));
            }
        } catch (\Throwable $e) {
            Log::debug('call.invite broadcast skipped', ['error' => $e->getMessage()]);
        }

        // Wake the device even when the app is fully closed. tab=feed is the
        // graceful fallback — the call screen overlays on top via checkPending,
        // and a stale/expired tap lands on the feed, not a dead tab.
        // Best-effort ONLY: a push failure must never 500 the call start —
        // the session + WS invite already went out (a past APNs deprecation
        // crash orphaned exactly that: worker rang while the office was dead).
        try {
            WorkerPushService::push(
                (string) $worker->dienst_nr,
                'Eingehender Anruf',
                ($call['admin_name'] ?: 'Clood-X') . ' ruft an',
                'feed',
                ['type' => 'call.invite', 'call_id' => $call['id'], 'caller' => (string) $call['admin_name']]
            );
        } catch (\Throwable $e) {
            Log::warning('call.invite push failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['call' => CallService::publicView($call)]);
    }

    /** GET /api/calls/ice-servers — fresh TURN credentials. */
    public function iceServers(Request $request)
    {
        return response()->json(CallService::iceServers('user' . $request->user()->id));
    }

    /** GET /api/calls/{id} — caller polls state (WS-fallback). */
    public function show(Request $request, string $id)
    {
        $call = $this->ownedCall($request, $id);
        if (!$call) return response()->json(['error' => 'Anruf nicht gefunden'], 404);
        $view = CallService::publicView($call);
        // Worker→office ICE candidates are drained here on poll.
        $view['worker_ice'] = CallService::drainWorkerIce($id);
        $view['answer_sdp'] = $call['answer_sdp'] ?? null;
        return response()->json(['call' => $view]);
    }

    /** POST /api/calls/{id}/offer {sdp} — store + relay SDP to the worker. */
    public function offer(Request $request, string $id)
    {
        $call = $this->ownedCall($request, $id);
        if (!$call) return response()->json(['error' => 'Anruf nicht gefunden'], 404);
        $sdp = self::normSdp((string) $request->input('sdp', ''));
        if (strlen($sdp) > 64 * 1024) return response()->json(['error' => 'sdp too large'], 422);

        CallService::update($id, ['offer_sdp' => $sdp]);
        $this->toWorker($call, 'offer', ['call_id' => $id, 'sdp' => $sdp]);
        return response()->json(['ok' => true]);
    }

    /** POST /api/calls/{id}/ice {candidate} — relay office→worker. */
    public function ice(Request $request, string $id)
    {
        $call = $this->ownedCall($request, $id);
        if (!$call) return response()->json(['error' => 'Anruf nicht gefunden'], 404);
        $candidate = $request->input('candidate');
        if (!is_array($candidate)) return response()->json(['error' => 'candidate required'], 422);

        CallService::queueAdminIce($id, $candidate);
        $this->toWorker($call, 'ice', ['call_id' => $id, 'candidate' => $candidate]);
        return response()->json(['ok' => true]);
    }

    /** POST /api/calls/{id}/cancel — caller hangs up before answer. */
    public function cancel(Request $request, string $id)
    {
        $call = $this->ownedCall($request, $id);
        if (!$call) return response()->json(['ok' => true]);
        $this->toWorker($call, 'cancel', ['call_id' => $id]);
        CallService::end($id);
        CallService::recordMissed($call);
        return response()->json(['ok' => true]);
    }

    /** POST /api/calls/{id}/end — caller ends an established call. */
    public function end(Request $request, string $id)
    {
        $call = $this->ownedCall($request, $id);
        if (!$call) return response()->json(['ok' => true]);
        $this->toWorker($call, 'end', ['call_id' => $id]);
        CallService::end($id);
        return response()->json(['ok' => true]);
    }

    /**
     * SDP line endings must be CRLF — request input arrives LF-normalized,
     * and TrimStrings also eats the final line terminator. Rebuild spec
     * endings plus the mandatory trailing CRLF before storing.
     */
    private static function normSdp(string $sdp): string
    {
        $sdp = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $sdp));
        return $sdp === '' ? $sdp : rtrim($sdp) . "\r\n";
    }

    private function ownedCall(Request $request, string $id): ?array
    {
        $call = CallService::get($id);
        if (!$call || (int) $call['admin_id'] !== (int) $request->user()->id) return null;
        return $call;
    }

    private function toWorker(array $call, string $type, array $payload): void
    {
        try {
            broadcast(new CallSignal(
                CallService::workerChannel((int) $call['worker_id']), $type, $payload
            ));
        } catch (\Throwable $e) {
            Log::debug("call.{$type} broadcast skipped", ['error' => $e->getMessage()]);
        }
    }
}
