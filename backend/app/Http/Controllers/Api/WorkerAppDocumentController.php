<?php

namespace App\Http\Controllers\Api;

use App\Helpers\NotificationDispatcher;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Worker side of the document-renewal workflow (worker app).
 *
 * Endpoints (middleware: worker.auth + tenant.boundary):
 *  GET  /worker-app/doc-requests               my renewal requests
 *  POST /worker-app/doc-requests/{id}/upload   upload the replacement PDF
 *
 * A worker may upload when the request status is 'angefordert' or
 * 'abgelehnt' (re-upload after rejection). The upload flips the status to
 * 'hochgeladen' and notifies HR — it does NOT go live until approved.
 */
class WorkerAppDocumentController extends Controller
{
    private function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('mitarbeiter_dokument_anfragen');
    }

    // ============================================================
    // LIST — my document requests, open first
    // ============================================================
    public function index(Request $request)
    {
        if (!$this->ready()) return response()->json(['items' => [], 'open_count' => 0]);

        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $items = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
            ->where('dienst_nr', $dienstNr)
            ->orderByRaw("FIELD(status,'angefordert','abgelehnt','hochgeladen','genehmigt')")
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'titel', 'nachricht', 'status', 'dateiname',
                   'ablaufdatum_neu', 'ablehnungsgrund', 'hochgeladen_am', 'created_at']);

        $openCount = $items->whereIn('status', ['angefordert', 'abgelehnt'])->count();

        return response()->json(['items' => $items->values(), 'open_count' => $openCount]);
    }

    // ============================================================
    // UPLOAD — replacement PDF (pending HR review)
    // ============================================================
    public function upload(Request $request, int $id)
    {
        if (!$this->ready()) return response()->json(['error' => 'Nicht verfügbar'], 404);

        $dienstNr = $request->attributes->get('worker_dienst_nr');

        $row = DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
            ->where('id', $id)->where('dienst_nr', $dienstNr)->first();
        if (!$row) return response()->json(['error' => 'Anfrage nicht gefunden'], 404);

        if (!in_array($row->status, ['angefordert', 'abgelehnt'], true)) {
            return response()->json([
                'error' => $row->status === 'hochgeladen'
                    ? 'Dokument wurde bereits hochgeladen und wird geprüft.'
                    : 'Diese Anfrage ist bereits abgeschlossen.',
            ], 409);
        }

        $request->validate([
            'dokument'    => 'required|file|mimes:pdf|max:10240',
            'ablaufdatum' => 'nullable|date',
        ]);

        $file = $request->file('dokument');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = 'renewal_' . $id . '_' . $safeName . '_' . time() . '.pdf';
        $dir      = 'uploads/workers/' . $dienstNr . '/renewals';
        $filepath = $dir . '/' . $filename;

        $file->storeAs($dir, $filename, 'public');

        // Replace a previously uploaded (e.g. rejected) file.
        if (!empty($row->dateipfad) && $row->dateipfad !== $filepath) {
            try {
                Storage::disk('public')->delete($row->dateipfad);
                \App\Services\DocumentThumbnailer::delete('public', $row->dateipfad);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        DB::connection('tenant')->table('mitarbeiter_dokument_anfragen')
            ->where('id', $id)
            ->update([
                'status'          => 'hochgeladen',
                'dateiname'       => $file->getClientOriginalName(),
                'dateipfad'       => $filepath,
                'dateigroesse'    => $file->getSize(),
                'ablaufdatum_neu' => $request->input('ablaufdatum') ?: null,
                'hochgeladen_am'  => now(),
                'updated_at'      => now(),
            ]);

        try {
            DB::connection('tenant')->table('mitarbeiter_dokument_anfragen_log')->insert([
                'anfrage_id' => $id,
                'aktion' => 'uploaded',
                'sender' => 'Mitarbeiter',
            ]);
        } catch (\Throwable $e) {
            Log::warning('doc-renewal upload log insert failed', ['id' => $id, 'error' => $e->getMessage()]);
        }

        // Notify HR managers (in-app bell + email) that a review is pending.
        try {
            $tenant = \App\Services\TenantService::current();
            $worker = DB::connection('tenant')->table('mitarbeiter')
                ->where('dienst_nr', $dienstNr)->first();
            $workerName = $worker ? trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? '')) : $dienstNr;

            NotificationDispatcher::notifyMitarbeiterManagers(
                (int) $tenant->id,
                'dokument.hochgeladen',
                'Dokument zur Prüfung: ' . $row->titel,
                "{$workerName} ({$dienstNr}) hat das Dokument \"{$row->titel}\" hochgeladen.\n"
                . "Bitte prüfen und genehmigen Sie es unter Stammdaten → Mitarbeiter → Dokumente.",
                '#workers?dienst_nr=' . urlencode($dienstNr) . '&tab=dokumente',
                ['anfrage_id' => $id, 'dienst_nr' => $dienstNr]
            );
        } catch (\Throwable $e) {
            Log::warning('WorkerAppDocument upload notify failed', ['id' => $id, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'status' => 'hochgeladen']);
    }
}
