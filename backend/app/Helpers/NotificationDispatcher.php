<?php

namespace App\Helpers;

use App\Jobs\SendWorkerPush;
use App\Mail\PlainNotificationMail;
use App\Models\CentralUser;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fan-out helper that creates an in-app notification row in the tenant DB
 * AND sends a plaintext email to every matching central_user, in a single
 * call.
 *
 * Targeting modes:
 *   - 'owners'           → is_owner = 1
 *   - 'permission:<key>' → any active user whose effective permissions
 *                          contain <key> (resolved via CentralUser model
 *                          so role presets are honoured).
 *
 * Failures (SMTP down, etc.) are swallowed and logged — a notification
 * delivery problem must never break the user-facing request that
 * triggered it. The in-app row is the source of truth; email is a
 * best-effort side channel.
 */
class NotificationDispatcher
{
    /**
     * Catalog of admin-facing notification types — the single source of
     * truth for the per-user "Benachrichtigungen" settings tab (label +
     * explanation per scenario). Group keys like 'worker.absence' cover
     * every 'worker.absence.*' subtype via longest-prefix matching in
     * CentralUser::wantsNotification().
     */
    public const TYPE_CATALOG = [
        'dispo.unplanned' => [
            'label' => 'Ungeplante Einsätze',
            'desc'  => 'Tägliche Erinnerung an Einsätze, denen noch keine Mitarbeiter zugewiesen sind.',
            'group' => 'Dispo',
            'aud'   => 'dispo',
        ],
        'dispo.late_clockin' => [
            'label' => 'Verspäteter Arbeitsbeginn',
            'desc'  => 'Ein Mitarbeiter hat sich nicht rechtzeitig zu seiner Schicht eingestempelt.',
            'group' => 'Dispo',
            'aud'   => 'dispo',
        ],
        'dispo.nu.rejected' => [
            'label' => 'NU-Absage',
            'desc'  => 'Ein Nachunternehmer hat eine angefragte Position abgelehnt.',
            'group' => 'Dispo',
            'aud'   => 'dispo',
        ],
        'dispo.boerse' => [
            'label' => 'Schichtbörse',
            'desc'  => 'Ein Mitarbeiter fragt eine offene Schicht an oder möchte seine Schicht abgeben.',
            'group' => 'Dispo',
            'aud'   => 'dispo',
        ],
        'dispo.einsatz.deleted' => [
            'label' => 'Einsatz gelöscht',
            'desc'  => 'Ein Einsatz wurde in der Dispo entfernt und fehlt dadurch in der Bestellung.',
            'group' => 'Dispo',
            'aud'   => 'eingang',
        ],
        'eingang.order.deleted' => [
            'label' => 'Bestelleingang gelöscht',
            'desc'  => 'Eine eingehende Bestellung wurde gelöscht; zugehörige Einsätze werden entfernt.',
            'group' => 'Bestelleingang',
            'aud'   => 'dispo',
        ],
        'chat.mention' => [
            'label' => 'Chat-Erwähnung',
            'desc'  => 'Sie wurden im internen Team-Chat mit @ erwähnt.',
            'group' => 'Kommunikation',
            'aud'   => 'all',
        ],
        'worker.absence' => [
            'label' => 'Abwesenheiten & Krankmeldungen',
            'desc'  => 'Mitarbeiter reichen Urlaubs- oder Abwesenheitsanträge ein, ändern sie oder melden sich krank.',
            'group' => 'Mitarbeiter',
            'aud'   => 'mitarbeiter',
        ],
        'dokument.hochgeladen' => [
            'label' => 'Dokument-Upload',
            'desc'  => 'Ein Mitarbeiter hat ein Dokument (Ausweis, Zertifikat …) hochgeladen.',
            'group' => 'Mitarbeiter',
            'aud'   => 'mitarbeiter',
        ],
        'aufgabe.neu' => [
            'label' => 'Neue Aufgabe',
            'desc'  => 'Ihnen wurde eine Aufgabe zugewiesen (Glocke, App-Push und E-Mail).',
            'group' => 'Aufgaben',
            'aud'   => 'all',
        ],
        'aufgabe.erledigt' => [
            'label' => 'Aufgabe erledigt',
            'desc'  => 'Ein Empfänger hat eine von Ihnen erstellte Aufgabe erledigt.',
            'group' => 'Aufgaben',
            'aud'   => 'all',
        ],
        'vertrieb.hinweis' => [
            'label' => 'Vertrieb: Hinweise',
            'desc'  => 'Lead-Modul: verlorene Deals, Übergaben an die Objektleitung und Hinweise an die Vertriebsleitung.',
            'group' => 'Vertrieb',
            'aud'   => 'all',
        ],
        'subscription.expiring' => [
            'label' => 'Abo läuft ab',
            'desc'  => 'Erinnerung vor dem Ablauf des Abonnements (nur Inhaber).',
            'group' => 'Konto',
            'aud'   => 'owner',
        ],
        'subscription.expired' => [
            'label' => 'Abo abgelaufen',
            'desc'  => 'Hinweis nach dem Ablauf des Abonnements (nur Inhaber).',
            'group' => 'Konto',
            'aud'   => 'owner',
        ],
    ];

