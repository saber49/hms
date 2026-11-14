<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class NuDocumentController extends Controller
{
    private const ALLOWED_FAELLIG = ['vertragsbeginn', 'jaehrlich', 'auf_anforderung'];

    public function index(Request $request)
    {
        $nu_id = $request->query('nu_id');
        if (!$nu_id) return response()->json(['error' => 'nu_id erforderlich'], 400);
        if (!BereichScope::allowsNu($request->user(), (string) $nu_id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        return response()->json(
            DB::connection('tenant')->table('nu_dokumente')
                ->where('nu_id', $nu_id)
                ->orderByDesc('hochgeladen_am')->get()
        );
    }

    public function upload(Request $request)
    {
        $nu_id = $request->input('nu_id');
        $titel = $request->input('titel');
        $dok_typ = $request->input('dok_typ');
        $faellig_typ = $request->input('faellig_typ');
        $gueltig_ab = $request->input('gueltig_ab');
        $gueltig_bis = $request->input('gueltig_bis');
        $bemerkung = $request->input('bemerkung');

        if (!$nu_id || !$titel || !$dok_typ || !$faellig_typ) {
            return response()->json(['error' => 'Alle Pflichtfelder ausfüllen (Dokumententyp, Fälligkeit, Datei).'], 400);
        }

        if (!in_array($faellig_typ, self::ALLOWED_FAELLIG, true)) {
            return response()->json(['error' => 'Ungültiger Fälligkeitstyp.'], 400);
        }
        if (!BereichScope::allowsNu($request->user(), (string) $nu_id)) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden'], 404);
        }

        $request->validate(['dokument' => 'required|file|mimes:pdf|max:10240']);

        // Validate optional date fields
        $parsedAb = $this->parseDate($gueltig_ab);
        $parsedBis = $this->parseDate($gueltig_bis);

        if ($gueltig_ab && !$parsedAb) {
            return response()->json(['error' => 'Ungültiges Datum: gültig ab.'], 422);
        }
        if ($gueltig_bis && !$parsedBis) {
            return response()->json(['error' => 'Ungültiges Datum: gültig bis.'], 422);
        }
        if ($parsedAb && $parsedBis && $parsedBis < $parsedAb) {
            return response()->json(['error' => '"Gültig bis" darf nicht vor "Gültig ab" liegen.'], 422);
        }

        $file = $request->file('dokument');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $safeName . '_' . time() . '.pdf';
        $filepath = 'uploads/subcontractors/' . $nu_id . '/' . $filename;

        $file->storeAs('uploads/subcontractors/' . $nu_id, $filename, 'public');

        // Best-effort thumbnail for the new card preview.
        \App\Services\DocumentThumbnailer::generate('public', $filepath);

        $payload = [
            'nu_id' => $nu_id,
            'titel' => $titel,
            'dok_typ' => $dok_typ ?: null,
            'dateiname' => $file->getClientOriginalName(),
            'dateipfad' => $filepath,
            'dateigroesse' => $file->getSize(),
            'faellig_typ' => $faellig_typ,
        ];

        if (Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_ab')) {
            $payload['gueltig_ab'] = $parsedAb;
        }
        if (Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis')) {
            $payload['gueltig_bis'] = $parsedBis;
        }
        if (Schema::connection('tenant')->hasColumn('nu_dokumente', 'bemerkung') && $bemerkung) {
            $payload['bemerkung'] = trim((string) $bemerkung);
        }

        $id = DB::connection('tenant')->table('nu_dokumente')->insertGetId($payload);

        return response()->json(['success' => true, 'id' => $id, 'dateipfad' => $filepath]);
    }

    public function destroy(Request $request, $id = null)
    {
        $docId = $id ?? $request->input('id');
        if (!$docId) return response()->json(['error' => 'Dokument-ID erforderlich'], 400);

        $doc = DB::connection('tenant')->table('nu_dokumente')->where('id', $docId)->first();
        if (!$doc || !BereichScope::allowsNu($request->user(), (string) $doc->nu_id)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        if ($doc->dateipfad) {
            Storage::disk('public')->delete($doc->dateipfad);
            \App\Services\DocumentThumbnailer::delete('public', $doc->dateipfad);
        }

        DB::connection('tenant')->table('nu_dokumente')->where('id', $docId)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Global view: documents that are expired or expiring within 60 days
     * across all NUs in this tenant. Useful for compliance dashboards.
     */
    public function expiring()
    {
        if (!Schema::connection('tenant')->hasColumn('nu_dokumente', 'gueltig_bis')) {
            return response()->json(['expired' => [], 'expiring' => []]);
        }

        $today = now()->toDateString();
        $warn = now()->addDays(60)->toDateString();

        [$nSql, $nParams] = BereichScope::nuPredicate(request()->user(), 'n');
        $rows = DB::connection('tenant')->table('nu_dokumente as d')
            ->join('nachunternehmer as n', 'n.nu_id', '=', 'd.nu_id')
            ->whereNotNull('d.gueltig_bis')
            ->where('d.gueltig_bis', '<=', $warn)
            ->where('n.aktiv', 1)
            ->whereRaw($nSql, $nParams)
            ->select(
                'd.id', 'd.nu_id', 'd.dok_typ', 'd.titel', 'd.gueltig_bis', 'd.dateipfad',
                'n.firmenname'
            )
            ->orderBy('d.gueltig_bis')
            ->get();

        $expired = [];
        $expiring = [];
        foreach ($rows as $r) {
            if ($r->gueltig_bis < $today) $expired[] = $r;
            else $expiring[] = $r;
        }

        return response()->json([
            'expired' => $expired,
            'expiring' => $expiring,
        ]);
    }

    private function parseDate(?string $d): ?string
    {
        if (!$d) return null;
        try {
            return (new \DateTime($d))->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * GET /api/nu-documents/{id}/thumb
     */
    public function thumb(Request $request, int $id)
    {
        $doc = DB::connection('tenant')->table('nu_dokumente')
            ->where('id', $id)->first();
        if (!$doc || empty($doc->dateipfad)
            || !BereichScope::allowsNu($request->user(), (string) $doc->nu_id)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }
        if (!str_starts_with($doc->dateipfad, 'uploads/subcontractors/')) {
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
