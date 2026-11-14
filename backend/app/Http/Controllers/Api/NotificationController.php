<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\NotificationDispatcher;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read/manage the current central_user's in-app notifications (tenant-scoped).
 *
 * All endpoints sit behind the standard `auth:sanctum + ResolveTenant`
 * middleware stack, so the tenant DB is already selected and $user->id
 * identifies the recipient.
 */
class NotificationController extends Controller
{
    /**
     * Read-time permission guard.
     *
     * Rows are already TARGETED by permission when they are created
     * (NotificationDispatcher), but permissions can change afterwards —
     * this constraint guarantees a user only ever SEES notification
     * types their CURRENT permissions entitle them to. Owners see all.
     *
     * The audience map must mirror the dispatcher routing:
     *   dispo staff       ← dispo.unplanned, dispo.nu.rejected,
     *                       eingang.order.deleted (their Einsätze vanish)
     *   eingang staff     ← dispo.einsatz.deleted (their order is affected)
     *   mitarbeiter staff ← worker.*, dokument.*
     * Unknown/future types stay visible to their addressee.
     */
    private function applyPermissionFilter($user, $q): void
    {
        if ($user->is_owner) return;

        $canDispo = $user->hasPermission('auftraege.dispo.view')
                 || $user->hasPermission('auftraege.dispo.edit');
        $canEingang = $user->hasPermission('auftraege.eingang.view')
                   || $user->hasPermission('auftraege.eingang.edit');
        $canMitarbeiter = $user->hasPermission('stammdaten.mitarbeiter.view')
                       || $user->hasPermission('stammdaten.mitarbeiter.edit');

        $dispoTypes   = ['dispo.unplanned', 'dispo.nu.rejected', 'dispo.late_clockin', 'eingang.order.deleted'];
        $eingangTypes = ['dispo.einsatz.deleted'];
        $maPrefixes   = ['worker.', 'dokument.'];

        $q->where(function ($w) use ($canDispo, $canEingang, $canMitarbeiter, $dispoTypes, $eingangTypes, $maPrefixes) {
            // Types not governed by any category remain visible.
            $w->where(function ($x) use ($dispoTypes, $eingangTypes, $maPrefixes) {
                $x->whereNotIn('type', array_merge($dispoTypes, $eingangTypes));
                foreach ($maPrefixes as $p) {
                    $x->where('type', 'not like', $p . '%');
                }
            });
            if ($canDispo)   $w->orWhereIn('type', $dispoTypes);
            if ($canEingang) $w->orWhereIn('type', $eingangTypes);
            if ($canMitarbeiter) {
                $w->orWhere(function ($x) use ($maPrefixes) {
                    foreach ($maPrefixes as $i => $p) {
                        $i === 0 ? $x->where('type', 'like', $p . '%')
                                 : $x->orWhere('type', 'like', $p . '%');
                    }
                });
            }
        });
    }

