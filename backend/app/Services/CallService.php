<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ephemeral office→worker WebRTC voice-call sessions.
 *
 * Signaling rides on Reverb: office events land on the worker's private
 * channel `private-worker.{tenant}.{workerId}`, worker responses land on
 * `private-user.{centralUserId}`. This store only keeps the rendezvous
 * state (ringing/active + queued SDP/ICE for late joiners) in Cache —
 * a call leaves no DB rows; missed calls surface via push/chat.
 *
 * Tenant isolation: every key is prefixed with the tenant id, so calls
 * can never cross company boundaries even under Octane workers.
 */
class CallService
{
    private const RING_TTL = 90;    // seconds an invite keeps ringing
    private const CALL_TTL = 7200;  // hard cap for a ringing/active session

    private static function tenantId(): int
    {
        return (int) (TenantService::current()->id ?? 0);
    }

    private static function key(string $id): string
    {
        return 'call:' . self::tenantId() . ':' . $id;
    }

    private static function workerIndex(int $workerId): string
    {
        return 'call_w:' . self::tenantId() . ':' . $workerId;
    }

    private static function adminIndex(int $adminId): string
    {
        return 'call_a:' . self::tenantId() . ':' . $adminId;
    }

    /**
     * Create a ringing session for office user → worker.
     * $worker is a mitarbeiter row (tenant connection).
     */
    public static function start(object $adminUser, object $worker): array
    {
        $call = [
            'id'          => 'call-' . Str::lower(Str::random(16)),
            'tenant_id'   => self::tenantId(),
            'state'       => 'ringing',            // ringing | active
            'admin_id'    => (int) $adminUser->id,
            'admin_name'  => trim(($adminUser->vorname ?? '') . ' ' . ($adminUser->nachname ?? ''))
                ?: ($adminUser->name ?? 'Disposition'),
            'worker_id'   => (int) $worker->id,
            'dienst_nr'   => (string) ($worker->dienst_nr ?? ''),
            'worker_name' => trim(($worker->vorname ?? '') . ' ' . ($worker->nachname ?? '')),
            'offer_sdp'   => null,
            'answer_sdp'  => null,
            'admin_ice'   => [],   // candidates queued before the worker joins
            'worker_ice'  => [],   // candidates queued before the office polls/WS
            'created_at'  => now()->toIso8601String(),
        ];
        Cache::put(self::key($call['id']), $call, self::CALL_TTL);
        // Indexes live as long as the call itself — a 90s index used to let
        // a second invite slip in while a call was still ACTIVE.
        Cache::put(self::workerIndex($call['worker_id']), $call['id'], self::CALL_TTL);
        Cache::put(self::adminIndex($call['admin_id']), $call['id'], self::CALL_TTL);
        return $call;
    }

    public static function get(string $id): ?array
    {
        $call = Cache::get(self::key($id));
        return is_array($call) ? $call : null;
    }

    public static function update(string $id, array $patch): ?array
    {
        $call = self::get($id);
        if (!$call) return null;
        $call = array_merge($call, $patch);
        Cache::put(self::key($id), $call, self::CALL_TTL);
        return $call;
    }

    /** Worker pulled offer+queued candidates — drain the ICE queue. */
    public static function drainAdminIce(string $id): array
    {
        $call = self::get($id);
        if (!$call) return [];
        $ice = $call['admin_ice'] ?? [];
        if ($ice) {
            $call['admin_ice'] = [];
            Cache::put(self::key($id), $call, self::CALL_TTL);
        }
        return $ice;
    }

    public static function queueAdminIce(string $id, array $candidate): void
    {
        $call = self::get($id);
        if (!$call) return;
        $call['admin_ice'][] = $candidate;
        Cache::put(self::key($id), $call, self::CALL_TTL);
    }

    public static function queueWorkerIce(string $id, array $candidate): void
    {
        $call = self::get($id);
        if (!$call) return;
        $call['worker_ice'][] = $candidate;
        Cache::put(self::key($id), $call, self::CALL_TTL);
    }

    /** Office drained its queued worker candidates (poll fallback). */
    public static function drainWorkerIce(string $id): array
    {
        $call = self::get($id);
        if (!$call) return [];
        $ice = $call['worker_ice'] ?? [];
        if ($ice) {
            $call['worker_ice'] = [];
            Cache::put(self::key($id), $call, self::CALL_TTL);
        }
        return $ice;
    }

    public static function end(string $id): ?array
    {
        $call = self::get($id);
        if (!$call) return null;
        Cache::forget(self::key($id));
        Cache::forget(self::workerIndex((int) $call['worker_id']));
        Cache::forget(self::adminIndex((int) $call['admin_id']));
        return $call;
    }

