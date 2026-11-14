<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * HR side of the document-renewal workflow.
 *
 * Endpoints (permission: stammdaten.mitarbeiter.*):
 *  GET    /worker-doc-renewals              list requests (?status=)
 *  POST   /worker-doc-renewals/request      ask a worker to renew a document
 *  GET    /worker-doc-renewals/{id}/file    stream the uploaded replacement PDF
 *  POST   /worker-doc-renewals/{id}/approve replacement goes live in mitarbeiter_dokumente
 *  POST   /worker-doc-renewals/{id}/reject  worker must re-upload
 *
 * Status flow: angefordert -> hochgeladen -> genehmigt | abgelehnt
 * (abgelehnt keeps the request open; the worker can upload again).
 */
class WorkerDocRenewalController extends Controller
{
    private function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('mitarbeiter_dokument_anfragen');
    }

    // ============================================================
    // LIST — all renewal requests, newest first
    // ============================================================
    public function index(Request $request)
    {
        if (!$this->ready()) return response()->json([]);

        $q = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen as a')
            ->leftJoin('mitarbeiter as m', 'a.dienst_nr', '=', 'm.dienst_nr')
            // Aggregated resend log: count of resends and the latest one.
            ->leftJoinSub(
                DB::connection('tenant')->table('mitarbeiter_dokument_anfragen_log')
                    ->select('anfrage_id',
                        DB::raw("COUNT(CASE WHEN aktion = 'resend' THEN 1 END) AS resend_count"),
                        DB::raw("MAX(created_at) AS last_resend_at"),
                        DB::raw("(SELECT l2.sender FROM mitarbeiter_dokument_anfragen_log l2 WHERE l2.anfrage_id = mitarbeiter_dokument_anfragen_log.anfrage_id AND l2.aktion = 'resend' ORDER BY l2.created_at DESC LIMIT 1) AS last_resend_von")
                    )
                    ->groupBy('anfrage_id'),
                'l', 'a.id', '=', 'l.anfrage_id'
            )
            ->select('a.*', 'm.vorname', 'm.name', 'm.email',
                DB::raw("COALESCE(l.resend_count, 0) AS resend_count"),
                'l.last_resend_at',
                'l.last_resend_von'
            )
            ->orderByDesc('a.created_at');

        // Bereich scope: only requests of in-scope workers.
        [$wSql, $wParams] = BereichScope::workerPredicate($request->user(), 'm');
        $q->whereRaw($wSql, $wParams);

        if ($status = $request->query('status')) {
            $q->whereIn('a.status', array_filter(explode(',', $status)));
        }

        return response()->json($q->limit(500)->get());
    }

    // ============================================================
    // REQUEST — HR asks the worker for a renewal (email + in-app)
    // ============================================================
    public function requestRenewal(Request $request)
    {
        if (!$this->ready()) {
            return response()->json(['error' => 'Funktion noch nicht verfügbar (Migration fehlt)'], 409);
        }

        $data = $request->validate([
            'dienst_nr'   => 'required|string|max:30',
            'dokument_id' => 'nullable|integer',
            'titel'       => 'required|string|max:190',
            'nachricht'   => 'nullable|string|max:2000',
        ]);

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $data['dienst_nr'])->first();
        if (!$worker || !BereichScope::allowsWorker($request->user(), (string) $data['dienst_nr'])) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        // One open request per document/title — don't spam the worker.
        $open = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
            ->where('dienst_nr', $data['dienst_nr'])
            ->whereIn('status', ['angefordert', 'hochgeladen', 'abgelehnt'])
            ->when(!empty($data['dokument_id']),
                fn($q) => $q->where('dokument_id', $data['dokument_id']),
                fn($q) => $q->where('titel', $data['titel']))
            ->first();
        if ($open) {
            return response()->json([
                'error'  => 'Für dieses Dokument läuft bereits eine Anfrage (Status: ' . $open->status . ')',
                'id'     => $open->id,
                'status' => $open->status,
            ], 409);
        }

        $requestedBy = trim((string) ($request->user()->full_name ?? $request->user()->name ?? '')) ?: 'HR';

        $id = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->insertGetId([
            'dienst_nr'       => $data['dienst_nr'],
            'dokument_id'     => $data['dokument_id'] ?? null,
            'titel'           => $data['titel'],
            'nachricht'       => $data['nachricht'] ?? null,
            'status'          => 'angefordert',
            'angefordert_von' => $requestedBy,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->logNotify((int) $id, 'requested', $requestedBy);
        $this->notifyWorkerRequested($worker, $data['titel'], $data['nachricht'] ?? null);

        return response()->json(['success' => true, 'id' => $id]);
    }

    // ============================================================
    // FILE — stream the uploaded replacement (HR review)
    // ============================================================
    public function file(Request $request, int $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Nicht gefunden'], 404);

        $row = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->where('id', $id)->first();
        if (!$row || empty($row->dateipfad)
            || !BereichScope::allowsWorker($request->user(), (string) $row->dienst_nr)) {
            return response()->json(['error' => 'Keine Datei vorhanden'], 404);
        }
        if (!str_starts_with($row->dateipfad, 'uploads/workers/')) {
            return response()->json(['error' => 'Zugriff verweigert'], 403);
        }
        if (!Storage::disk('public')->exists($row->dateipfad)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }

        return response()->file(Storage::disk('public')->path($row->dateipfad), [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => 'inline; filename="' . ($row->dateiname ?: 'dokument.pdf') . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ============================================================
    // APPROVE — replacement becomes the official document
    // ============================================================
    public function approve(Request $request, int $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Nicht gefunden'], 404);

        $data = $request->validate([
            'ablaufdatum' => 'nullable|date',
        ]);

        $row = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->where('id', $id)->first();
        if (!$row || !BereichScope::allowsWorker($request->user(), (string) $row->dienst_nr)) {
            return response()->json(['error' => 'Anfrage nicht gefunden'], 404);
        }
        if ($row->status !== 'hochgeladen') {
            return response()->json(['error' => 'Nur hochgeladene Dokumente können genehmigt werden (Status: ' . $row->status . ')'], 409);
        }
        if (empty($row->dateipfad)) {
            return response()->json(['error' => 'Keine Datei vorhanden'], 409);
        }

        $reviewer    = trim((string) ($request->user()->full_name ?? $request->user()->name ?? '')) ?: 'HR';
        $ablaufdatum = $data['ablaufdatum'] ?? ($row->ablaufdatum_neu ?: null);

        DB::connection('tenant')->beginTransaction();
        try {
            $oldPath = null;
            $existing = $row->dokument_id
                ? DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $row->dokument_id)->first()
                : null;

            if ($existing) {
                // Replace the old document in place — keeps its id/history.
                $oldPath = $existing->dateipfad;
                DB::connection('tenant')->table('mitarbeiter_dokumente')
                    ->where('id', $existing->id)
                    ->update([
                        'titel'          => $row->titel,
                        'dateiname'      => $row->dateiname,
                        'dateipfad'      => $row->dateipfad,
                        'dateigroesse'   => $row->dateigroesse,
                        'ablaufdatum'    => $ablaufdatum,
                        'hochgeladen_am' => now(),
                    ]);
            } else {
                DB::connection('tenant')->table('mitarbeiter_dokumente')->insert([
                    'dienst_nr'    => $row->dienst_nr,
                    'titel'        => $row->titel,
                    'dateiname'    => $row->dateiname,
                    'dateipfad'    => $row->dateipfad,
                    'dateigroesse' => $row->dateigroesse,
                    'ablaufdatum'  => $ablaufdatum,
                ]);
            }

            DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
                ->where('id', $id)
                ->update([
                    'status'       => 'genehmigt',
                    'geprueft_von' => $reviewer,
                    'geprueft_am'  => now(),
                    'updated_at'   => now(),
                ]);

            DB::connection('tenant')->commit();

            // Best-effort cleanup of the replaced file + thumbnails.
            if ($oldPath && $oldPath !== $row->dateipfad) {
                try {
                    Storage::disk('public')->delete($oldPath);
                    \App\Services\DocumentThumbnailer::delete('public', $oldPath);
                } catch (\Throwable $e) { /* non-fatal */ }
            }
            try {
                \App\Services\DocumentThumbnailer::generate('public', $row->dateipfad);
            } catch (\Throwable $e) { /* non-fatal */ }
        } catch (\Throwable $e) {
            DB::connection('tenant')->rollBack();
            Log::error('WorkerDocRenewal approve failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Genehmigung fehlgeschlagen'], 500);
        }

        $worker = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $row->dienst_nr)->first();
        if ($worker) {
            NotificationDispatcher::notifyWorker(
                $worker,
                'dokument.genehmigt',
                'Dokument genehmigt: ' . $row->titel,
                "Ihr hochgeladenes Dokument \"{$row->titel}\" wurde geprüft und genehmigt."
                . ($ablaufdatum ? "\nNeues Ablaufdatum: " . \Carbon\Carbon::parse($ablaufdatum)->format('d.m.Y') : '')
                . "\nVielen Dank!",
                ['anfrage_id' => $id]
            );
        }

        return response()->json(['success' => true]);
    }

    // ============================================================
    // REJECT — worker must upload again
    // ============================================================
    public function reject(Request $request, int $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Nicht gefunden'], 404);

        $data = $request->validate([
            'grund' => 'required|string|max:2000',
        ]);

        $row = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->where('id', $id)->first();
        if (!$row || !BereichScope::allowsWorker($request->user(), (string) $row->dienst_nr)) {
            return response()->json(['error' => 'Anfrage nicht gefunden'], 404);
        }
        if ($row->status !== 'hochgeladen') {
            return response()->json(['error' => 'Nur hochgeladene Dokumente können abgelehnt werden (Status: ' . $row->status . ')'], 409);
        }

        $reviewer = trim((string) ($request->user()->full_name ?? $request->user()->name ?? '')) ?: 'HR';

        // Remove the rejected file so a fresh upload starts clean.
        if (!empty($row->dateipfad)) {
            try {
                Storage::disk('public')->delete($row->dateipfad);
                \App\Services\DocumentThumbnailer::delete('public', $row->dateipfad);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
            ->where('id', $id)
            ->update([
                'status'          => 'abgelehnt',
                'ablehnungsgrund' => $data['grund'],
                'dateiname'       => null,
                'dateipfad'       => null,
                'dateigroesse'    => null,
                'ablaufdatum_neu' => null,
                'geprueft_von'    => $reviewer,
                'geprueft_am'     => now(),
                'updated_at'      => now(),
            ]);

        $worker = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $row->dienst_nr)->first();
        if ($worker) {
            NotificationDispatcher::notifyWorker(
                $worker,
                'dokument.abgelehnt',
                'Dokument abgelehnt: ' . $row->titel,
                "Ihr hochgeladenes Dokument \"{$row->titel}\" wurde abgelehnt.\n"
                . "Grund: {$data['grund']}\n"
                . "Bitte laden Sie das Dokument erneut über die Mitarbeiter-App hoch.",
                ['anfrage_id' => $id]
            );
        }

        return response()->json(['success' => true]);
    }

    // ============================================================
    // RESEND — HR wants to remind the worker again. The existing
    // request stays open; we just re-deliver the notification.
    // ============================================================
    public function resend(Request $request, int $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Nicht gefunden'], 404);

        $row = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->where('id', $id)->first();
        if (!$row || !BereichScope::allowsWorker($request->user(), (string) $row->dienst_nr)) {
            return response()->json(['error' => 'Anfrage nicht gefunden'], 404);
        }

        if (!in_array($row->status, ['angefordert', 'abgelehnt'])) {
            return response()->json(['error' => 'Nur offene Anfragen können erneut benachrichtigt werden (Status: ' . $row->status . ')'], 409);
        }

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $row->dienst_nr)->first();
        if (!$worker) return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);

        // Update the requested-at timestamp so the UI shows the latest resend.
        DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
            ->where('id', $id)
            ->update(['updated_at' => now()]);

        $sender = trim((string) ($request->user()->full_name ?? $request->user()->name ?? '')) ?: 'HR';
        $this->logNotify($id, 'resend', $sender);

        self::notifyWorkerRequested($worker, $row->titel, $row->nachricht);

        return response()->json(['success' => true]);
    }

    private function logNotify(int $anfrageId, string $aktion, string $sender): void
    {
        try {
            DB::connection('tenant')->table('mitarbeiter_dokument_anfragen_log')->insert([
                'anfrage_id' => $anfrageId,
                'aktion'     => $aktion,
                'sender'     => $sender,
            ]);
        } catch (\Throwable $e) {
            Log::warning('doc-renewal log insert failed', ['anfrage_id' => $anfrageId, 'error' => $e->getMessage()]);
        }
    }
    // ============================================================
    // LOG — audit trail for one request (who sent/resend when)
    // ============================================================
    public function log(Request $request, int $id)
    {
        if (!$this->ready()) return response()->json([]);

        $row = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')->where('id', $id)->first();
        if (!$row) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);

        $rows = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen_log')
            ->where('anfrage_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'anfrage_id' => $id,
            'dienst_nr'  => $row->dienst_nr,
            'titel'      => $row->titel,
            'log'        => $rows,
        ]);
    }

    // ============================================================
    public static function notifyWorkerRequested($worker, string $titel, ?string $nachricht = null): void
    {
        NotificationDispatcher::notifyWorker(
            $worker,
            'dokument.angefordert',
            'Dokument benötigt: ' . $titel,
            "Ihr Dokument \"{$titel}\" ist abgelaufen oder läuft bald ab.\n"
            . ($nachricht ? $nachricht . "\n" : '')
            . "Bitte öffnen Sie die Mitarbeiter-App und laden Sie unter \"Profil → Dokumente\" "
            . "eine aktuelle Version als PDF hoch. Das Dokument wird anschließend von der "
            . "Personalabteilung geprüft und freigegeben.",
            ['titel' => $titel]
        );
    }
}