    /**
     * Notify every user with Mitarbeiter access (owner OR view/edit perm).
     *
     * @param  int     $tenantId   Tenant whose central_users are scanned + whose tenant DB receives the rows.
     * @param  string  $type       Machine kind, e.g. 'worker.absence.created'.
     * @param  string  $title      Short subject line.
     * @param  string  $body       Plain text body.
     * @param  string  $link       In-app deep link (e.g. '#workers').
     * @param  array   $payload    Arbitrary metadata stored as JSON.
     * @return int                 Number of recipients that received the in-app row.
     */
    public static function notifyMitarbeiterManagers(
        int $tenantId,
        string $type,
        string $title,
        string $body,
        string $link = '#workers',
        array $payload = [],
        ?string $htmlBody = null
    ): int {
        $recipients = self::resolveMitarbeiterRecipients($tenantId);
        return self::dispatch($recipients, $type, $title, $body, $link, $payload, $htmlBody);
    }

    /**
     * Notify every user responsible for Dispo: owners + anyone with
     * auftraege.dispo.view or .edit. Used by the daily "unplanned shifts"
     * reminder (type 'dispo.unplanned', link '#tasks').
     *
     * @param  int     $tenantId   Tenant whose central_users are scanned + whose tenant DB receives the rows.
     * @param  string  $type       Machine kind, e.g. 'dispo.unplanned'.
     * @param  string  $title      Short subject line.
     * @param  string  $body       Plain text body.
     * @param  string  $link       In-app deep link (e.g. '#tasks').
     * @param  array   $payload    Arbitrary metadata stored as JSON.
     * @return int                 Number of recipients that received the in-app row.
     */
    public static function notifyDispoManagers(
        int $tenantId,
        string $type,
        string $title,
        string $body,
        string $link = '#tasks',
        array $payload = [],
        ?string $htmlBody = null
    ): int {
        $recipients = self::resolveDispoRecipients($tenantId);
        return self::dispatch($recipients, $type, $title, $body, $link, $payload, $htmlBody);
    }

    /**
     * Notify every user responsible for Bestelleingang: owners + anyone with
     * auftraege.eingang.view or .edit. Used when a shift is deleted from Dispo
     * so the back-office sees it on the originating order card
     * (type 'dispo.einsatz.deleted', link '#incoming_orders').
     *
     * @param  int     $tenantId   Tenant whose central_users are scanned + whose tenant DB receives the rows.
     * @param  string  $type       Machine kind, e.g. 'dispo.einsatz.deleted'.
     * @param  string  $title      Short subject line.
     * @param  string  $body       Plain text body.
     * @param  string  $link       In-app deep link (e.g. '#incoming_orders?id=BE-...').
     * @param  array   $payload    Arbitrary metadata stored as JSON.
     * @return int                 Number of recipients that received the in-app row.
     */
    public static function notifyEingangManagers(
        int $tenantId,
        string $type,
        string $title,
        string $body,
        string $link = '#incoming_orders',
        array $payload = [],
        ?string $htmlBody = null
    ): int {
        $recipients = self::resolveEingangRecipients($tenantId);
        return self::dispatch($recipients, $type, $title, $body, $link, $payload, $htmlBody);
    }

