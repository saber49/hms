<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkerMessageController extends Controller
{
    public function inbox(Request $request)
    {
        // Per-user Bereich restriction — a restricted dispatcher only sees
        // threads of workers inside his scope (pool + tagged + assigned).
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');

        $threads = DB::connection('tenant')->select("
            SELECT m.dienst_nr,
                   CONCAT(mi.vorname, ' ', mi.name) AS worker_name,
                   mi.foto, mi.bereich,
                   MAX(m.created_at) AS letzte_nachricht,
                   COUNT(CASE WHEN m.richtung = 'worker' AND m.gelesen = 0 THEN 1 END) AS unread,
                   (SELECT m2.nachricht FROM mitarbeiter_nachrichten m2 WHERE m2.dienst_nr = m.dienst_nr ORDER BY m2.created_at DESC LIMIT 1) AS letzte_text,
                   (SELECT m2.richtung FROM mitarbeiter_nachrichten m2 WHERE m2.dienst_nr = m.dienst_nr ORDER BY m2.created_at DESC LIMIT 1) AS letzte_richtung
            FROM mitarbeiter_nachrichten m
            JOIN mitarbeiter mi ON mi.dienst_nr = m.dienst_nr
            WHERE $wSql
            GROUP BY m.dienst_nr, mi.vorname, mi.name, mi.foto, mi.bereich
            ORDER BY letzte_nachricht DESC
        ", $wParams);

        $unreadCount = DB::connection('tenant')->table('mitarbeiter_nachrichten as mn')
            ->join('mitarbeiter as mi', 'mi.dienst_nr', '=', 'mn.dienst_nr')
            ->whereRaw($wSql, $wParams)
            ->where('mn.richtung', 'worker')->where('mn.gelesen', 0)->count();

        return response()->json(['threads' => $threads, 'total_unread' => $unreadCount]);
    }

    public function workers(Request $request)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');
        $workers = DB::connection('tenant')->select("
            SELECT mi.dienst_nr,
                   CONCAT(mi.vorname, ' ', mi.name) AS worker_name,
                   mi.foto, mi.bereich,
                   EXISTS(SELECT 1 FROM mitarbeiter_nachrichten m WHERE m.dienst_nr = mi.dienst_nr) AS has_thread
            FROM mitarbeiter mi
            WHERE mi.aktiv = 1 AND $wSql
            ORDER BY mi.vorname, mi.name
        ", $wParams);

        return response()->json(['workers' => $workers]);
    }

    public function thread(Request $request)
    {
        $dienstNr = $request->query('dienst_nr');
        if (!$dienstNr) return response()->json(['error' => 'dienst_nr erforderlich'], 400);
        if (!BereichScope::allowsWorker($request->user(), $dienstNr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $messages = DB::connection('tenant')
            ->table('mitarbeiter_nachrichten as mn')
            ->leftJoin('users as u', 'u.id', '=', 'mn.admin_user_id')
            ->where('mn.dienst_nr', $dienstNr)
            ->select('mn.*', 'u.full_name as admin_name')
            ->orderBy('mn.created_at')
            ->get();

        DB::connection('tenant')->table('mitarbeiter_nachrichten')
            ->where('dienst_nr', $dienstNr)->where('richtung', 'worker')->where('gelesen', 0)
            ->update(['gelesen' => 1]);

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $dienstNr)
            ->select('dienst_nr', 'vorname', 'name', 'foto', 'bereich')
            ->first();

        return response()->json(['nachrichten' => $messages, 'worker' => $worker]);
    }

    public function reply(Request $request)
    {
        $data = $request->all();
        $dienstNr = $data['dienst_nr'] ?? '';
        $nachricht = trim($data['nachricht'] ?? '');

        if (!$dienstNr || !$nachricht) {
            return response()->json(['error' => 'dienst_nr und nachricht erforderlich'], 400);
        }
        if (!BereichScope::allowsWorker($request->user(), $dienstNr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $id = DB::connection('tenant')->table('mitarbeiter_nachrichten')->insertGetId([
            'dienst_nr' => $dienstNr,
            'richtung' => 'admin',
            'nachricht' => $nachricht,
            'admin_user_id' => null,
        ]);

        $this->pushWorkerMessageNotification($dienstNr, $nachricht);

        return response()->json(['success' => true, 'id' => $id], 201);
    }

    public function unread(Request $request)
    {
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'mi');
        $count = DB::connection('tenant')->table('mitarbeiter_nachrichten as mn')
            ->join('mitarbeiter as mi', 'mi.dienst_nr', '=', 'mn.dienst_nr')
            ->whereRaw($wSql, $wParams)
            ->where('mn.richtung', 'worker')->where('mn.gelesen', 0)->count();

        return response()->json(['unread' => $count]);
    }

    public function sendDocument(Request $request)
    {
        $data = $request->all();
        $dienstNr = $data['dienst_nr'] ?? '';
        $nachricht = trim($data['nachricht'] ?? 'Stundennachweis');
        $htmlContent = $data['html_content'] ?? '';
        $dateiName = $data['datei_name'] ?? 'stundennachweis.html';

        if (!$dienstNr || !$htmlContent) {
            return response()->json(['error' => 'dienst_nr und html_content erforderlich'], 400);
        }
        if (!BereichScope::allowsWorker($request->user(), $dienstNr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($dateiName, PATHINFO_FILENAME));
        $fileName = $safeName . '_' . date('Ymd_His') . '.html';
        $filePath = 'uploads/documents/' . $dienstNr . '/' . $fileName;

        Storage::disk('public')->put($filePath, $htmlContent);

        $id = DB::connection('tenant')->table('mitarbeiter_nachrichten')->insertGetId([
            'dienst_nr' => $dienstNr,
            'richtung' => 'admin',
            'nachricht' => $nachricht,
            'admin_user_id' => null,
            'datei_pfad' => $filePath,
            'datei_name' => $dateiName,
        ]);

        $this->pushWorkerMessageNotification($dienstNr, $nachricht . ' 📎');

        return response()->json(['success' => true, 'id' => $id]);
    }

    /**
     * In-app (bell/banner) notification so the worker app can surface a new
     * office message like a chat push. Best-effort — chat delivery itself
     * never depends on it. No email: the chat cadence would spam mailboxes.
     */
    private function pushWorkerMessageNotification(string $dienstNr, string $nachricht): void
    {
        try {
            $worker = DB::connection('tenant')->table('mitarbeiter')
                ->where('dienst_nr', $dienstNr)->first(['id', 'dienst_nr']);
            if (!$worker) return;

            $preview = mb_substr($nachricht, 0, 120) . (mb_strlen($nachricht) > 120 ? '…' : '');

            DB::connection('tenant')->table('worker_notifications')->insert([
                'mitarbeiter_id' => $worker->id,
                'dienst_nr'      => $worker->dienst_nr,
                'type'           => 'message.neu',
                'title'          => 'Neue Nachricht vom Büro',
                'body'           => $preview,
                'payload'        => json_encode([], JSON_UNESCAPED_UNICODE),
                'gelesen'        => 0,
                'created_at'     => now(),
            ]);

            // Real push to the worker's devices (works with app closed).
            // Queued — the FCM/APNs round-trip must not delay the chat reply.
            \App\Jobs\SendWorkerPush::dispatch(
                (int) \App\Services\TenantService::current()->id,
                (string) $worker->dienst_nr, 'Neue Nachricht vom Büro', $preview, 'chat'
            );
        } catch (\Throwable $e) {
            \Log::warning('pushWorkerMessageNotification failed: ' . $e->getMessage());
        }
    }
}
