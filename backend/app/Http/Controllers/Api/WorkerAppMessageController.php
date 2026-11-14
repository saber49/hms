<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkerAppMessageController extends Controller
{
    /**
     * GET — List worker's own conversation thread.
     */
    public function index(Request $request)
    {
        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $messages = DB::connection('tenant')->select("
            SELECT mn.*, u.full_name AS admin_name
            FROM mitarbeiter_nachrichten mn
            LEFT JOIN users u ON u.id = mn.admin_user_id
            WHERE mn.dienst_nr = ?
            ORDER BY mn.created_at ASC
        ", [$dienstNr]);

        // Mark admin messages as read
        DB::connection('tenant')->table('mitarbeiter_nachrichten')
            ->where('dienst_nr', $dienstNr)
            ->where('richtung', 'admin')
            ->where('gelesen', 0)
            ->update(['gelesen' => 1]);

        $unread = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) as cnt FROM mitarbeiter_nachrichten
            WHERE dienst_nr = ? AND richtung = 'admin' AND gelesen = 0
        ", [$dienstNr]);

        return response()->json([
            'nachrichten' => $messages,
            'unread'      => (int)($unread->cnt ?? 0),
        ]);
    }

    /**
     * GET — Unread office messages (chat tab badge). Read-only: unlike
     * index() it never marks anything as read.
     */
    public function unreadCount(Request $request)
    {
        $cnt = DB::connection('tenant')->table('mitarbeiter_nachrichten')
            ->where('dienst_nr', $request->attributes->get('worker_dienst_nr'))
            ->where('richtung', 'admin')
            ->where('gelesen', 0)
            ->count();
        return response()->json(['unread' => $cnt]);
    }

    /**
     * POST — Send message to management.
     */
    public function store(Request $request)
    {
        $dienstNr  = $request->attributes->get('worker_dienst_nr');
        $nachricht = trim($request->input('nachricht', ''));

        if (!$nachricht) {
            return response()->json(['error' => 'Nachricht darf nicht leer sein'], 400);
        }
        if (strlen($nachricht) > 2000) {
            return response()->json(['error' => 'Nachricht zu lang (max. 2000 Zeichen)'], 400);
        }

        $id = DB::connection('tenant')->table('mitarbeiter_nachrichten')->insertGetId([
            'dienst_nr' => $dienstNr,
            'richtung'  => 'worker',
            'nachricht' => $nachricht,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    /**
     * GET /messages/document/{id} — Download/view attached document.
     * This route is outside middleware — authenticates via ?token= query param.
     */
    public function document(Request $request, $id)
    {
        // Manual auth via query token (since this opens in a new browser tab)
        $token = $request->query('token') ?: $request->bearerToken();
        if (!$token) {
            return response('Nicht angemeldet', 401);
        }

        $tokenHash = hash('sha256', $token);
        $pat = DB::connection('mysql')->table('personal_access_tokens')
            ->where('token', $tokenHash)
            ->where('tokenable_type', 'worker')
            ->first();

        if (!$pat) {
            return response('Ungültiges Token', 401);
        }

        $abilities = json_decode($pat->abilities, true) ?? [];
        $tenantId = $abilities['tenant_id'] ?? null;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if (!$tenant || !$tenant->aktiv) {
            return response('Firma nicht gefunden', 403);
        }

        TenantService::switchToTenant($tenant);

        $worker = DB::connection('tenant')->table('mitarbeiter')->where('id', $pat->tokenable_id)->first();
        if (!$worker || !$worker->aktiv || !($worker->app_zugang ?? 0)) {
            return response('Mitarbeiter nicht gefunden', 401);
        }

        $dienstNr = $worker->dienst_nr;

        $msg = DB::connection('tenant')->table('mitarbeiter_nachrichten')
            ->where('id', $id)
            ->where('dienst_nr', $dienstNr)
            ->whereNotNull('datei_pfad')
            ->first();

        if (!$msg || !$msg->datei_pfad) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        if (!Storage::disk('public')->exists($msg->datei_pfad)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }

        // Site photos the worker sent from the chat — serve the image itself.
        if (preg_match('/\.(jpe?g|png|webp)$/i', $msg->datei_pfad)) {
            return response()->file(Storage::disk('public')->path($msg->datei_pfad), [
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        $html = Storage::disk('public')->get($msg->datei_pfad);
        $docName = $msg->datei_name ?: 'Stundennachweis.html';

        $action = $request->query('action', 'view');

        if ($action === 'download') {
            return response($html, 200)
                ->header('Content-Type', 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="' . $docName . '"');
        }

        // View mode — inject a toolbar for share/print
        $toolbar = '
        <div id="docToolbar" style="position:fixed;bottom:0;left:0;right:0;background:#1e3a5f;padding:12px 16px;display:flex;gap:10px;justify-content:center;z-index:9999;box-shadow:0 -2px 10px rgba(0,0,0,.2)">
            <button onclick="shareDoc()" style="flex:1;max-width:180px;padding:10px 14px;background:white;color:#1e3a5f;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
                <i class="fas fa-share-alt"></i> Teilen
            </button>
            <button onclick="window.print()" style="flex:1;max-width:180px;padding:10px 14px;background:white;color:#1e3a5f;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
                <i class="fas fa-print"></i> Drucken
            </button>
        </div>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <script>
        function shareDoc(){if(navigator.share){navigator.share({title:document.title,url:window.location.href}).catch(function(){});}else{window.print();}}
        </script>
        <style>@media print{#docToolbar{display:none!important;}body{padding-bottom:0!important;}}body{padding-bottom:70px!important;}</style>';

        $html = str_replace('</body>', $toolbar . '</body>', $html);

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
