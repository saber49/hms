<?php

namespace App\Services;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) sender for the native worker app
 * (Capacitor Android/iOS). Uses the service-account JSON referenced by
 * FCM_CREDENTIALS_JSON to mint short-lived OAuth2 tokens — no extra SDK.
 *
 * Delivery is best-effort; UNREGISTERED tokens are reported back to the
 * caller (WorkerPushService) for pruning.
 */
class FcmService
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function ready(): bool
    {
        $path = config('services.fcm.credentials');
        return $path && is_readable($path);
    }

    /** @return array{project_id:string,client_email:string,private_key:string}|null */
    private static function credentials(): ?array
    {
        $path = config('services.fcm.credentials');
        if (!$path || !is_readable($path)) return null;
        $json = json_decode((string) file_get_contents($path), true);
        if (empty($json['project_id']) || empty($json['client_email']) || empty($json['private_key'])) {
            return null;
        }
        return $json;
    }

    /** Mint (and cache) an OAuth2 access token for FCM. */
    private static function accessToken(): ?string
    {
        $creds = self::credentials();
        if (!$creds) return null;

        return Cache::remember('fcm_oauth_token', 3000, function () use ($creds) {
            $now = time();
            $assertion = JWT::encode([
                'iss'   => $creds['client_email'],
                'scope' => self::SCOPE,
                'aud'   => self::OAUTH_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $creds['private_key'], 'RS256');

            $res = (new Client(['timeout' => 10]))->post(self::OAUTH_URL, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
            ]);
            $body = json_decode((string) $res->getBody(), true);
            return $body['access_token'] ?? null;
        });
    }

    /**
     * Send a notification to one FCM device token.
     *
     * @return string 'ok' | 'unregistered' (prune the token) | 'error'
     */
    public static function send(string $token, string $title, string $body, string $tab = 'feed', array $data = []): string
    {
        try {
            $creds = self::credentials();
            $access = self::accessToken();
            if (!$creds || !$access) return 'error';

            $client = new Client(['timeout' => 10]);
            $client->post(
                'https://fcm.googleapis.com/v1/projects/' . $creds['project_id'] . '/messages:send',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $access],
                    'json' => [
                        'message' => [
                            'token' => $token,
                            'notification' => [
                                'title' => $title,
                                'body'  => mb_substr($body, 0, 240),
                            ],
                            'data' => array_map('strval', array_merge(['tab' => $tab], $data)),
                            'android' => [
                                'priority' => 'HIGH',
                                'notification' => ['sound' => 'default'],
                            ],
                            'apns' => [
                                'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                            ],
                        ],
                    ],
                ]
            );
            return 'ok';
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $bodyStr = (string) ($e->getResponse()?->getBody() ?? '');
            if ($status === 404 || str_contains($bodyStr, 'UNREGISTERED') || str_contains($bodyStr, 'INVALID_ARGUMENT')) {
                return 'unregistered';
            }
            Log::warning('FcmService::send client error', ['status' => $status, 'body' => mb_substr($bodyStr, 0, 300)]);
            return 'error';
        } catch (\Throwable $e) {
            Log::warning('FcmService::send failed: ' . $e->getMessage());
            return 'error';
        }
    }
}
