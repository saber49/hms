<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web-Push (VAPID) delivery to worker-app users — real system notifications
 * that arrive even when the app/browser is completely closed.
 *
 * Subscriptions live per tenant in `worker_push_subscriptions` (a worker may
 * have several devices). Sending is strictly best-effort: a push failure must
 * never break the business action that triggered it. Endpoints rejected with
 * 404/410 (expired/unsubscribed) are pruned on the fly.
 */
class WorkerPushService
{
    /** True when VAPID keys are configured and the tenant table exists. */
    public static function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('worker_push_subscriptions')
            && ((bool) config('services.webpush.public_key') || FcmService::ready() || ApnsService::ready());
    }

    public static function publicKey(): ?string
    {
        return config('services.webpush.public_key') ?: null;
    }

    /**
     * Store (upsert) a subscription for a worker.
     */
    public static function subscribe(string $dienstNr, string $endpoint, ?string $p256dh, ?string $auth, string $provider = 'webpush'): void
    {
        DB::connection('tenant')->table('worker_push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'dienst_nr'  => $dienstNr,
                'provider'   => $provider,
                'endpoint'   => $endpoint,
                'p256dh'     => $p256dh,
                'auth'       => $auth,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public static function unsubscribe(string $dienstNr, string $endpoint): void
    {
        DB::connection('tenant')->table('worker_push_subscriptions')
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->where('dienst_nr', $dienstNr)
            ->delete();
    }

    /**
     * Push a notification to every registered device of a worker.
     * $tab tells the app which screen to open on tap (plan/chat/profil/frei/feed).
     *
     * @return int Number of devices the push was accepted for.
     */
    public static function push(string $dienstNr, string $title, string $body, string $tab = 'feed', array $data = []): int
    {
        try {
            if (!self::ready()) return 0;

            $subs = DB::connection('tenant')->table('worker_push_subscriptions')
                ->where('dienst_nr', $dienstNr)->get();
            if ($subs->isEmpty()) return 0;

            $sent = 0;

            // Native app devices: Android → FCM, iOS → direct APNs.
            foreach ($subs->whereIn('provider', ['fcm', 'apns']) as $s) {
                $result = $s->provider === 'apns'
                    ? ApnsService::send($s->endpoint, $title, $body, $tab, $data)
                    : FcmService::send($s->endpoint, $title, $body, $tab, $data);
                if ($result === 'ok') {
                    $sent++;
                } elseif ($result === 'unregistered') {
                    DB::connection('tenant')->table('worker_push_subscriptions')
                        ->where('id', $s->id)->delete();
                }
            }

            // Browser/PWA devices — via Web-Push (VAPID).
            $subs = $subs->filter(fn($s) => ($s->provider ?? 'webpush') === 'webpush')->values();
            if ($subs->isEmpty() || !config('services.webpush.public_key')) return $sent;

            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.webpush.subject'),
                    'publicKey'  => config('services.webpush.public_key'),
                    'privateKey' => config('services.webpush.private_key'),
                ],
            ]);
            $webPush->setDefaultOptions(['TTL' => 86400, 'urgency' => 'high']); // @phpstan-ignore-line

            $payload = json_encode(array_merge([
                'title' => $title,
                'body'  => mb_substr($body, 0, 240),
                'tab'   => $tab,
            ], $data), JSON_UNESCAPED_UNICODE);

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
                // Prune endpoints that are gone for good (device unsubscribed
                // or browser rotated the subscription).
                $status = $report->getResponse()?->getStatusCode();
                if (in_array($status, [404, 410], true)) {
                    DB::connection('tenant')->table('worker_push_subscriptions')
                        ->where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                        ->delete();
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            Log::warning('WorkerPushService::push failed', [
                'dienst_nr' => $dienstNr,
                'error'     => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Map a worker_notifications type to the app tab opened on tap —
     * mirror of notifTargetTab() in the worker app frontend.
     */
    public static function tabForType(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'shift.')    => 'plan',
            str_starts_with($type, 'message.')  => 'chat',
            str_starts_with($type, 'dokument.') => 'profil',
            str_starts_with($type, 'absence.')  => 'frei',
            str_starts_with($type, 'qm_')       => 'dienst',
            default                             => 'feed',
        };
    }
}
