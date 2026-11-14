<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AbrechnungDataService;
use App\Services\TenantService;
use App\Support\BereichScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PdfController extends Controller
{
    /**
     * GET /api/pdf/order/{id}
     * Generates a PDF for the order from server-side blade template + DomPDF.
     * Optional ?settings= and ?anonymize= to apply user customizations.
     */
    public function generateOrderPdf(Request $request, string $id)
    {
        $data = $this->buildOrderData($id);
        if (!$data) return response()->json(['error' => 'Bestellung nicht gefunden'], 404);

        $settings = $request->input('settings', []);
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];

        $data['settings'] = $settings;
        $data['anonymize_workers'] = $request->boolean('anonymize', false);
        // Per-document "hide logo" toggle — suppress the logo for THIS PDF
        // without touching the tenant's company logo (logo_path).
        if (!empty($settings['hide_logo'])) $data['logoDataUri'] = null;

        if ($data['anonymize_workers']) {
            $data['confirmedWorkers'] = $this->anonymizeWorkerList($data['confirmedWorkers']);
            $data['confirmedNu']      = $this->anonymizeWorkerList($data['confirmedNu'], 'NU-Mitarbeiter');
        }

        $pdf = Pdf::loadView('pdf.order-form', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'Auftragsbestaetigung_' . $id . '.pdf';
        if ($request->input('action') === 'download') return $pdf->download($filename);
        return $pdf->stream($filename);
    }

    /**
     * POST /api/orders/{id}/pdfs
     * Saves a PDF snapshot for an order.
     *
     * Two modes:
     *  1. Client-rendered (preferred when the user has added free-form
     *     editor elements): the frontend sends the already-rendered PDF as
     *     a multipart `pdf` file. The server stores it as-is, guaranteeing
     *     1:1 WYSIWYG with what the user saw on screen.
     *  2. Server-rendered (legacy / no custom layout): JSON payload with
     *     html_snapshot/settings_snapshot. Server renders via DomPDF +
     *     blade template.
     */
    public function savePdf(Request $request, string $id)
    {
        // Verify the order exists & belongs to current tenant + Bereich scope.
        $orderExists = DB::connection('tenant')->table('bestellungen')
            ->where('bestell_id', $id)->exists();
        if (!$orderExists || !BereichScope::allowsBestellung($request->user(), $id)) {
            return response()->json(['error' => 'Bestellung nicht gefunden'], 404);
        }

        return $request->hasFile('pdf')
            ? $this->saveUploadedPdf($request, $id)
            : $this->saveServerRenderedPdf($request, $id);
    }

    /**
     * Path 1: client uploaded a pre-rendered PDF binary.
     * Store as-is. We only validate it's a real PDF and within size limits.
     */
    protected function saveUploadedPdf(Request $request, string $id)
    {
        $request->validate([
            'pdf'               => 'required|file|mimetypes:application/pdf|max:10240', // 10 MB
            'name'              => 'nullable|string|max:255',
            'settings_snapshot' => 'nullable|string', // JSON string in multipart
            'anonymize_workers' => 'nullable',
            'template_id'       => 'nullable|integer',
            'html_snapshot'     => 'nullable|string',
        ]);

        $file   = $request->file('pdf');
        $binary = file_get_contents($file->getRealPath());

        // Magic-byte check: PDF files always begin with "%PDF-"
        if (substr($binary, 0, 5) !== '%PDF-') {
            return response()->json(['error' => 'Ungültige PDF-Datei'], 422);
        }

        $tenant     = TenantService::current();
        $tenantSlug = $tenant->slug ?? 'default';
        $filename   = sprintf('%s_%s_%s.pdf', $id, now()->format('Ymd_His'), Str::random(6));
        $path       = "pdfs/{$tenantSlug}/{$filename}";
        Storage::disk('public')->put($path, $binary);

        $settings = $request->input('settings_snapshot');
        if (is_string($settings)) $settings = json_decode($settings, true) ?: [];

        $name = $request->input('name')
            ?: ('Auftragsbestaetigung ' . $id . ' (' . now()->format('d.m.Y H:i') . ')');

        $pdfId = DB::connection('tenant')->table('bestellung_pdfs')->insertGetId([
            'bestell_id'        => $id,
            'name'              => $name,
            'file_path'         => $path,
            'file_size'         => strlen($binary),
            'template_id'       => $request->input('template_id'),
            'html_snapshot'     => $request->input('html_snapshot'),
            'settings_snapshot' => json_encode($settings),
            'anonymize_workers' => $request->boolean('anonymize_workers'),
            'generated_by'      => optional($request->user())->email,
            'generated_at'      => now(),
        ]);

        return response()->json([
            'success'      => true,
            'id'           => $pdfId,
            'name'         => $name,
            'size'         => strlen($binary),
            'path'         => $path,
            'render_mode'  => 'client',
        ], 201);
    }

    /**
     * Path 2: server renders the PDF via DomPDF + blade template.
     * Used when the frontend has no free-form custom elements.
     */
    protected function saveServerRenderedPdf(Request $request, string $id)
    {
        $data = $this->buildOrderData($id);
        if (!$data) return response()->json(['error' => 'Bestellung nicht gefunden'], 404);

        $tenant  = TenantService::current();
        $payload = $request->validate([
            'name'              => 'nullable|string|max:255',
            'html_snapshot'     => 'nullable|string',
            'settings_snapshot' => 'nullable|array',
            'anonymize_workers' => 'nullable|boolean',
            'template_id'       => 'nullable|integer',
        ]);

        // Render PDF (use settings if provided)
        $data['settings']          = $payload['settings_snapshot'] ?? [];
        $data['anonymize_workers'] = $payload['anonymize_workers'] ?? false;
        // Per-document "hide logo" toggle — suppress the logo for THIS PDF
        // without touching the tenant's company logo (logo_path).
        if (!empty($data['settings']['hide_logo'])) $data['logoDataUri'] = null;
        if ($data['anonymize_workers']) {
            $data['confirmedWorkers'] = $this->anonymizeWorkerList($data['confirmedWorkers']);
            $data['confirmedNu']      = $this->anonymizeWorkerList($data['confirmedNu'], 'NU-Mitarbeiter');
        }

        $pdf = Pdf::loadView('pdf.order-form', $data);
        $pdf->setPaper('A4', 'portrait');
        $binary = $pdf->output();

        $tenantSlug = $tenant->slug ?? 'default';
        $filename   = sprintf('%s_%s_%s.pdf', $id, now()->format('Ymd_His'), Str::random(6));
        $path       = "pdfs/{$tenantSlug}/{$filename}";
        Storage::disk('public')->put($path, $binary);

        $name = $payload['name'] ?? ('Auftragsbestaetigung ' . $id . ' (' . now()->format('d.m.Y H:i') . ')');

        $pdfId = DB::connection('tenant')->table('bestellung_pdfs')->insertGetId([
            'bestell_id'        => $id,
            'name'              => $name,
            'file_path'         => $path,
            'file_size'         => strlen($binary),
            'template_id'       => $payload['template_id'] ?? null,
            'html_snapshot'     => $payload['html_snapshot'] ?? null,
            'settings_snapshot' => json_encode($payload['settings_snapshot'] ?? []),
            'anonymize_workers' => $payload['anonymize_workers'] ?? false,
            'generated_by'      => optional($request->user())->email,
            'generated_at'      => now(),
        ]);

        return response()->json([
            'success'      => true,
            'id'           => $pdfId,
            'name'         => $name,
            'size'         => strlen($binary),
            'path'         => $path,
            'render_mode'  => 'server',
        ], 201);
    }

    /**
     * GET /api/orders/{id}/pdfs
     * Lists all saved PDFs for a Bestellung.
     */
    public function listPdfs(Request $request, string $id)
    {
        if (!BereichScope::allowsBestellung($request->user(), $id)) {
            return response()->json(['error' => 'Bestellung nicht gefunden'], 404);
        }
        $rows = DB::connection('tenant')->table('bestellung_pdfs')
            ->select('id', 'name', 'file_size', 'template_id', 'anonymize_workers',
                     'generated_by', 'generated_at')
            ->where('bestell_id', $id)
            ->orderByDesc('generated_at')
            ->get();

        // Map share link counts
        $pdfIds = $rows->pluck('id');
        $shares = DB::connection('tenant')->table('pdf_share_links')
            ->select('bestellung_pdf_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('bestellung_pdf_id', $pdfIds)
            ->groupBy('bestellung_pdf_id')
            ->pluck('cnt', 'bestellung_pdf_id');

        $rows->transform(function ($r) use ($shares) {
            $r->anonymize_workers = (bool) $r->anonymize_workers;
            $r->share_count = (int) ($shares[$r->id] ?? 0);
            return $r;
        });

        return response()->json(['pdfs' => $rows]);
    }

    /**
     * GET /api/pdfs/{pdfId}/download
     */
    public function downloadSavedPdf(int $pdfId)
    {
        $pdf = DB::connection('tenant')->table('bestellung_pdfs')->where('id', $pdfId)->first();
        if (!$pdf || !BereichScope::allowsBestellung(request()->user(), (string) $pdf->bestell_id)) {
            return response()->json(['error' => 'PDF nicht gefunden'], 404);
        }
        if (!$pdf->file_path || !Storage::disk('public')->exists($pdf->file_path)) {
            return response()->json(['error' => 'Datei nicht mehr vorhanden'], 410);
        }

        $binary = Storage::disk('public')->get($pdf->file_path);
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $this->pdfDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf->name),
        ]);
    }

    /**
     * DELETE /api/pdfs/{pdfId}
     */
    public function deletePdf(int $pdfId)
    {
        $pdf = DB::connection('tenant')->table('bestellung_pdfs')->where('id', $pdfId)->first();
        if (!$pdf || !BereichScope::allowsBestellung(request()->user(), (string) $pdf->bestell_id)) {
            return response()->json(['error' => 'PDF nicht gefunden'], 404);
        }

        if ($pdf->file_path && Storage::disk('public')->exists($pdf->file_path)) {
            Storage::disk('public')->delete($pdf->file_path);
        }

        // Share links cascade via FK; explicit delete as belt-and-suspenders
        DB::connection('tenant')->table('pdf_share_links')
            ->where('bestellung_pdf_id', $pdfId)->delete();
        DB::connection('tenant')->table('bestellung_pdfs')->where('id', $pdfId)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/pdfs/{pdfId}/share
     * Creates a public share link (token in URL, no auth needed to view).
     *
     * expires_in_days is clamped to [1, 365] — never permanent. This is a
     * deliberate security guardrail: a "permanent" public PDF link is a
     * footgun for tenants and runs counter to GDPR data-minimisation
     * (data should not be reachable longer than necessary).
     */
    public function createShareLink(Request $request, int $pdfId)
    {
        $pdf = DB::connection('tenant')->table('bestellung_pdfs')->where('id', $pdfId)->first();
        if (!$pdf || !BereichScope::allowsBestellung($request->user(), (string) $pdf->bestell_id)) {
            return response()->json(['error' => 'PDF nicht gefunden'], 404);
        }

        $days = (int) $request->input('expires_in_days', 30);
        $days = max(1, min(365, $days));   // clamp 1..365
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $tenant  = TenantService::current();
        $expires = now()->addDays($days);

        DB::connection('tenant')->table('pdf_share_links')->insert([
            'token'             => $token,
            'bestellung_pdf_id' => $pdfId,
            'expires_at'        => $expires,
            'created_by'        => optional($request->user())->email,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $shareUrl = url('/share/' . $tenant->slug . '/' . $token);

        return response()->json([
            'success'    => true,
            'token'      => $token,
            'share_url'  => $shareUrl,
            'expires_at' => $expires,
        ], 201);
    }

    /**
     * GET /share/{tenant_slug}/{token}
     * Public view of a shared PDF — NO auth required.
     */
    public function publicShareView(string $tenantSlug, string $token)
    {
        // Find tenant by slug (this method is called outside the normal auth+tenant middleware)
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->first();
        if (!$tenant) abort(404, 'Unknown tenant');
        if (!$tenant->aktiv) abort(403, 'Tenant deactivated');

        // Always go through TenantService so the worker (Octane / FrankenPHP)
        // gets a clean reset + reconnect. Direct config()/purge() can leave
        // stale state if the controller throws mid-request.
        TenantService::switchToTenant($tenant);

        $share = DB::connection('tenant')->table('pdf_share_links')->where('token', $token)->first();
        if (!$share) abort(404, 'Share link not found');
        if ($share->expires_at && now()->gt($share->expires_at)) {
            abort(410, 'Share link expired');
        }

        $pdf = DB::connection('tenant')->table('bestellung_pdfs')
            ->where('id', $share->bestellung_pdf_id)->first();
        if (!$pdf) abort(404, 'PDF not found');
        if (!$pdf->file_path || !Storage::disk('public')->exists($pdf->file_path)) {
            abort(410, 'PDF file no longer available');
        }

        // Update access count
        DB::connection('tenant')->table('pdf_share_links')
            ->where('id', $share->id)->update([
                'access_count'     => $share->access_count + 1,
                'last_accessed_at' => now(),
            ]);

        $binary = Storage::disk('public')->get($pdf->file_path);
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $this->pdfDisposition(HeaderUtils::DISPOSITION_INLINE, $pdf->name),
        ]);
    }

    /**
     * POST /api/pdf/upload-logo
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $tenant = TenantService::current();
        $tenantModel = $request->user()->tenant;

        $file = $request->file('logo');
        $path = $file->store('logos/' . $tenant->slug, 'public');

        $tenantModel->update(['logo_path' => $path]);

        return response()->json([
            'success'  => true,
            'logo_path'=> $path,
            'logo_url' => '/api/serve?path=' . urlencode($path),
        ]);
    }

    /**
     * POST /api/pdf/upload-signature — company signature image for PDFs.
     * POST /api/pdf/upload-stamp     — company stamp/seal image for PDFs.
     *
     * Both mirror uploadLogo: the file lands on the public disk inside the
     * tenant's own folder and the column on `tenants` is replaced (the old
     * file is deleted). Transparent PNG/WebP is what the "Gestempelt"-look
     * needs, but any browser-renderable image type is accepted.
     */
    public function uploadSignature(Request $request)
    {
        return $this->denyDocAsset($request) ?? $this->storeDocAsset($request, 'signature', 'signature_path');
    }

    public function uploadStamp(Request $request)
    {
        return $this->denyDocAsset($request) ?? $this->storeDocAsset($request, 'stamp', 'stamp_path');
    }

    public function deleteSignature(Request $request)
    {
        return $this->denyDocAsset($request) ?? $this->removeDocAsset($request, 'signature_path');
    }

    public function deleteStamp(Request $request)
    {
        return $this->denyDocAsset($request) ?? $this->removeDocAsset($request, 'stamp_path');
    }

    /** Signature/stamp gate: Bestellungen editors or Dokument-Design managers. */
    private function denyDocAsset(Request $request)
    {
        $u = $request->user();
        if ($u && ($u->hasPermission('auftraege.bestellungen.edit')
                || $u->hasPermission('system.dokument_design.manage'))) {
            return null;
        }
        return response()->json([
            'error'    => 'Sie haben keine Berechtigung für diese Aktion.',
            'required' => 'auftraege.bestellungen.edit',
        ], 403);
    }

    private function storeDocAsset(Request $request, string $field, string $column)
    {
        $request->validate([
            $field => 'required|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
            'scope' => 'nullable|in:user,tenant',
        ]);

        $tenant = TenantService::current();
        $user = $request->user();
        // scope=tenant writes the company-wide default (tenants row) —
        // admin-only; every other upload is the user's personal asset.
        $scope = $request->input('scope', 'user');
        if ($scope === 'tenant' && !$this->canManageTenantAssets($user)) {
            $scope = 'user';
        }

        // Same slug guard as the dashboard logo upload — a corrupt slug
        // must never let a file land outside the tenant's own folder.
        if (!preg_match('/^[a-z0-9][-a-z0-9_]{0,63}$/', (string) $tenant->slug)) {
            \Illuminate\Support\Facades\Log::critical('Doc-asset upload: invalid tenant slug', [
                'tenant_id' => $tenant->id, 'slug' => $tenant->slug,
            ]);
            return response()->json(['error' => 'Ungültige Mandanten-Konfiguration'], 500);
        }

        $dir = $scope === 'tenant'
            ? "tenants/{$tenant->slug}/{$field}"
            : "tenants/{$tenant->slug}/users/{$user->id}/{$field}";
        $path = $request->file($field)->store($dir, 'public');
        if (!is_string($path) || !str_starts_with($path, $dir . '/')) {
            try { Storage::disk('public')->delete((string) $path); } catch (\Throwable $e) {}
            \Illuminate\Support\Facades\Log::critical('Doc-asset upload: path scope violation', [
                'tenant_id' => $tenant->id, 'expected' => $dir, 'got' => $path,
            ]);
            return response()->json(['error' => 'Speicherort ungültig'], 500);
        }

        $model = $scope === 'tenant' ? $user->tenant : $user;
        if ($model->{$column}) {
            try { Storage::disk('public')->delete($model->{$column}); } catch (\Throwable $e) {}
        }
        $model->update([$column => $path]);

        return response()->json([
            'success' => true,
            $column   => $path,
            'scope'   => $scope,
            'url'     => '/api/serve?path=' . urlencode($path),
        ]);
    }

    private function removeDocAsset(Request $request, string $column)
    {
        $user = $request->user();
        $scope = $request->input('scope', 'user');
        $model = ($scope === 'tenant' && $this->canManageTenantAssets($user))
            ? $user->tenant
            : $user;
        if ($model->{$column}) {
            try { Storage::disk('public')->delete($model->{$column}); } catch (\Throwable $e) {}
            $model->update([$column => null]);
        }
        return response()->json(['success' => true]);
    }

    /**
     * Company-default signature/stamp management is limited to admins and
     * the tenant owner — the same circle that owns global tenant settings.
     */
    private function canManageTenantAssets($user): bool
    {
        return $user->isOwner()
            || in_array($user->role, ['tenant_admin', 'admin', 'super_admin'], true);
    }

    // ──────────────────────────────────────────────────────────
    // INTERNAL HELPERS
    // ──────────────────────────────────────────────────────────

    /**
     * Build a safe Content-Disposition header for serving a stored PDF.
     *
     * $name is user-controlled (max 255 chars) so it must never be dropped
     * raw into the header — a quote or CR/LF would corrupt or inject it.
     * We strip path separators + control characters, then let Symfony's
     * HeaderUtils percent-encode the UTF-8 filename and emit a sanitised
     * ASCII fallback (RFC 6266), closing the header-injection vector while
     * still preserving umlauts in clients that support filename*.
     */
    protected function pdfDisposition(string $type, ?string $name): string
    {
        $name = str_replace(['/', '\\'], ' ', trim((string) $name));
        $name = preg_replace('/[\x00-\x1f]+/', ' ', $name);
        $name = trim($name) ?: 'Auftragsbestaetigung';

        $fallback = preg_replace('/[^\w.\- ]/', '_', Str::ascii($name));
        $fallback = trim($fallback) ?: 'Auftragsbestaetigung';

        return HeaderUtils::makeDisposition($type, $name . '.pdf', $fallback . '.pdf');
    }

    /**
     * Build the order data array (used by both generate and save).
     */
    protected function buildOrderData(string $id): ?array
    {
        $tenant = TenantService::current();

        $order = DB::connection('tenant')
            ->table('bestellungen as b')
            ->leftJoin('kunden as k', 'b.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o', 'b.obj_id', '=', 'o.obj_id')
            ->leftJoin('nachunternehmer as n', 'b.nu_id', '=', 'n.nu_id')
            ->select('b.*', 'k.kundenname', 'o.objektname', 'n.firmenname as nu_name')
            ->where('b.bestell_id', $id)->first();

        // Bereich scope: an out-of-scope Bestellung must look "not found".
        if (!$order || !BereichScope::allowsBestellung(auth()->user(), $id)) return null;

        $settlement = AbrechnungDataService::confirmedOrderAssignments($id);
        $confirmedWorkers = $settlement['workers'];
        $confirmedNu = $settlement['nu_workers'];

        $company = [
            // Contact / display
            'name'              => $tenant->name ?? 'Firma',
            'address'           => $tenant->company_address ?? '',
            'zip'               => $tenant->company_zip ?? '',
            'city'              => $tenant->company_city ?? '',
            'phone'             => $tenant->company_phone ?? '',
            'email'             => $tenant->company_email ?? '',
            'website'           => $tenant->website ?? '',
            'logo_path'         => $tenant->logo_path ?? null,
            // German legal (Impressum / footer block)
            'rechtsform'         => $tenant->rechtsform ?? '',
            'geschaeftsfuehrer'  => $tenant->geschaeftsfuehrer ?? '',
            'handelsregister_nr' => $tenant->handelsregister_nr ?? '',
            'registergericht'    => $tenant->registergericht ?? '',
            'ust_id'             => $tenant->ust_id ?? '',
            'steuernummer'       => $tenant->steuernummer ?? '',
            // Bank details
            'bank_name'         => $tenant->bank_name ?? '',
            'iban'              => $tenant->iban ?? '',
            'bic'               => $tenant->bic ?? '',
        ];

        $logoDataUri = $this->resolveLogoDataUri($tenant->id ?? 0, $company['logo_path']);

        return [
            'order'            => $order,
            'confirmedWorkers' => $confirmedWorkers,
            'confirmedNu'      => $confirmedNu,
            'company'          => $company,
            'logoDataUri'      => $logoDataUri,
        ];
    }

    /**
     * Resolve a tenant logo into a data: URI with a small in-process cache.
     *
     * Logos are typically 50–500 KB. Without caching, every PDF request
     * re-reads the file from disk and base64-encodes it, which is pure
     * waste under Octane. We cache the encoded URI for 10 minutes keyed
     * by tenant id + path + mtime — so a freshly uploaded logo invalidates
     * the cache automatically.
     *
     * Cache::driver('array') is used so this lives only inside the current
     * Octane worker (no cross-tenant leakage via Redis).
     */
    protected function resolveLogoDataUri(int $tenantId, ?string $logoPath): ?string
    {
        if (!$logoPath) return null;

        $fullPath = storage_path('app/public/' . $logoPath);
        if (!is_file($fullPath)) return null;

        $key = sprintf('pdf:logo:%d:%s:%d', $tenantId, md5($logoPath), filemtime($fullPath));

        return Cache::driver('array')->remember($key, 600, function () use ($fullPath) {
            $type = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            // Normalise jpg → jpeg for the data URI MIME
            if ($type === 'jpg') $type = 'jpeg';
            $data = @file_get_contents($fullPath);
            if ($data === false) return null;
            return 'data:image/' . $type . ';base64,' . base64_encode($data);
        });
    }

    /**
     * Anonymize worker names: "Mitarbeiter A", "Mitarbeiter B", etc.
     */
    protected function anonymizeWorkerList(array $workers, string $prefix = 'Mitarbeiter'): array
    {
        $i = 0;
        foreach ($workers as $w) {
            $letter = chr(65 + ($i % 26)); // A..Z then wraps
            if ($i >= 26) $letter = chr(65 + intdiv($i, 26) - 1) . $letter;
            $w->vorname = $prefix;
            $w->name    = $letter;
            if (isset($w->dienst_nr)) $w->dienst_nr = $prefix . ' ' . $letter;
            $i++;
        }
        return $workers;
    }
}