    /**
     * Notify the tenant owners only. Used for account-level notices such as
     * the subscription expiry reminder (type 'subscription.expiring',
     * link '#settings').
     *
     * @param  int     $tenantId   Tenant whose central_users are scanned + whose tenant DB receives the rows.
     * @param  string  $type       Machine kind, e.g. 'subscription.expiring'.
     * @param  string  $title      Short subject line.
     * @param  string  $body       Plain text body.
     * @param  string  $link       In-app deep link (e.g. '#settings').
     * @param  array   $payload    Arbitrary metadata stored as JSON.
     * @return int                 Number of recipients that received the in-app row.
     */
    public static function notifyOwners(
        int $tenantId,
        string $type,
        string $title,
        string $body,
        string $link = '#settings',
        array $payload = [],
        ?string $htmlBody = null
    ): int {
        $recipients = self::resolveOwnerRecipients($tenantId);
        return self::dispatch($recipients, $type, $title, $body, $link, $payload, $htmlBody);
    }

    /**
     * Resolve the tenant owners (is_owner = 1, active, with an email).
     *
     * @return array<int, CentralUser>
     */
    private static function resolveOwnerRecipients(int $tenantId): array
    {
        return CentralUser::where('tenant_id', $tenantId)
            ->where('aktiv', 1)
            ->where('is_owner', 1)
            ->get()
            ->all();
    }

    /**
     * Resolve all central_users for the tenant who should see Mitarbeiter
     * notifications: owners + anyone with stammdaten.mitarbeiter.view or .edit.
     *
     * @return array<int, CentralUser>
     */
    private static function resolveMitarbeiterRecipients(int $tenantId): array
    {
        $users = CentralUser::where('tenant_id', $tenantId)
            ->where('aktiv', 1)
            ->get();

        $out = [];
        foreach ($users as $u) {
            if ($u->is_owner
                || $u->hasPermission('stammdaten.mitarbeiter.view')
                || $u->hasPermission('stammdaten.mitarbeiter.edit')
            ) {
                $out[] = $u;
            }
        }
        return $out;
    }

    /**
     * Resolve all central_users for the tenant who should see Dispo
     * notifications: owners + anyone with auftraege.dispo.view or .edit.
     *
     * @return array<int, CentralUser>
     */
    private static function resolveDispoRecipients(int $tenantId): array
    {
        $users = CentralUser::where('tenant_id', $tenantId)
            ->where('aktiv', 1)
            ->get();

        $out = [];
        foreach ($users as $u) {
            if ($u->is_owner
                || $u->hasPermission('auftraege.dispo.view')
                || $u->hasPermission('auftraege.dispo.edit')
            ) {
                $out[] = $u;
            }
        }
        return $out;
    }

    /**
     * Resolve all central_users for the tenant who should see Bestelleingang
     * notifications: owners + anyone with auftraege.eingang.view or .edit.
     *
     * @return array<int, CentralUser>
     */
    private static function resolveEingangRecipients(int $tenantId): array
    {
        $users = CentralUser::where('tenant_id', $tenantId)
            ->where('aktiv', 1)
            ->get();

        $out = [];
        foreach ($users as $u) {
            if ($u->is_owner
                || $u->hasPermission('auftraege.eingang.view')
                || $u->hasPermission('auftraege.eingang.edit')
            ) {
                $out[] = $u;
            }
        }
        return $out;
    }

