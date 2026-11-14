<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tiny helper around the `user_audit_log` table.
 *
 * Usage from a controller:
 *     UserAuditLogger::log($request, 'update', $targetUser, $before, $after);
 *
 * The helper:
 *   - resolves actor + tenant_id from $request->user(),
 *   - drops password / token fields so we never persist secrets,
 *   - swallows exceptions (logging failure must never break the request).
 */
final class UserAuditLogger
{
    /** Fields we refuse to ever store. */
    private const REDACTED_KEYS = ['password', 'password_hash', 'remember_token', 'verification_token'];

    /** Cap snapshot size — 4KB per side is plenty for user records. */
    private const MAX_JSON_BYTES = 4096;

    /**
     * Append a row. $target may be an object, array, or just an id.
     * $before / $after should already be limited to the keys that changed.
     */
    public static function log(
        ?Request $request,
        string $action,
        $target = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        try {
            $actor = $request?->user();
            $tenantId = $actor?->tenant_id ?? (is_object($target) ? ($target->tenant_id ?? null) : null);
            if (!$tenantId) return; // refuse to log without tenant scope

            DB::connection('mysql')->table('user_audit_log')->insert([
                'tenant_id'      => (int) $tenantId,
                'actor_id'       => $actor?->id,
                'actor_email'    => $actor?->email,
                'target_id'      => self::extractId($target),
                'target_username'=> self::extractUsername($target),
                'action'         => substr($action, 0, 40),
                'before'         => self::encode($before),
                'after'          => self::encode($after),
                'ip'             => $request?->ip(),
                'user_agent'     => $request ? substr((string) $request->userAgent(), 0, 255) : null,
                'created_at'     => now(),
            ]);
        } catch (Throwable $e) {
            // Never let audit logging break the request — but do leave a trace.
            Log::warning('UserAuditLogger failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private static function extractId($target): ?int
    {
        if (is_object($target) && isset($target->id)) return (int) $target->id;
        if (is_array($target)  && isset($target['id'])) return (int) $target['id'];
        if (is_int($target))     return $target;
        if (is_numeric($target)) return (int) $target;
        return null;
    }

    private static function extractUsername($target): ?string
    {
        if (is_object($target)) {
            return $target->username ?? $target->name ?? null;
        }
        if (is_array($target)) {
            return $target['username'] ?? $target['name'] ?? null;
        }
        return null;
    }

    /** Strip secrets, JSON-encode, hard-cap size. */
    private static function encode(?array $payload): ?string
    {
        if ($payload === null) return null;
        foreach (self::REDACTED_KEYS as $k) {
            if (array_key_exists($k, $payload)) {
                $payload[$k] = '[redacted]';
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return null;
        if (strlen($json) > self::MAX_JSON_BYTES) {
            // Fall back to a tiny marker rather than silently truncating malformed JSON.
            return json_encode(['_truncated' => true, '_size' => strlen($json)]);
        }
        return $json;
    }
}