    /**
     * GET /api/notifications
     *   ?unread_only=1   only gelesen=0
     *   ?limit=N         default 50, max 200
     *   ?type=prefix     optional filter by exact type or 'worker.absence.%' wildcard
     */
    public function index(Request $request)
    {
        $user   = $request->user();
        $userId = $user->id;
        $limit  = max(1, min(200, (int) $request->query('limit', 50)));

        $q = DB::connection('tenant')->table('notifications')
            ->where('central_user_id', $userId);
        $this->applyPermissionFilter($user, $q);

        if ($request->boolean('unread_only')) {
            $q->where('gelesen', 0);
        }
        if ($request->filled('type')) {
            $type = (string) $request->query('type');
            if (str_ends_with($type, '%')) {
                $q->where('type', 'like', $type);
            } else {
                $q->where('type', $type);
            }
        }

        $rows = $q->orderByDesc('id')->limit($limit)->get();
        $rows = $this->scopeNotificationRows($user, $rows);

        $cq = DB::connection('tenant')->table('notifications')
            ->where('central_user_id', $userId)->where('gelesen', 0);
        $this->applyPermissionFilter($user, $cq);

        return response()->json([
            'notifications' => $rows,
            'unread_count'  => $this->scopeNotificationRows(
                $user, $cq->select('id', 'type', 'link', 'payload')->get()
            )->count(),
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     *   ?group=workers   restrict to mitarbeiter-related types for the sidebar badge
     */
    public function unreadCount(Request $request)
    {
        $user   = $request->user();
        $userId = $user->id;

        $q = DB::connection('tenant')->table('notifications')
            ->where('central_user_id', $userId)
            ->where('gelesen', 0);
        $this->applyPermissionFilter($user, $q);

        $group = $request->query('group');
        if ($group === 'workers') {
            $q->where('type', 'like', 'worker.%');
        }

        // Sidebar `workers` badge = ground-truth count of absences still
        // waiting for a manager decision (NOT based on the notifications
        // table). This way the badge reappears for any new request even if
        // the manager already opened the Mitarbeiter section earlier in
        // the session, and it goes down to zero only after explicit
        // approve/reject — exactly what users expect.
        [$wSql, $wParams] = BereichScope::workerPredicate($user, 'm');
        $pendingAbsences = (int) DB::connection('tenant')->table('mitarbeiter_abwesenheiten')
            ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'mitarbeiter_abwesenheiten.dienst_nr')
            ->where('mitarbeiter_abwesenheiten.status', 'pending')
            ->whereRaw($wSql, $wParams)
            ->count();

        $tq = DB::connection('tenant')->table('notifications')
            ->where('central_user_id', $userId)->where('gelesen', 0);
        $this->applyPermissionFilter($user, $tq);

        return response()->json([
            'total'   => $this->scopeNotificationRows(
                $user, $tq->select('id', 'type', 'link', 'payload')->get()
            )->count(),
            'workers' => $pendingAbsences,
        ]);
    }

    /**
     * Bereich scope for notification rows: a notification about an
     * out-of-scope Einsatz or worker must not leak its existence to a
     * restricted user. Rows with no scopable entity in their payload
     * (e.g. generic system notices) stay visible.
     */
    private function scopeNotificationRows($user, $rows)
    {
        $allowed = BereichScope::for($user);
        if ($allowed === null || $rows->isEmpty()) {
            return $rows;
        }

        // Collect entity ids referenced by the rows (payload + deep link).
        $einsatzIds = [];
        $dienstNrs  = [];
        $extract = function ($r) use (&$einsatzIds, &$dienstNrs) {
            $p = json_decode($r->payload ?? 'null', true) ?: [];
            $eid = $p['einsatz_id'] ?? null;
            $dnr = $p['dienst_nr'] ?? null;
            if (!$dnr && !empty($r->link)
                && preg_match('/dienst_nr=([A-Za-z0-9\-_]+)/', $r->link, $mm)) {
                $dnr = $mm[1];
            }
            return [$eid ? (string) $eid : null, $dnr ? (string) $dnr : null];
        };
        foreach ($rows as $r) {
            [$eid, $dnr] = $extract($r);
            if ($eid) $einsatzIds[] = $eid;
            if ($dnr) $dienstNrs[]  = $dnr;
        }

        // Batch-resolve which referenced entities are inside the whitelist.
        $okEinsatz = [];
        if ($einsatzIds) {
            [$sql, $params] = BereichScope::inList($allowed, BereichScope::derivedExprSub('e'));
            $okEinsatz = array_flip(array_map('strval', DB::connection('tenant')
                ->table('einsaetze as e')
                ->whereIn('e.einsatz_id', array_unique($einsatzIds))
                ->whereRaw($sql, $params)
                ->pluck('e.einsatz_id')->all()));
        }
        $okWorker = [];
        if ($dienstNrs) {
            [$wsql, $wparams] = BereichScope::workerPredicate($user, 'm');
            $okWorker = array_flip(array_map('strval', DB::connection('tenant')
                ->table('mitarbeiter as m')
                ->whereIn('m.dienst_nr', array_unique($dienstNrs))
                ->whereRaw($wsql, $wparams)
                ->pluck('m.dienst_nr')->all()));
        }

        return $rows->filter(function ($r) use ($extract, $okEinsatz, $okWorker) {
            [$eid, $dnr] = $extract($r);
            if ($eid && !isset($okEinsatz[$eid])) return false;
            if ($dnr && !isset($okWorker[$dnr])) return false;
            return true;
        })->values();
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function markRead(Request $request, int $id)
    {
        $userId = $request->user()->id;
        $affected = DB::connection('tenant')->table('notifications')
            ->where('id', $id)->where('central_user_id', $userId)
            ->update(['gelesen' => 1, 'read_at' => now()]);

        return response()->json(['success' => $affected > 0]);
    }

    /**
     * POST /api/notifications/read-all
     *   ?group=workers   only mark worker.* notifications
     */
    public function markAllRead(Request $request)
    {
        $user   = $request->user();
        $userId = $user->id;
        $q = DB::connection('tenant')->table('notifications')
            ->where('central_user_id', $userId)
            ->where('gelesen', 0);
        $this->applyPermissionFilter($user, $q);

        if ($request->query('group') === 'workers') {
            $q->where('type', 'like', 'worker.%');
        }

        $affected = $q->update(['gelesen' => 1, 'read_at' => now()]);
        return response()->json(['success' => true, 'affected' => $affected]);
    }

    /**
     * GET /api/notifications/preferences
     *   The current user's personal notification settings plus the type
     *   catalog (label + description + group) that drives the
     *   "Benachrichtigungen" tab in Konto & Firma.
     */
    /**
     * Catalog keys the current user may ever receive — mirrors the
     * dispatch targeting + applyPermissionFilter audience map. A user
     * with only Dispo rights sees only Dispo + universal types; owner
     * sees everything (incl. 'owner'-audience rows).
     */
    private function visibleCatalogKeys($user): array
    {
        if ($user->is_owner) return array_keys(NotificationDispatcher::TYPE_CATALOG);

        $can = [
            'dispo'       => $user->hasPermission('auftraege.dispo.view')   || $user->hasPermission('auftraege.dispo.edit'),
            'eingang'     => $user->hasPermission('auftraege.eingang.view') || $user->hasPermission('auftraege.eingang.edit'),
            'mitarbeiter' => $user->hasPermission('stammdaten.mitarbeiter.view') || $user->hasPermission('stammdaten.mitarbeiter.edit'),
        ];

        $keys = [];
        foreach (NotificationDispatcher::TYPE_CATALOG as $key => $meta) {
            $aud = $meta['aud'] ?? 'all';
            if ($aud === 'all' || !empty($can[$aud])) $keys[] = $key;
        }
        return $keys;
    }

    public function preferences(Request $request)
    {
        $prefs = $request->user()->notification_prefs;
        if (!is_array($prefs)) $prefs = [];

        $visible = array_flip($this->visibleCatalogKeys($request->user()));
        $catalog = [];
        foreach (NotificationDispatcher::TYPE_CATALOG as $key => $meta) {
            if (!isset($visible[$key])) continue;
            $catalog[] = ['key' => $key] + $meta;
        }

        return response()->json([
            'prefs'   => [
                'email' => $prefs['email'] ?? true,
                'app'   => $prefs['app'] ?? true,
                'push'  => $prefs['push'] ?? true,
                'types' => (isset($prefs['types']) && is_array($prefs['types'])) ? $prefs['types'] : new \stdClass(),
            ],
            'catalog' => $catalog,
        ]);
    }

    /**
     * PUT /api/notifications/preferences
     *   { email: bool, app: bool, push: bool,
     *     types: { "<catalog key>": {email:bool, app:bool, push:bool} } }
     *   Only catalog keys are stored; unknown keys are dropped silently.
     *   Per-user, per-tenant — never touches other users.
     */
    public function savePreferences(Request $request)
    {
        $user = $request->user();

        $in = $request->input();
        if (!is_array($in)) {
            return response()->json(['error' => 'Ungültige Daten'], 422);
        }

        $clean = [
            'email' => array_key_exists('email', $in) ? (bool) $in['email'] : true,
            'app'   => array_key_exists('app', $in) ? (bool) $in['app'] : true,
            'push'  => array_key_exists('push', $in) ? (bool) $in['push'] : true,
            'types' => new \stdClass(),
        ];

        $allowed = $this->visibleCatalogKeys($user);
        $types = [];
        if (isset($in['types']) && is_array($in['types'])) {
            foreach ($in['types'] as $key => $row) {
                if (!in_array($key, $allowed, true) || !is_array($row)) continue;
                $cleanRow = [];
                if (array_key_exists('email', $row)) $cleanRow['email'] = (bool) $row['email'];
                if (array_key_exists('app', $row))   $cleanRow['app']   = (bool) $row['app'];
                if (array_key_exists('push', $row))  $cleanRow['push']  = (bool) $row['push'];
                if ($cleanRow) $types[$key] = $cleanRow;
            }
        }

        // Preserve stored prefs for catalog types the user can't currently
        // see — hiding a toggle must not erase a choice that becomes
        // relevant again if the permission is restored.
        $existing = $user->notification_prefs['types'] ?? null;
        if (is_array($existing)) {
            $knownKeys = array_keys(NotificationDispatcher::TYPE_CATALOG);
            foreach ($existing as $key => $row) {
                if (!isset($types[$key]) && in_array($key, $knownKeys, true) && is_array($row)) {
                    $types[$key] = $row;
                }
            }
        }
        $clean['types'] = $types ?: new \stdClass();

        $user->notification_prefs = $clean;
        $user->save();

        return response()->json(['success' => true, 'prefs' => $clean]);
    }

    /**
     * POST /api/notifications/push/subscribe
     *   { provider: 'fcm'|'apns'|'webpush', endpoint: <token|url>,
     *     keys: {p256dh, auth} /* webpush only *\/ }
     *
     * Registers THIS device for the current central_user. Called from the
     * web dashboard when it runs inside the native app's Capacitor WebView
     * (admin login path — see worker-app initAdminMode): the Capacitor
     * bridge stays injectable on the remote page, so PushNotifications is
     * available to index.php without rebuilding the app.
     */
    public function pushSubscribe(Request $request)
    {
        $data = $request->validate([
            'provider'    => 'nullable|string|in:webpush,fcm,apns',
            'endpoint'    => 'required|string|max:2000',
            'keys.p256dh' => 'required_if:provider,webpush|nullable|string|max:255',
            'keys.auth'   => 'required_if:provider,webpush|nullable|string|max:100',
        ]);

        if (!\App\Services\CentralPushService::ready()) {
            return response()->json(['error' => 'Push nicht verfügbar'], 409);
        }

        \App\Services\CentralPushService::subscribe(
            (int) $request->user()->id,
            $data['endpoint'],
            $data['keys']['p256dh'] ?? null,
            $data['keys']['auth'] ?? null,
            $data['provider'] ?? 'fcm'
        );

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/notifications/push/unsubscribe
     *   { endpoint: <token|url> } — removes THIS device for the user.
     */
    public function pushUnsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2000']);
        \App\Services\CentralPushService::unsubscribe((int) $request->user()->id, $data['endpoint']);
        return response()->json(['success' => true]);
    }
}
