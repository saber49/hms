<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Support\BereichScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PDF archive of issued documents (Leistungsnachweis, Auftragsbestätigung,
 * Stundennachweis). Every download / print / send of a document uploads the
 * exact PDF the user got; identical content (same bytes apart from the PDF
 * creation date / file id) keeps its version number and only adds an event
 * row, changed content becomes a new version. Files live on the private
 * `local` disk under a path built from validated parts only — never from
 * raw user input. There is intentionally NO delete endpoint.
 */
class DokumentArchivController extends Controller
{
    private const VIEW_PERMISSION = [
        'order'  => 'auftraege.bestellungen.view',
        'ab'     => 'auftraege.bestaetigung.view',
        'worker' => 'auftraege.monatsabschluss.view',
    ];
    private const AKTIONEN = ['download', 'druck', 'senden', 'teilen'];
    private const ID_RE = '/^[A-Za-z0-9_-]{1,64}$/';
    // Stundennachweis: "MA:<dienst_nr>|<Zeitraum>" or "NU:<nu_portal_mitarbeiter.id>|<Zeitraum>".
    private const WORKER_REF_RE = '/^(MA|NU):([A-Za-z0-9_-]{1,40})\|([^\x00-\x1f|]{1,80})$/u';

    /** POST /api/dokument-archiv — multipart {pdf, doc_type, ref_id, aktion, label?} */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        TenantService::assertCurrentMatches((int) $user->tenant_id);
        if (!$this->ready()) return response()->json(['error' => 'Archiv ist auf diesem Tenant noch nicht aktiv.'], 503);

        $request->validate([
            'pdf'      => 'required|file|mimetypes:application/pdf|max:15360',
            'doc_type' => 'required|string|in:order,ab,worker',
            'ref_id'   => 'required|string|max:130',
            'aktion'   => 'required|string|in:' . implode(',', self::AKTIONEN),
            'label'    => 'nullable|string|max:190',
        ]);
        $type = (string) $request->input('doc_type');
        $ref  = (string) $request->input('ref_id');
        if (!$this->refAllowed($user, $type, $ref)) return response()->json(['error' => 'Dokument nicht gefunden'], 404);

        $bin = (string) file_get_contents($request->file('pdf')->getRealPath());
        if (strncmp($bin, '%PDF-', 5) !== 0) return response()->json(['error' => 'Ungültige PDF-Datei'], 422);
        $hash = $this->contentHash($bin);
        $tenantId = (int) $user->tenant_id;
        $label = mb_substr(strip_tags((string) $request->input('label', '')), 0, 190) ?: null;
        $db = DB::connection('tenant');

        $out = $db->transaction(function () use ($db, $type, $ref, $hash, $bin, $tenantId, $user, $request, $label) {
            $latest = $db->table('dokument_archiv')->where('doc_type', $type)->where('ref_id', $ref)
                ->orderByDesc('version')->orderByDesc('id')->lockForUpdate()->first();
            $same = $latest && hash_equals((string) $latest->content_hash, $hash)
                && Storage::disk('local')->exists($latest->pdf_path);
            if ($same) {
                $version = (int) $latest->version;
                $path = $latest->pdf_path;
            } else {
                $version = $latest ? (int) $latest->version + 1 : 1;
                $path = 'dokument_archiv/' . $tenantId . '/' . $type . '/' . substr(hash('sha256', $ref), 0, 24)
                    . '/v' . $version . '_' . Str::lower(Str::random(10)) . '.pdf';
                Storage::disk('local')->put($path, $bin);
            }
            $id = $db->table('dokument_archiv')->insertGetId([
                'doc_type'        => $type,
                'ref_id'          => $ref,
                'label'           => $label,
                'version'         => $version,
                'aktion'          => (string) $request->input('aktion'),
                'content_hash'    => $hash,
                'pdf_path'        => $path,
                'file_size'       => strlen($bin),
                'created_by'      => (int) $user->id,
                'created_by_name' => mb_substr((string) ($user->full_name ?: ($user->name ?: $user->email)), 0, 190),
                'created_at'      => now(),
            ]);
            return ['id' => $id, 'version' => $version, 'neu' => !$same];
        });