    /**
     * Send a notification BACK to a worker (after manager approve/reject).
     *
     * Stores a row in tenant `worker_notifications` so the worker app can
     * surface it inside the Frei screen + bell icon, and (best-effort) sends
     * an email to the worker if their `email` is on file.
     *
     * @param  object $worker     A row from the `mitarbeiter` table (must have id, dienst_nr; email optional).
     * @return bool               True if the in-app row was inserted.
     */
    public static function notifyWorker(
        $worker,
        string $type,
        string $title,
        string $body,
        array $payload = [],
        ?string $htmlBody = null
    ): bool {
        if (!$worker || empty($worker->id)) return false;

        try {
            DB::connection('tenant')->table('worker_notifications')->insert([
                'mitarbeiter_id' => $worker->id,
                'dienst_nr'      => $worker->dienst_nr,
                'type'           => $type,
                'title'          => $title,
                'body'           => $body,
                'payload'        => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'gelesen'        => 0,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationDispatcher::notifyWorker insert failed', [
                'dienst_nr' => $worker->dienst_nr ?? null,
                'type'      => $type,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }

        // Real push to the worker's registered devices (arrives even when
        // the app is closed). Queued — device pushes are slow HTTP round-trips
        // and must never block the caller. Falls back to inline send when the
        // tenant context is unavailable (job needs the tenant id to re-switch).
        try {
            $tenantId = null;
            try {
                $tenantId = \App\Services\TenantService::current()->id ?? null;
            } catch (\Throwable $e) { /* tenant context optional */ }

            $tab = \App\Services\WorkerPushService::tabForType($type);
            if ($tenantId) {
                SendWorkerPush::dispatch((int) $tenantId, (string) $worker->dienst_nr, $title, $body, $tab);
            } else {
                \App\Services\WorkerPushService::push((string) $worker->dienst_nr, $title, $body, $tab);
            }
        } catch (\Throwable $e) { /* logged inside the service */ }

        if (!empty($worker->email)) {
            try {
                // Resolve tenant company name so the subject line carries
                // the same "[Subject] | <Firma>" pattern as the rest of the
                // outbound mail (NU emails etc.).
                $company = null;
                try {
                    $tenant = \App\Services\TenantService::current();
                    $company = $tenant->name ?? null;
                } catch (\Throwable $e) { /* tenant context optional */ }
                $subject = $title . ($company ? ' | ' . $company : '');

                $name = trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''));
                Mail::to($worker->email, $name ?: $worker->dienst_nr)
                    ->queue(new PlainNotificationMail($subject, $body, $htmlBody));
            } catch (\Throwable $e) {
                Log::warning('NotificationDispatcher::notifyWorker mail failed', [
                    'to'    => $worker->email,
                    'type'  => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * Notify an explicit set of central_user IDs (e.g. chat @mentions,
     * where the caller already knows the addressees). Loads the
     * CentralUser models so per-user notification prefs are honoured.
     *
     * @return int Number of recipients that received the in-app row.
     */
    public static function notifyUserIds(
        int $tenantId,
        array $centralUserIds,
        string $type,
        string $title,
        string $body,
        string $link = '#',
        array $payload = [],
        ?string $htmlBody = null
    ): int {
        if (empty($centralUserIds)) return 0;
        $recipients = CentralUser::where('tenant_id', $tenantId)
            ->whereIn('id', $centralUserIds)
            ->where('aktiv', 1)
            ->get()
            ->all();
        return self::dispatch($recipients, $type, $title, $body, $link, $payload, $htmlBody);
    }

    /**
     * Persist + device push. Returns count of in-app rows actually
     * inserted. Each channel honours the recipient's notification_prefs:
     * 'app' gates the bell row, 'push' gates the FCM/APNs/WebPush
     * delivery — opt-out only, so users without prefs keep receiving
     * everything. Admin notifications are deliberately NOT emailed
     * (bell + app push only); worker/NU mails are sent elsewhere.
     */
    private static function dispatch(
        array $recipients,
        string $type,
        string $title,
        string $body,
        string $link,
        array $payload,
        ?string $htmlBody = null
    ): int {
        if (empty($recipients)) return 0;

        $now = now();
        $rows = [];
        foreach ($recipients as $u) {
            if ($u->wantsNotification($type, 'app')) {
                $rows[] = [
                    'central_user_id' => $u->id,
                    'type'            => $type,
                    'title'           => $title,
                    'body'            => $body,
                    'link'            => $link,
                    'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'gelesen'         => 0,
                    'created_at'      => $now,
                ];
            }
            // Device push — queued like worker pushes; a missing table or
            // dead token must never break the dispatch.
            if ($u->wantsNotification($type, 'push')) {
                try {
                    \App\Jobs\SendCentralPush::dispatch((int) $u->id, $title, $body, $link);
                } catch (\Throwable $e) { /* queue down — skip silently */ }
            }
        }

        if (!empty($rows)) {
            try {
                DB::connection('tenant')->table('notifications')->insert($rows);
            } catch (\Throwable $e) {
                Log::warning('NotificationDispatcher: insert failed', [
                    'type'  => $type,
                    'error' => $e->getMessage(),
                ]);
                return 0;
            }

            // Realtime push to each recipient's private WebSocket channel
            // (bell badge / dropdown / toast update instantly). Row ids
            // are derived from the bulk insert's first auto-increment so
            // clients can mark-read/dedupe without a refetch.
            try {
                $firstId = (int) DB::connection('tenant')->getPdo()->lastInsertId();
                foreach ($rows as $i => $row) {
                    \App\Helpers\Realtime::notification((int) $row['central_user_id'], [
                        'id'         => $firstId ? $firstId + $i : null,
                        'type'       => $row['type'],
                        'title'      => $row['title'],
                        'body'       => $row['body'],
                        'link'       => $row['link'],
                        'created_at' => $row['created_at'],
                    ]);
                }
            } catch (\Throwable $e) { /* Realtime swallows internally too */ }
        }

        return count($rows);
    }
}
