<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    public function conversations(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        if (!$userId) return response()->json(['error' => 'Kein Chat-Profil für dieses Konto gefunden'], 403);

        $conversations = DB::connection('tenant')->select("
            SELECT c.id, c.title, c.created_by, c.created_at, c.updated_at,
                   u.full_name AS creator_name,
                   (SELECT cm.message FROM chat_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.id DESC LIMIT 1) AS last_message,
                   (SELECT cm.created_at FROM chat_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.id DESC LIMIT 1) AS last_message_at,
                   (SELECT cm.sender_id FROM chat_messages cm WHERE cm.conversation_id = c.id ORDER BY cm.id DESC LIMIT 1) AS last_sender_id,
                   (SELECT u2.full_name FROM chat_messages cm2 JOIN users u2 ON u2.id = cm2.sender_id WHERE cm2.conversation_id = c.id ORDER BY cm2.id DESC LIMIT 1) AS last_sender_name,
                   (SELECT COUNT(*) FROM chat_messages cm3 WHERE cm3.conversation_id = c.id AND cm3.created_at > COALESCE(cp.last_read_at, '1970-01-01')) AS unread_count
            FROM chat_conversations c
            JOIN chat_participants cp ON cp.conversation_id = c.id AND cp.user_id = ?
            LEFT JOIN users u ON u.id = c.created_by
            ORDER BY c.updated_at DESC
        ", [$userId]);

        foreach ($conversations as &$conv) {
            // is_creator / is_me: the client's CURRENT_USER.id is the
            // central_users id — a different table than tenant users.id
            // carried by participants — so the client cannot compare
            // ids itself and used to match full_name (breaks on
            // duplicate or renamed names). Flag it here instead.
            $conv->is_creator = (int) $conv->created_by === (int) $userId;
            $conv->participants = DB::connection('tenant')
                ->table('chat_participants as cp')
                ->join('users as u', 'u.id', '=', 'cp.user_id')
                ->where('cp.conversation_id', $conv->id)
                ->select('u.id', 'u.full_name', 'u.role')
                ->get()
                ->map(function ($p) use ($userId) {
                    $p->is_me = (int) $p->id === (int) $userId;
                    return $p;
                });
        }

        return response()->json($conversations);
    }

    public function messages(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        $convId = $request->query('conversation_id');
        if (!$convId) return response()->json(['error' => 'conversation_id erforderlich'], 400);

        $participant = DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->where('user_id', $userId)->first();
        if (!$participant) return response()->json(['error' => 'Kein Zugriff'], 403);

        DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->where('user_id', $userId)
            ->update(['last_read_at' => now()]);

        $msgs = DB::connection('tenant')
            ->table('chat_messages as m')
            ->join('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.conversation_id', $convId)
            ->select('m.id', 'm.conversation_id', 'm.sender_id', 'm.message', 'm.image_path', 'm.created_at', 'u.full_name as sender_name')
            ->orderBy('m.id')
            ->get();

        return response()->json($msgs);
    }

    public function send(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        $convId = $request->input('conversation_id');
        $message = trim($request->input('message', ''));

        if (!$convId || !$message) return response()->json(['error' => 'conversation_id und message erforderlich'], 400);

        // Only participants may post into a conversation — otherwise any
        // authenticated tenant user could write into arbitrary chats by
        // guessing the id (same guard as messages()).
        $participant = DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->where('user_id', $userId)->first();
        if (!$participant) return response()->json(['error' => 'Kein Zugriff'], 403);

        $id = DB::connection('tenant')->table('chat_messages')->insertGetId([
            'conversation_id' => $convId,
            'sender_id' => $userId,
            'message' => $message,
        ]);

        DB::connection('tenant')->table('chat_conversations')->where('id', $convId)->update(['updated_at' => now()]);
        DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->where('user_id', $userId)
            ->update(['last_read_at' => now()]);

        $this->notifyMentions((int) $convId, $userId, $message);

        \App\Helpers\Realtime::changed('chat');
        return response()->json(['success' => true, 'message_id' => $id]);
    }

    /**
     * '@Firstname' (or '@Full Name') in a group chat notifies that member via
     * the in-app bell — link '#chat?conv=<id>' opens the panel straight on
     * this conversation. Best-effort: never blocks sending.
     */
    protected function notifyMentions(int $convId, int $senderId, string $message): void
    {
        if (!str_contains($message, '@')) return;
        try {
            $participants = DB::connection('tenant')->table('chat_participants as cp')
                ->join('users as u', 'u.id', '=', 'cp.user_id')
                ->where('cp.conversation_id', $convId)
                ->where('cp.user_id', '!=', $senderId)
                ->select('u.id', 'u.username', 'u.full_name')
                ->get();
            if ($participants->count() < 2) return;   // direct chat — no mentions

            $hits = [];
            foreach ($participants as $p) {
                $full = trim((string) $p->full_name);
                $first = preg_split('/\s+/u', $full)[0] ?? '';
                if ($first !== '' && preg_match('/@' . preg_quote($first, '/') . '\b/iu', $message)) {
                    $hits[] = $p;
                }
            }
            if (!$hits) return;

            $tenant = \App\Services\TenantService::current();
            if (!$tenant || empty($tenant->id)) return;
            $tenantId = (int) $tenant->id;

            $senderName = DB::connection('tenant')->table('users')->where('id', $senderId)->value('full_name') ?: 'Jemand';
            $convTitle  = DB::connection('tenant')->table('chat_conversations')->where('id', $convId)->value('title');

            // Map tenant.users rows to central user ids — same match the
            // participant picker uses (username OR full_name).
            $centralIds = [];
            foreach ($hits as $p) {
                $cid = DB::table('central_users')
                    ->where('tenant_id', $tenantId)
                    ->where(fn ($q) => $q->where('name', $p->username)->orWhere('full_name', $p->full_name))
                    ->value('id');
                if ($cid) $centralIds[] = (int) $cid;
            }
            if (!$centralIds) return;

            \App\Helpers\NotificationDispatcher::notifyUserIds(
                $tenantId,
                $centralIds,
                'chat.mention',
                $senderName . ' hat dich erwähnt' . ($convTitle ? ' — ' . $convTitle : ''),
                mb_strimwidth($message, 0, 160, '…'),
                '#chat?conv=' . $convId,
                ['conversation_id' => $convId, 'sender' => $senderName]
            );
        } catch (\Throwable $e) {
            Log::warning('chat mention notify failed', ['conv' => $convId, 'error' => $e->getMessage()]);
        }
    }

    public function create(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        $title = trim($request->input('title', ''));
        $participantIds = $request->input('participants', []);
        $firstMessage = trim($request->input('message', ''));

        if (empty($participantIds)) return response()->json(['error' => 'Mindestens ein Teilnehmer erforderlich'], 400);

        if (!in_array($userId, $participantIds)) $participantIds[] = $userId;

        DB::connection('tenant')->beginTransaction();
        try {
            $convId = DB::connection('tenant')->table('chat_conversations')->insertGetId([
                'title' => $title ?: null,
                'created_by' => $userId,
            ]);

            foreach ($participantIds as $pid) {
                DB::connection('tenant')->table('chat_participants')->insert([
                    'conversation_id' => $convId,
                    'user_id' => $pid,
                    'last_read_at' => now(),
                ]);
            }

            if ($firstMessage) {
                DB::connection('tenant')->table('chat_messages')->insert([
                    'conversation_id' => $convId,
                    'sender_id' => $userId,
                    'message' => $firstMessage,
                ]);
            }

            DB::connection('tenant')->commit();
            \App\Helpers\Realtime::changed('chat');
            return response()->json(['success' => true, 'conversation_id' => $convId], 201);
        } catch (\Exception $e) {
            DB::connection('tenant')->rollBack();
            return response()->json(['error' => 'Fehler: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /messages/add-members — add new members to an existing
     * conversation. Any current participant may add; new members see the
     * full history (last_read_at=now() so they start with no unread count).
     */
    public function addMembers(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        $convId = $request->input('conversation_id');
        $newIds = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('participants', [])))));

        if (!$convId || empty($newIds)) {
            return response()->json(['error' => 'conversation_id und participants erforderlich'], 400);
        }

        $participant = DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->where('user_id', $userId)->first();
        if (!$participant) return response()->json(['error' => 'Kein Zugriff'], 403);

        $added = 0;
        foreach ($newIds as $pid) {
            // Only active tenant users can join; silently skip the rest.
            $isUser = DB::connection('tenant')->table('users')
                ->where('id', $pid)->where('aktiv', 1)->exists();
            if (!$isUser) continue;
            $exists = DB::connection('tenant')->table('chat_participants')
                ->where('conversation_id', $convId)->where('user_id', $pid)->exists();
            if ($exists) continue;
            DB::connection('tenant')->table('chat_participants')->insert([
                'conversation_id' => $convId,
                'user_id' => $pid,
                'last_read_at' => now(),
            ]);
            $added++;
        }

        DB::connection('tenant')->table('chat_conversations')->where('id', $convId)->update(['updated_at' => now()]);

        return response()->json(['success' => true, 'added' => $added]);
    }

    public function unread(Request $request)
    {
        $userId = $this->getTenantUserId($request);

        $result = DB::connection('tenant')->selectOne("
            SELECT COALESCE(SUM(sub.unread), 0) AS total_unread FROM (
                SELECT COUNT(*) AS unread
                FROM chat_messages cm
                JOIN chat_participants cp ON cp.conversation_id = cm.conversation_id AND cp.user_id = ?
                WHERE cm.created_at > COALESCE(cp.last_read_at, '1970-01-01')
                  AND cm.sender_id != ?
            ) sub
        ", [$userId, $userId]);

        return response()->json(['unread' => (int)$result->total_unread]);
    }

    public function deleteConversation(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        if (!$userId) return response()->json(['error' => 'Kein Chat-Profil für dieses Konto gefunden'], 403);

        $convId = $request->input('conversation_id');
        if (!$convId) return response()->json(['error' => 'conversation_id erforderlich'], 400);

        // Only a participant of this conversation may delete it — otherwise
        // any authenticated tenant user could delete arbitrary conversations
        // just by guessing the id.
        $participant = DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->where('user_id', $userId)->first();
        if (!$participant) return response()->json(['error' => 'Kein Zugriff'], 403);

        $conv = DB::connection('tenant')->table('chat_conversations')->where('id', $convId)->first();
        if (!$conv) return response()->json(['error' => 'Chat nicht gefunden'], 404);

        $memberCount = DB::connection('tenant')->table('chat_participants')
            ->where('conversation_id', $convId)->count();

        // Group chats (3+ members) may only be deleted by their creator.
        // Other members "leave" instead — the group stays alive for the rest.
        if ($memberCount > 2 && (int) $conv->created_by !== (int) $userId) {
            DB::connection('tenant')->table('chat_participants')
                ->where('conversation_id', $convId)->where('user_id', $userId)->delete();
            return response()->json(['success' => true, 'left' => true]);
        }

        DB::connection('tenant')->table('chat_conversations')->where('id', $convId)->delete();
        \App\Helpers\Realtime::changed('chat');
        return response()->json(['success' => true]);
    }

    public function uploadImage(Request $request)
    {
        $userId = $this->getTenantUserId($request);
        $convId = $request->input('conversation_id');
        if (!$convId) return response()->json(['error' => 'conversation_id erforderlich'], 400);

        $request->validate(['image' => 'required|image|max:5120']);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension();
        $filename = 'chat_' . $convId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filepath = 'uploads/chat/' . $filename;

        $file->storeAs('uploads/chat', $filename, 'public');

        $caption = trim($request->input('message', ''));

        $id = DB::connection('tenant')->table('chat_messages')->insertGetId([
            'conversation_id' => $convId,
            'sender_id' => $userId,
            'message' => $caption ?: null,
            'image_path' => $filepath,
        ]);

        DB::connection('tenant')->table('chat_conversations')->where('id', $convId)->update(['updated_at' => now()]);

        return response()->json(['success' => true, 'message_id' => $id, 'image_path' => $filepath]);
    }

    public function users(Request $request)
    {
        $userId = $this->getTenantUserId($request);

        // Only list tenant users that actually have a live central account
        // for this tenant. This prevents orphan rows in tenant.users
        // (created by tests / manual inserts / failed sync) from leaking
        // into the chat participant picker while being absent from the
        // user management page (which reads from central_users).
        $tenantId = optional($request->user())->tenant_id;

        $centrals = DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)
            ->whereRaw("name NOT LIKE 'deleted\\_%'")
            ->where('aktiv', 1)
            ->get(['name', 'full_name']);

        $liveUsernames = $centrals->pluck('name')->filter()->values();
        $liveFullNames = $centrals->pluck('full_name')->filter()->values();

        // Match by username OR full_name — the owner row in tenant.users is
        // historically seeded with username='admin' regardless of the
        // central account's name, so a username-only join would drop the
        // owner from the picker. full_name is the stable identifier here.
        $users = DB::connection('tenant')->table('users')
            ->where('aktiv', 1)
            ->where('id', '!=', $userId)
            ->where(function ($q) use ($liveUsernames, $liveFullNames) {
                $q->whereIn('username', $liveUsernames)
                  ->orWhereIn('full_name', $liveFullNames);
            })
            ->select('id', 'full_name', 'role')
            ->orderBy('full_name')->get();

        return response()->json($users);
    }

    protected function getTenantUserId(Request $request): ?int
    {
        $central = $request->user();
        if (!$central) return null;

        // Resolve the matching tenant.users row for the authenticated
        // central account. Match by username first (common case), and
        // fall back to full_name so legacy/owner rows still resolve
        // (the owner row is seeded as username='admin' regardless of
        // the central account name). tenant.users has no email column.
        $row = DB::connection('tenant')->table('users')
            ->where(function ($q) use ($central) {
                $q->where('username', $central->name)
                  ->orWhere('full_name', $central->full_name);
            })
            ->where('aktiv', 1)
            ->select('id')
            ->first();
        if ($row) return (int) $row->id;

        // Active central account without its tenant.users mirror (e.g. an
        // owner account whose seeded row was renamed to another person):
        // create the mirror once — the same row UserController::store writes
        // for every new user. Without it every chat call failed and the old
        // 401 made the frontend log the user out.
        if (empty($central->aktiv) || str_starts_with((string) $central->name, 'deleted_')) return null;
        $username = trim((string) $central->name);
        if ($username === '') return null;
        $taken = DB::connection('tenant')->table('users')->where('username', $username)->first();
        if ($taken) {
            // Username exists but inactive / different person — never hijack it.
            return null;
        }
        $isAdmin = !empty($central->is_owner) || in_array($central->role ?? '', ['tenant_admin', 'super_admin'], true);
        $id = DB::connection('tenant')->table('users')->insertGetId([
            'username'      => $username,
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), // tenant.users is not used for login
            'full_name'     => trim((string) ($central->full_name ?? '')) !== '' ? $central->full_name : $username,
            'role'          => $isAdmin ? 'admin' : 'viewer',
            'aktiv'         => 1,
            'created_at'    => now(),
        ]);
        Log::info('Chat: created missing tenant.users mirror', ['central_user_id' => $central->id, 'tenant_user_id' => $id]);
        return (int) $id;
    }
}
