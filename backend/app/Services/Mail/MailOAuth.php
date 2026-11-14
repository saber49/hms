<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Authorization-code + PKCE flow against the platform's own OAuth apps
 * (config services.mail_oauth). The redirect URI is derived from APP_URL,
 * so staging and production each use their own registered callback.
 */
class MailOAuth
{
    private const SCOPES = [
        'microsoft' => 'openid email profile offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send',
        'google'    => 'openid email profile https://mail.google.com/',
    ];

    /**
     * HTTP client for Microsoft endpoints. FrankenPHP's OpenSSL 3.6 offers a
     * post-quantum key share (X25519MLKEM768) by default and
     * login.microsoftonline.com answers that handshake with "404 Not Found"
     * (2026-10). Pinning the classic curves restores normal responses.
     */
    public static function http(): PendingRequest
    {
        $opt = defined('CURLOPT_SSL_EC_CURVES') ? CURLOPT_SSL_EC_CURVES : 10298;
        return Http::withOptions(['curl' => [$opt => 'X25519:prime256v1:secp384r1']]);
    }

    public static function providers(): array
    {
        return array_keys(self::SCOPES);
    }

    public static function configured(string $provider): bool
    {
        return isset(self::SCOPES[$provider])
            && !empty(config("services.mail_oauth.$provider.client_id"))
            && !empty(config("services.mail_oauth.$provider.client_secret"));
    }

    public static function redirectUri(string $provider): string
    {
        return rtrim((string) config('app.url'), '/') . "/api/mail/oauth/$provider/callback";
    }

    public static function pkce(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return [$verifier, $challenge];
    }

    public static function authUrl(string $provider, string $state, string $challenge, ?string $loginHint): string
    {
        $q = [
            'client_id'             => config("services.mail_oauth.$provider.client_id"),
            'response_type'         => 'code',
            'redirect_uri'          => self::redirectUri($provider),
            'scope'                 => self::SCOPES[$provider],
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if ($loginHint) $q['login_hint'] = $loginHint;

        if ($provider === 'microsoft') {
            $q['response_mode'] = 'query';
            $q['prompt'] = 'select_account';
            $base = 'https://login.microsoftonline.com/' . self::msTenant() . '/oauth2/v2.0/authorize';
        } else {
            $q['access_type'] = 'offline';
            $q['prompt'] = 'consent';
            $base = 'https://accounts.google.com/o/oauth2/v2/auth';
        }
        return $base . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    public static function exchange(string $provider, string $code, string $verifier): array
    {
        return self::token($provider, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri($provider),
            'code_verifier' => $verifier,
        ]);
    }

    public static function refresh(string $provider, string $refreshToken): array
    {
        return self::token($provider, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** @return array{email: string, name: string} */
    public static function profile(string $provider, string $accessToken): array
    {
        $url = $provider === 'microsoft'
            ? 'https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName'
            : 'https://openidconnect.googleapis.com/v1/userinfo';
        try {
            $res = self::http()->withToken($accessToken)->acceptJson()->timeout(15)->get($url);
        } catch (\Throwable) {
            throw new MailException('Der E-Mail-Anbieter ist nicht erreichbar.', 504);
        }
        $d = $res->json() ?? [];
        $email = strtolower(trim((string) ($provider === 'microsoft' ? ($d['mail'] ?? $d['userPrincipalName'] ?? '') : ($d['email'] ?? ''))));
        if (!$res->successful() || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MailException('Die E-Mail-Adresse des Kontos konnte nicht gelesen werden.', 502);
        }
        return ['email' => $email, 'name' => (string) ($provider === 'microsoft' ? ($d['displayName'] ?? '') : ($d['name'] ?? ''))];
    }

    private static function msTenant(): string
    {
        $t = (string) config('services.mail_oauth.microsoft.tenant', 'common');
        return preg_match('/^[A-Za-z0-9.-]+$/', $t) ? $t : 'common';
    }

    private static function token(string $provider, array $params): array
    {
        $url = $provider === 'microsoft'
            ? 'https://login.microsoftonline.com/' . self::msTenant() . '/oauth2/v2.0/token'
            : 'https://oauth2.googleapis.com/token';
        $params += [
            'client_id'     => config("services.mail_oauth.$provider.client_id"),
            'client_secret' => config("services.mail_oauth.$provider.client_secret"),
        ];
        if ($provider === 'microsoft') $params['scope'] = self::SCOPES['microsoft'];

        try {
            $res = self::http()->asForm()->acceptJson()->timeout(15)->post($url, $params);
        } catch (\Throwable) {
            throw new MailException('Der Anmeldedienst des E-Mail-Anbieters ist nicht erreichbar.', 504);
        }
        $d = $res->json() ?? [];
        if (!$res->successful() || empty($d['access_token'])) {
            $err = (string) ($d['error'] ?? 'http_' . $res->status());
            Log::warning('mail.oauth.token', [
                'provider' => $provider,
                'grant'    => $params['grant_type'],
                'error'    => $err,
                'desc'     => mb_substr((string) ($d['error_description'] ?? ''), 0, 300),
            ]);
            if ($err === 'invalid_grant' && $params['grant_type'] === 'refresh_token') {
                throw new MailException('Die Verbindung zum Postfach ist abgelaufen. Bitte neu verbinden.', 409, true);
            }
            throw new MailException('Die Anmeldung beim E-Mail-Anbieter ist fehlgeschlagen.', 502);
        }
        return [
            'type'          => 'oauth',
            'access_token'  => $d['access_token'],
            'refresh_token' => $d['refresh_token'] ?? ($params['refresh_token'] ?? null),
            'expires_at'    => time() + (int) ($d['expires_in'] ?? 3600),
        ];
    }
}
