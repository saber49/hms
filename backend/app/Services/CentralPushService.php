<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Device-push delivery to central_users (admin accounts) — mirror of
 * WorkerPushService, but the subscriptions live on the CENTRAL connection
 * in `central_user_push_tokens` because the recipient is a central_user.
 *
 * Reached when an admin logs into the worker app: the Capacitor WebView
 * then loads the web dashboard, which registers the device's FCM/APNs
 * token under this user's id. Web-Push (VAPID) rows are also honoured so
 * a future browser subscription path needs no schema change.
 *
 * Sending is strictly best-effort: a push failure must never break the
 * business action that triggered it. Tokens rejected with
 * 'unregistered'/404/410 are pruned on the fly.
 */
class CentralPushService
{
    /** True when at least one native/web push transport is configured. */
    public static function ready(): bool
    {
        return Schema::connection('mysql')->hasTable('central_user_push_tokens')
            && (FcmService::ready() || ApnsService::ready() || (bool) config('services.webpush.public_key'));
    }

    /**
     * Store (upsert) a device token/subscription for a central user.
     */
    public static function subscribe(int $userId, string $endpoint, ?string $p256dh = null, ?string $auth = null, string $provider = 'fcm'): void
    {
        DB::connection('mysql')->table('central_user_push_tokens')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'central_user_id' => $userId,
                'provider'        => $provider,
                'endpoint'        => $endpoint,
                'p256dh'          => $p256dh,
                'auth'            => $auth,
                'updated_at'      => now(),
                'created_at'      => now(),
            ]
        );
    }

    public static function unsubscribe(int $userId, string $endpoint): void
    {
        DB::connection('mysql')->table('central_user_push_tokens')
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->where('central_user_id', $userId)
            ->delete();
    }

    /**
     * Push a notification to every registered device of a central user.
     * $link is the web-panel deep link (e.g. '#tasks') carried in the
     * push payload so a tap can open the matching view.
     *
     * @return int Number of devices the push was accepted for.
     */
    public static function push(int $userId, string $title, string $body, string $link = '#'): int
    {
        try {
            if (!self::ready()) return 0;

            $subs = DB::connection('mysql')->table('central_user_push_tokens')
                ->where('central_user_id', $userId)->get();
            if ($subs->isEmpty()) return 0;

            $sent = 0;

            // Native app devices: Android → FCM, iOS → direct APNs.
            foreach ($subs->whereIn('provider', ['fcm', 'apns']) as $s) {
                $result = $s->provider === 'apns'
                    ? ApnsService::send($s->endpoint, $title, $body, $link)
                    : FcmService::send($s->endpoint, $title, $body, $link);
                if ($result === 'ok') {
                    $sent++;
                } elseif ($result === 'unregistered') {
                    DB::connection('mysql')->table('central_user_push_tokens')
                        ->where('id', $s->id)->delete();
                }
            }

            // Browser devices — via Web-Push (VAPID).
            $subs = $subs->filter(fn($s) => ($s->provider ?? 'fcm') === 'webpush')->values();
            if ($subs->isEmpty() || !config('services.webpush.public_key')) return $sent;

            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.webpush.subject'),
                    'publicKey'  => config('services.webpush.public_key'),
                    'privateKey' => config('services.webpush.private_key'),
                ],
            ]);
            $webPush->setDefaultOptions(['TTL' => 86400, 'urgency' => 'high']); // @phpstan-ignore-line

            $payload = json_encode([
                'title' => $title,
                'body'  => mb_substr($body, 0, 240),
                'link'  => $link,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $s) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $s->endpoint,
                    'keys'     => ['p256dh' => $s->p256dh, 'auth' => $s->auth],
                ]), $payload);
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }
                // Prune endpoints that are gone for good (unsubscribed or
                // the browser rotated the subscription).
                $status = $report->getResponse()?->getStatusCode();
                if (in_array($status, [404, 410], true)) {
                    DB::connection('mysql')->table('central_user_push_tokens')
                        ->where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                        ->delete();
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('CentralPushService::push failed', [
                'central_user_id' => $userId,
                'error'           => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
