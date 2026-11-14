<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Direct APNs (Apple Push Notification service) sender for the native iOS
 * worker app — no Firebase involved. Signs ES256 provider tokens with the
 * APNs auth key (.p8) and talks HTTP/2 to api.push.apple.com.
 *
 * Required env: APNS_KEY_PATH, APNS_KEY_ID, APNS_TEAM_ID
 * (bundle id defaults to com.cloodx.worker).
 */
class ApnsService
{
    private const HOST = 'https://api.push.apple.com';
    // Development/TestFlight-debug builds hold SANDBOX device tokens —
    // Apple rejects them on the production host with 400 BadDeviceToken.
    // We retry such tokens against the sandbox host instead of pruning a
    // perfectly valid registration.
    private const HOST_SANDBOX = 'https://api.sandbox.push.apple.com';

    public static function ready(): bool
    {
        $c = config('services.apns');
        return !empty($c['key_id']) && !empty($c['team_id'])
            && !empty($c['key_path']) && is_readable($c['key_path']);
    }

    /** Provider token (ES256 JWT), cached — Apple allows reuse for up to 1h. */
    private static function providerToken(): ?string
    {
        return Cache::remember('apns_provider_token', 2700, function () {
            $c = config('services.apns');
            $key = file_get_contents($c['key_path']);
            return JWT::encode(
                ['iss' => $c['team_id'], 'iat' => time()],
                $key,
                'ES256',
                $c['key_id']
            );
        });
    }

    /**
     * Send an alert push to one APNs device token.
     *
     * @return string 'ok' | 'unregistered' (prune the token) | 'error'
     */
    public static function send(string $deviceToken, string $title, string $body, string $tab = 'feed', array $data = []): string
    {
        try {
            if (!self::ready()) return 'error';
            $jwt = self::providerToken();
            if (!$jwt) return 'error';

            $payload = json_encode(array_merge([
                'aps' => [
                    'alert' => ['title' => $title, 'body' => mb_substr($body, 0, 240)],
                    'sound' => 'default',
                    'badge' => 1,
                ],
                'tab' => $tab,
            ], $data), JSON_UNESCAPED_UNICODE);

            [$status, $response] = self::post(self::HOST, $deviceToken, $jwt, $payload);
            // Token issued by a dev/sandbox build → Apple says BadDeviceToken
            // (400) on the prod host. BadEnvironmentKeyInToken (403) means the
            // .p8 auth key itself is sandbox-scoped — same remedy: retry the
            // sandbox host before giving up.
            $envMismatch = ($status === 400 && str_contains((string) $response, 'BadDeviceToken'))
                || ($status === 403 && str_contains((string) $response, 'BadEnvironmentKeyInToken'));
            if ($envMismatch) {
                $firstWasBadToken = $status === 400;
                [$status, $response] = self::post(self::HOST_SANDBOX, $deviceToken, $jwt, $payload);
                // Dev/sandbox device token + production-only auth key: no host
                // can ever deliver it → treat as unregistered (pruned) instead
                // of failing (and logging) on every single push.
                if ($firstWasBadToken && $status === 403 && str_contains((string) $response, 'BadEnvironmentKeyInToken')) {
                    return 'unregistered';
                }
            }

            if ($status === 200) return 'ok';
            // 410 = token no longer active; 400 BadDeviceToken also prunable.
            if ($status === 410 || ($status === 400 && str_contains((string) $response, 'BadDeviceToken'))) {
                return 'unregistered';
            }
            Log::warning('ApnsService::send failed', ['status' => $status, 'body' => mb_substr((string) $response, 0, 300)]);
            return 'error';
        } catch (\Throwable $e) {
            Log::warning('ApnsService::send exception: ' . $e->getMessage());
            return 'error';
        }
    }

    /** POST one push to the given APNs host. @return array{0:int,1:string} */
    private static function post(string $host, string $deviceToken, string $jwt, string $payload): array
    {
        $ch = curl_init($host . '/3/device/' . $deviceToken);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . config('services.apns.bundle_id'),
                'apns-push-type: alert',
                'apns-priority: 10',
                'apns-expiration: ' . (time() + 86400),
                'content-type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close() — deprecated no-op since PHP 8.5; CurlHandle
        // objects are freed by the GC. The call used to throw E_DEPRECATED
        // which Laravel escalated into a 500 (missing deprecations channel).
        $ch = null;
        return [$status, (string) $response];
    }
}
