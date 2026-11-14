<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CustomerDocumentController extends Controller
{
    public function index(Request $request)
    {
        $kunde_id = $request->query('kunde_id');
        if (!$kunde_id) return response()->json(['error' => 'kunde_id erforderlich'], 400);
        if (!BereichScope::allowsKunde($request->user(), (string) $kunde_id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        return response()->json(
            DB::connection('tenant')->table('kunden_dokumente')
                ->where('kunde_id', $kunde_id)->orderByDesc('hochgeladen_am')->get()
        );
    }

    public function upload(Request $request)
    {
        $kunde_id = $request->input('kunde_id');
        $titel = $request->input('titel');

        if (!$kunde_id || !$titel) return response()->json(['error' => 'kunde_id und titel erforderlich'], 400);
        if (!BereichScope::allowsKunde($request->user(), (string) $kunde_id)) {
            return response()->json(['error' => 'Kunde nicht gefunden'], 404);
        }

        $request->validate(['dokument' => 'required|file|max:15360']);

        $file = $request->file('dokument');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

        if (!in_array($ext, $allowed)) {
            return response()->json(['error' => 'Erlaubte Dateitypen: PDF, JPG, PNG, WebP, GIF, DOC, DOCX, XLS, XLSX'], 400);
        }

        $imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $dateityp = in_array($ext, $imageExts) ? 'image' : $ext;

        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $safeName . '_' . time() . '.' . $ext;
        $filepath = 'uploads/customers/' . $kunde_id . '/' . $filename;

        $file->storeAs('uploads/customers/' . $kunde_id, $filename, 'public');

        // Best-effort thumbnail for the new card preview.
        \App\Services\DocumentThumbnailer::generate('public', $filepath);

        $id = DB::connection('tenant')->table('kunden_dokumente')->insertGetId([
            'kunde_id' => $kunde_id,
            'titel' => $titel,
            'dateiname' => $file->getClientOriginalName(),
            'dateipfad' => $filepath,
            'dateigroesse' => $file->getSize(),
            'dateityp' => $dateityp,
        ]);

        \App\Helpers\Realtime::changed('kunden');
        return response()->json(['success' => true, 'id' => $id, 'dateipfad' => $filepath, 'dateityp' => $dateityp]);
    }

    public function destroy(Request $request, $id = null)
    {
        // Accept both REST DELETE /{id} and legacy POST {id}
        $docId = $id ?? $request->input('id');
        if (!$docId) return response()->json(['error' => 'Dokument-ID erforderlich'], 400);

        $doc = DB::connection('tenant')->table('kunden_dokumente')->where('id', $docId)->first();
        if (!$doc || !BereichScope::allowsKunde($request->user(), (string) $doc->kunde_id)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        if ($doc->dateipfad) {
            Storage::disk('public')->delete($doc->dateipfad);
            \App\Services\DocumentThumbnailer::delete('public', $doc->dateipfad);
        }

        DB::connection('tenant')->table('kunden_dokumente')->where('id', $docId)->delete();
        \App\Helpers\Realtime::changed('kunden');
        return response()->json(['success' => true]);
    }

    /**
     * GET /api/customer-documents/{id}/thumb
     */
    public function thumb(Request $request, int $id)
    {
        $doc = DB::connection('tenant')->table('kunden_dokumente')
            ->where('id', $id)->first();
        if (!$doc || empty($doc->dateipfad)
            || !BereichScope::allowsKunde($request->user(), (string) $doc->kunde_id)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }
        if (!str_starts_with($doc->dateipfad, 'uploads/customers/')) {
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
