<?php

namespace App\Services\Mail;

use App\Services\TenantService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Persistence + driver factory for `mail_konten` (tenant DB).
 * `credentials` is decrypted here only and never leaves the backend.
 */
class MailService
{
    public static function find(int $id): ?object
    {
        TenantService::assertActive();
        return DB::connection('tenant')->table('mail_konten')->where('id', $id)->first();
    }

    public static function all()
    {
        TenantService::assertActive();
        return DB::connection('tenant')->table('mail_konten')->orderBy('email')->get();
    }

    public static function publicRow(object $k): array
    {
        return [
            'id'           => (int) $k->id,
            'email'        => $k->email,
            'anzeige_name' => $k->anzeige_name,
            'provider'     => $k->provider,
            'server'       => $k->provider === 'microsoft' ? null : $k->imap_host,
            'aktiv'        => (bool) $k->aktiv,
            'last_error'   => $k->last_error,
        ];
    }

    public static function encrypt(array $credentials): string
    {
        return Crypt::encryptString(json_encode($credentials));
    }

    public static function credentials(object $k): array
    {
        try {
            return json_decode(Crypt::decryptString($k->credentials), true) ?: [];
        } catch (\Throwable) {
            throw new MailException('Die Zugangsdaten sind nicht mehr lesbar. Bitte das Postfach neu verbinden.', 409, true);
        }
    }

    public static function driver(object $k): MailDriver
    {
        $c = self::credentials($k);
        return $k->provider === 'microsoft' ? new GraphMailDriver($k, $c) : new ImapMailDriver($k, $c);
    }

    public static function markError(int $id, ?string $error): void
    {
        DB::connection('tenant')->table('mail_konten')->where('id', $id)
            ->update(['last_error' => $error ? mb_substr($error, 0, 500) : null, 'updated_at' => now()]);
    }

    /**
     * Valid OAuth access token for the mailbox, refreshing (and persisting
     * the rotated refresh token) when it is about to expire. Serialised per
     * mailbox across Octane workers so two parallel requests never race on
     * the same refresh token.
     */
    public static function accessToken(object $k, array &$cred): string
    {
        if (($cred['expires_at'] ?? 0) > time() + 120 && !empty($cred['access_token'])) {
            return $cred['access_token'];
        }
        if ((int) $k->id === 0) {
            throw new MailException('Die Verbindung zum Postfach ist abgelaufen. Bitte neu verbinden.', 409, true);
        }
        $tenant = TenantService::assertActive();
        try {
            return Cache::lock("mail:refresh:{$tenant->id}:{$k->id}", 20)->block(15, function () use ($k, &$cred) {
                $fresh = self::find((int) $k->id);
                $c = $fresh ? self::credentials($fresh) : $cred;
                if (($c['expires_at'] ?? 0) > time() + 120 && !empty($c['access_token'])) {
                    $cred = $c;
                    return $c['access_token'];
                }
                if (empty($c['refresh_token'])) {
                    throw new MailException('Die Verbindung zum Postfach ist abgelaufen. Bitte neu verbinden.', 409, true);
                }
                try {
                    $t = MailOAuth::refresh($k->provider, $c['refresh_token']);
                } catch (MailException $e) {
                    if ($e->reconnect) self::markError((int) $k->id, $e->getMessage());
                    throw $e;
                }
                DB::connection('tenant')->table('mail_konten')->where('id', $k->id)->update([
                    'credentials' => self::encrypt($t),
                    'last_error'  => null,
                    'updated_at'  => now(),
                ]);
                $cred = $t;
                return $t['access_token'];
            });
        } catch (LockTimeoutException) {
            throw new MailException('Das Postfach ist gerade beschäftigt. Bitte erneut versuchen.', 503);
        }
    }
}