    /**
     * The office hung up (or its 45 s no-answer timer fired) while the
     * invite was still ringing: leave a "missed call" line in the worker's
     * chat (counts as unread there) + a notification and a push.
     */
    public static function recordMissed(array $call): void
    {
        try {
            $dienstNr = (string) ($call['dienst_nr'] ?? '');
            if ($dienstNr === '' || ($call['state'] ?? '') !== 'ringing') return;
            $time = now()->timezone('Europe/Berlin')->format('H:i');
            $from = trim((string) ($call['admin_name'] ?? '')) ?: 'Büro';
            $text = "📞 Verpasster Anruf von {$from} um {$time} Uhr";

            \Illuminate\Support\Facades\DB::connection('tenant')->table('mitarbeiter_nachrichten')->insert([
                'dienst_nr'  => $dienstNr,
                'richtung'   => 'admin',
                'nachricht'  => $text,
                'gelesen'    => 0,
            ]);
            \Illuminate\Support\Facades\DB::connection('tenant')->table('worker_notifications')->insert([
                'mitarbeiter_id' => (int) $call['worker_id'],
                'dienst_nr'      => $dienstNr,
                'type'           => 'call.missed',
                'title'          => 'Verpasster Anruf',
                'body'           => "{$from} hat um {$time} Uhr angerufen.",
                'payload'        => json_encode([], JSON_UNESCAPED_UNICODE),
                'gelesen'        => 0,
                'created_at'     => now(),
            ]);
            \App\Jobs\SendWorkerPush::dispatch(self::tenantId(), $dienstNr, 'Verpasster Anruf', "{$from} hat um {$time} Uhr angerufen.", 'chat');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('call.missed record failed: ' . $e->getMessage());
        }
    }

    /** Ringing call for a worker (app opened from the invite push). */
    public static function pendingForWorker(int $workerId): ?array
    {
        $id = Cache::get(self::workerIndex($workerId));
        if (!$id) return null;
        $call = self::get($id);
        if (!$call || $call['state'] !== 'ringing') return null;
        return $call;
    }

    /** Ringing OR active call for a worker — used as the busy check so a
     *  second invite can never land while the worker is in a call. */
    public static function busyForWorker(int $workerId): ?array
    {
        $id = Cache::get(self::workerIndex($workerId));
        if (!$id) return null;
        $call = self::get($id);
        if (!$call || !in_array($call['state'], ['ringing', 'active'], true)) return null;
        return $call;
    }

    /** The office user's open call (ringing/active), if any. A caller that
     *  refreshed the page mid-call re-starts cleanly by ending this first. */
    public static function activeForAdmin(int $adminId): ?array
    {
        $id = Cache::get(self::adminIndex($adminId));
        if (!$id) return null;
        $call = self::get($id);
        if (!$call || !in_array($call['state'], ['ringing', 'active'], true)) return null;
        return $call;
    }

    /** Private-channel suffix a worker subscribes to: worker.{tenant}.{worker}. */
    public static function workerChannel(int $workerId): string
    {
        return 'worker.' . self::tenantId() . '.' . $workerId;
    }

    /** Private-channel suffix for the calling office user. */
    public static function adminChannel(int $adminId): string
    {
        return 'user.' . $adminId;
    }

    /**
     * Time-limited TURN credentials — coturn `use-auth-secret` (REST API):
     * username "<expiry>:<label>", credential base64(hmac_sha1(secret)).
     * Host/port/secret come from config (cached under Octane), never env().
     */
    public static function iceServers(string $label = 'user'): array
    {
        $servers = [];
        $host   = config('services.turn.host');
        $port   = (int) (config('services.turn.port') ?: 3478);
        $secret = config('services.turn.secret');

        if ($host) {
            $servers[] = ['urls' => ["stun:{$host}:{$port}"]];
            if ($secret) {
                $username = (time() + 86400) . ':' . $label;
                $servers[] = [
                    'urls'       => ["turn:{$host}:{$port}?transport=udp", "turn:{$host}:{$port}?transport=tcp"],
                    'username'   => $username,
                    'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
                ];
            }
        }
        return ['iceServers' => $servers];
    }

    /** Fields safe to hand to either call party. */
    public static function publicView(array $call): array
    {
        return [
            'id'          => $call['id'],
            'state'       => $call['state'],
            'admin_name'  => $call['admin_name'],
            'worker_name' => $call['worker_name'],
            'dienst_nr'   => $call['dienst_nr'],
            'created_at'  => $call['created_at'],
        ];
    }
}