        return response()->json(['success' => true] + $out, 201);
    }

    /** GET /api/dokument-archiv?doc_type=&ref_id= — Verlauf of one document (newest first). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        TenantService::assertCurrentMatches((int) $user->tenant_id);
        $type = (string) $request->query('doc_type');
        $ref  = (string) $request->query('ref_id');
        if (!isset(self::VIEW_PERMISSION[$type]) || !$this->refAllowed($user, $type, $ref)) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        $entries = [];
        if ($this->ready()) {
            foreach (DB::connection('tenant')->table('dokument_archiv')->where('doc_type', $type)->where('ref_id', $ref)
                ->orderByDesc('id')->limit(500)->get() as $r) {
                $entries[] = [
                    'id' => (int) $r->id, 'quelle' => 'archiv', 'version' => (int) $r->version, 'aktion' => $r->aktion,
                    'label' => $r->label, 'file_size' => (int) $r->file_size, 'by' => $r->created_by_name, 'at' => $r->created_at,
                    'download' => '/dokument-archiv/' . (int) $r->id . '/download',
                ];
            }
        }
        // Auftragsbestätigung: versions frozen by "Als versendet markieren".
        if ($type === 'ab' && Schema::connection('tenant')->hasTable('auftragsbestaetigung_versionen')) {
            $rows = DB::connection('tenant')->table('auftragsbestaetigung_versionen')->where('eingang_id', $ref)
                ->orderByDesc('version')->get(['version', 'sent_at', 'sent_by']);
            $names = $this->userNames((int) $user->tenant_id, $rows->pluck('sent_by')->all());
            foreach ($rows as $r) {
                $entries[] = [
                    'id' => null, 'quelle' => 'versand', 'version' => (int) $r->version, 'aktion' => 'versendet',
                    'label' => 'An Kunden versendet (Version ' . (int) $r->version . ')', 'file_size' => null,
                    'by' => $names[$r->sent_by] ?? null, 'at' => $r->sent_at,
                    'download' => '/order-confirmations/' . rawurlencode($ref) . '/archive?version=' . (int) $r->version . '&download=1',
                ];
            }
        }
        // Leistungsnachweis: PDFs filed by the old "Speichern" (bestellung_pdfs).
        if ($type === 'order' && Schema::connection('tenant')->hasTable('bestellung_pdfs')) {
            foreach (DB::connection('tenant')->table('bestellung_pdfs')->where('bestell_id', $ref)->orderByDesc('id')
                ->get(['id', 'name', 'file_size', 'generated_by', 'generated_at']) as $r) {
                $entries[] = [
                    'id' => null, 'quelle' => 'alt', 'version' => null, 'aktion' => 'gespeichert',
                    'label' => $r->name, 'file_size' => (int) $r->file_size, 'by' => $r->generated_by, 'at' => $r->generated_at,
                    'download' => '/pdfs/' . (int) $r->id . '/download',
                ];
            }
        }
        usort($entries, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return response()->json(['entries' => $entries]);
    }

    /** GET /api/dokument-archiv/{id}/download */
    public function download(Request $request, int $id)
    {
        $user = $request->user();
        TenantService::assertCurrentMatches((int) $user->tenant_id);
        $row = $this->ready() ? DB::connection('tenant')->table('dokument_archiv')->where('id', $id)->first() : null;
        if (!$row || !$this->refAllowed($user, $row->doc_type, $row->ref_id)
            || !str_starts_with((string) $row->pdf_path, 'dokument_archiv/' . (int) $user->tenant_id . '/')
            || !Storage::disk('local')->exists($row->pdf_path)) {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }
        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($row->label ?: ($row->doc_type . '_' . $row->ref_id)));
        $name = trim(mb_substr($base, 0, 120), '_') . '_V' . (int) $row->version . '.pdf';
        return response(Storage::disk('local')->get($row->pdf_path), 200, [
            'Content-Type'           => 'application/pdf',
            'Content-Disposition'    => ($request->boolean('inline') ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Cache-Control'          => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ─────────────────────────────────────────────────────────────

    private function ready(): bool
    {
        return Schema::connection('tenant')->hasTable('dokument_archiv');
    }

    /** Permission + existence + Bereich scope of the referenced document. */
    private function refAllowed($user, string $type, string $ref): bool
    {
        if (!isset(self::VIEW_PERMISSION[$type])) return false;
        if (!$user->hasPermission(self::VIEW_PERMISSION[$type]) && !$user->hasPermission('system.dokument_design.manage')) return false;
        $db = DB::connection('tenant');
        if ($type === 'order') {
            return (bool) preg_match(self::ID_RE, $ref)
                && $db->table('bestellungen')->where('bestell_id', $ref)->exists()
                && BereichScope::allowsBestellung($user, $ref);
        }
        if ($type === 'ab') {
            return (bool) preg_match(self::ID_RE, $ref)
                && $db->table('bestelleingang')->where('eingang_id', $ref)->exists()
                && BereichScope::allowsEingang($user, $ref);
        }
        if (!preg_match(self::WORKER_REF_RE, $ref, $m)) return false;
        if ($m[1] === 'MA') return BereichScope::allowsWorker($user, $m[2]);
        if (!ctype_digit($m[2])) return false;
        $nuId = $db->table('nu_portal_mitarbeiter')->where('id', (int) $m[2])->value('nu_id');
        return $nuId !== null && BereichScope::allowsNu($user, (string) $nuId);
    }

    /**
     * Content identity of a PDF: sha256 of the bytes without the volatile
     * creation/modification date and file id, so re-rendering an unchanged
     * document maps to the same version.
     */
    private function contentHash(string $bin): string
    {
        $norm = preg_replace([
            '~/(CreationDate|ModDate)\s*\(D:[^)]*\)~',
            '~/ID\s*\[\s*<[0-9A-Fa-f]*>\s*<[0-9A-Fa-f]*>\s*\]~',
            '~<xmp:(CreateDate|ModifyDate|MetadataDate)>[^<]*</xmp:\1>~',
        ], '', $bin);
        return hash('sha256', $norm ?? $bin);
    }

    private function userNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) return [];
        return DB::connection('mysql')->table('central_users')->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)->pluck('full_name', 'id')->all();
    }
}
