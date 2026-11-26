<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class WorkerDocumentController extends Controller
{
    private function isArchivedVersionPath(?string $path, int $tenantId): bool
    {
        $prefix = 'worker-document-versions/' . $tenantId . '/';
        return is_string($path)
            && str_starts_with($path, $prefix)
            && preg_match('/^[a-f0-9-]{36}\.pdf$/i', substr($path, strlen($prefix))) === 1;
    }

    public function index(Request $request)
    {
        $dienst_nr = $request->query('dienst_nr');
        if (!$dienst_nr) return response()->json(['error' => 'dienst_nr erforderlich'], 400);
        if (!BereichScope::allowsWorker($request->user(), (string) $dienst_nr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $docs = DB::connection('tenant')->table('mitarbeiter_dokumente')
            ->where('dienst_nr', $dienst_nr)->orderByDesc('hochgeladen_am')->get();

        return response()->json($docs);
    }

    public function versions(Request $request, int $id)
    {
        TenantService::assertActive();
        $doc = DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $id)->first();
        if (!$doc || !BereichScope::allowsWorker($request->user(), (string) $doc->dienst_nr)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }
        if (!Schema::connection('tenant')->hasTable('mitarbeiter_dokument_versionen')) {
            return response()->json([]);
        }

        return response()->json(DB::connection('tenant')->table('mitarbeiter_dokument_versionen')
            ->where('dokument_id', $id)->where('dienst_nr', $doc->dienst_nr)
            ->select('id', 'titel', 'dateiname', 'ablaufdatum', 'hochgeladen_am', 'archiviert_am', 'archiviert_von')
            ->orderByDesc('archiviert_am')->get());
    }

    public function versionFile(Request $request, int $id, int $versionId)
    {
        TenantService::assertActive();
        $doc = DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $id)->first();
        if (!$doc || !BereichScope::allowsWorker($request->user(), (string) $doc->dienst_nr)
            || !Schema::connection('tenant')->hasTable('mitarbeiter_dokument_versionen')) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }
        $version = DB::connection('tenant')->table('mitarbeiter_dokument_versionen')
            ->where('id', $versionId)->where('dokument_id', $id)
            ->where('dienst_nr', $doc->dienst_nr)->first();
        if (!$version || !$this->isArchivedVersionPath($version->dateipfad, (int) TenantService::assertActive()->id)
            || !Storage::disk('local')->exists($version->dateipfad)) {
            return response()->json(['error' => 'Version nicht gefunden'], 404);
        }

        return response()->file(Storage::disk('local')->path($version->dateipfad), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dokument-version.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(Request $request)
    {
        $dienst_nr = $request->input('dienst_nr');
        $titel = $request->input('titel');
        $ablaufdatum = $request->input('ablaufdatum');

        if (!$dienst_nr || !$titel) return response()->json(['error' => 'dienst_nr und titel erforderlich'], 400);
        if (!BereichScope::allowsWorker($request->user(), (string) $dienst_nr)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden'], 404);
        }

        $request->validate(['dokument' => 'required|file|mimes:pdf|max:10240']);

        $file = $request->file('dokument');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $safeName . '_' . time() . '.pdf';
        $filepath = 'uploads/workers/' . $dienst_nr . '/' . $filename;

        $file->storeAs('uploads/workers/' . $dienst_nr, $filename, 'public');

        // Best-effort thumbnail (PDFs only here — mimes is restricted to pdf).
        \App\Services\DocumentThumbnailer::generate('public', $filepath);

        $id = DB::connection('tenant')->table('mitarbeiter_dokumente')->insertGetId([
            'dienst_nr' => $dienst_nr,
            'titel' => $titel,
            'dateiname' => $file->getClientOriginalName(),
            'dateipfad' => $filepath,
            'dateigroesse' => $file->getSize(),
            'ablaufdatum' => $ablaufdatum ?: null,
        ]);

        return response()->json(['success' => true, 'id' => $id, 'dateipfad' => $filepath]);
    }

    public function update(Request $request)
    {
        $data = $request->all();
        $docId = $data['id'] ?? null;
        if (!$docId) return response()->json(['error' => 'Dokument-ID erforderlich'], 400);

        $fields = [];
        $params = [];
        if (isset($data['titel'])) { $fields['titel'] = $data['titel']; }
        if (array_key_exists('ablaufdatum', $data)) { $fields['ablaufdatum'] = $data['ablaufdatum'] ?: null; }

        if (empty($fields)) return response()->json(['error' => 'Keine Felder zum Aktualisieren'], 400);

        $doc = DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $docId)->first();
        if (!$doc || !BereichScope::allowsWorker($request->user(), (string) $doc->dienst_nr)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $docId)->update($fields);
        return response()->json(['success' => true]);
    }

    public function destroy(Request $request)
    {
        TenantService::assertActive();
        $data = $request->all();
        $docId = $data['id'] ?? null;
        if (!$docId) return response()->json(['error' => 'Dokument-ID erforderlich'], 400);

        $doc = DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $docId)->first();
        if (!$doc || !BereichScope::allowsWorker($request->user(), (string) $doc->dienst_nr)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        if (Schema::connection('tenant')->hasTable('mitarbeiter_dokument_versionen')) {
            $versions = DB::connection('tenant')->table('mitarbeiter_dokument_versionen')
                ->where('dokument_id', $docId)->where('dienst_nr', $doc->dienst_nr)->get();
            foreach ($versions as $version) {
                if ($this->isArchivedVersionPath($version->dateipfad, (int) TenantService::assertActive()->id)) {
                    Storage::disk('local')->delete($version->dateipfad);
                }
            }
            DB::connection('tenant')->table('mitarbeiter_dokument_versionen')
                ->where('dokument_id', $docId)->where('dienst_nr', $doc->dienst_nr)->delete();
        }

        if ($doc->dateipfad) {
            Storage::disk('public')->delete($doc->dateipfad);
            \App\Services\DocumentThumbnailer::delete('public', $doc->dateipfad);
        }

        DB::connection('tenant')->table('mitarbeiter_dokumente')->where('id', $docId)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * GET /api/worker-documents/{id}/thumb
     *
     * Streams a 300×400 JPG preview. Tenant isolation is implicit:
     * `mitarbeiter_dokumente` lives on the tenant DB, which the
     * tenant.boundary middleware has already pinned for this request.
     */
    public function thumb(Request $request, int $id)
    {
        $doc = DB::connection('tenant')->table('mitarbeiter_dokumente')
            ->where('id', $id)->first();
        if (!$doc || empty($doc->dateipfad)
            || !BereichScope::allowsWorker($request->user(), (string) $doc->dienst_nr)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        // Defense-in-depth: only files under uploads/workers/ may be
        // thumb'd — paths from other modules are rejected.
        if (!str_starts_with($doc->dateipfad, 'uploads/workers/')) {
            return response()->json(['error' => 'Zugriff verweigert'], 403);
        }

        $thumbPath = \App\Services\DocumentThumbnailer::generate('public', $doc->dateipfad);
        if (!$thumbPath) {
            return response()->json(['error' => 'Thumbnail nicht verfügbar'], 404);
        }

        return response()->file(
            Storage::disk('public')->path($thumbPath),
            [
                'Content-Type'           => 'image/jpeg',
                'Cache-Control'          => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
