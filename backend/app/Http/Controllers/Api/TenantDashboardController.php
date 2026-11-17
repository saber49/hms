<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControllingAggregationService;
use App\Services\ControllingCockpitService as Cockpit;
use App\Services\TenantService;
use App\Support\BereichScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantDashboardController extends Controller
{
    /**
     * Get tenant dashboard data: plan info, usage stats, trial countdown.
     * GET /api/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $planConfig = $tenant->getPlanConfig();
        $usage = TenantService::getUsageStats($tenant);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logo_path' => $tenant->logo_path,
            ],
            'plan' => [
                'id' => $tenant->plan,
                'name' => $planConfig['name'],
                'price_monthly' => $planConfig['price_monthly'],
                'features' => $planConfig['features'],
            ],
            'subscription' => [
                'is_active' => $tenant->isSubscriptionActive(),
                'is_trial' => $tenant->plan === 'trial',
                'trial_days_remaining' => $tenant->trialDaysRemaining(),
                'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
                'subscription_ends_at' => $tenant->subscription_ends_at?->toISOString(),
            ],
            'usage' => $usage,
            'onboarding_completed' => $tenant->onboarding_completed,
        ]);
    }

    /**
     * Get full company profile (legal info + contact + bank).
     * GET /api/dashboard/company
     */
    public function companyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'id'                 => $tenant->id,
            'name'               => $tenant->name,
            'slug'               => $tenant->slug,
            'logo_path'          => $tenant->logo_path,
            // Effective paths: personal asset wins over company default.
            'signature_path'     => $user->signature_path ?: $tenant->signature_path,
            'stamp_path'         => $user->stamp_path ?: $tenant->stamp_path,
            // Breakdown so the profile UI can label "Eigene" vs "Firmen-Standard".
            'signature_own'      => $user->signature_path,
            'stamp_own'          => $user->stamp_path,
            'signature_default'  => $tenant->signature_path,
            'stamp_default'      => $tenant->stamp_path,
            // Contact
            'company_email'      => $tenant->company_email,
            'company_phone'      => $tenant->company_phone,
            'company_address'    => $tenant->company_address,
            'company_zip'        => $tenant->company_zip,
            'company_city'       => $tenant->company_city,
            'company_country'    => $tenant->company_country,
            'website'            => $tenant->website,
            // Legal
            'rechtsform'         => $tenant->rechtsform,
            'geschaeftsfuehrer'  => $tenant->geschaeftsfuehrer,
            'handelsregister_nr' => $tenant->handelsregister_nr,
            'registergericht'    => $tenant->registergericht,
            'ust_id'             => $tenant->ust_id,
            'steuernummer'       => $tenant->steuernummer,
            // Bank
            'bank_name'          => $tenant->bank_name,
            'iban'               => $tenant->iban,
            'bic'                => $tenant->bic,
            // Company-wide PDF branding (colours) used by BOTH the order PDF
            // editor (Bestellungen) and the Monatsabschluss worker PDFs.
            'pdf_branding'       => data_get($tenant->settings, 'pdf_branding'),
            // AI-generated custom PDF templates (e.g. pdf_templates.order.html).
            'pdf_templates'      => data_get($tenant->settings, 'pdf_templates'),
            // Break tiers for the Bestelleingang MA-Pause auto-fill.
            'pause_rules'        => data_get($tenant->settings, 'pause_rules', []),
            // Zusatz line under the company name (e.g. "Sicherheitsdienst").
            'firmen_zusatz'      => (string) data_get($tenant->settings, 'firmen_zusatz', ''),
            'arbzg'                    => \App\Support\TenantProfile::arbzg($tenant),
            'aueg'                     => ['aktiv' => \App\Support\Aueg::enabled($tenant)],
        ]);
    }

    /**
     * Update tenant settings (name, logo, branding).
     * PUT /api/dashboard/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'name'               => 'nullable|string|max:200',
            'company_email'      => 'nullable|email|max:100',
            'company_phone'      => 'nullable|string|max:50',
            'company_address'    => 'nullable|string|max:255',
            'company_city'       => 'nullable|string|max:100',
            'company_zip'        => 'nullable|string|max:20',
            'company_country'    => 'nullable|string|max:5',
            // German legal fields
            'rechtsform'         => 'nullable|string|max:50',
            'geschaeftsfuehrer'  => 'nullable|string|max:200',
            'handelsregister_nr' => 'nullable|string|max:50',
            'registergericht'    => 'nullable|string|max:100',
            'ust_id'             => 'nullable|string|max:30',
            'steuernummer'       => 'nullable|string|max:30',
            'bank_name'          => 'nullable|string|max:100',
            'iban'               => 'nullable|string|max:34',
            'bic'                => 'nullable|string|max:11',
            'website'            => 'nullable|string|max:255',
            // PDF branding — stored in the tenant.settings JSON column.
            'pdf_branding'               => 'nullable|array',
            'pdf_branding.primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pdf_branding.accent_color'  => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pdf_branding.font_family'   => 'nullable|string|max:120',
            'pdf_branding.font_size'     => 'nullable|string|in:small,medium,large',
            'pdf_branding.logo_position' => 'nullable|string|in:left,center,right',
            // Break tiers — settings.pause_rules, read by the Bestelleingang
            // wizard to auto-fill MA-Pause from the shift duration.
            'pause_rules'          => 'nullable|array|max:20',
            // Range rules: Schichtdauer von–bis (HH:MM) → Pause. Old
            // "ab X Std" rules (ab_h) are still accepted and converted.
            'pause_rules.*.von'    => ['nullable', 'string', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'pause_rules.*.bis'    => ['nullable', 'string', 'regex:/^\d{1,2}:[0-5]\d$/'],
            'pause_rules.*.ab_h'   => 'nullable|numeric|min:0|max:24',
            'pause_rules.*.min'    => 'required_with:pause_rules|integer|min:0|max:480',
            'firmen_zusatz'        => 'nullable|string|max:150',
            'arbzg'                => 'nullable|array',
            'arbzg.aktiv'          => 'nullable|boolean',
            'arbzg.max_tag'        => 'nullable|numeric|min:6|max:24',
            'arbzg.ruhezeit'       => 'nullable|numeric|min:5|max:24',
            'aueg'                 => 'nullable|array',
            'aueg.aktiv'           => 'nullable|boolean',
        ]);

        $tenant = $request->user()->tenant;

        $tenant->update($request->only([
            'name', 'company_email', 'company_phone',
            'company_address', 'company_city', 'company_zip', 'company_country',
            'rechtsform', 'geschaeftsfuehrer',
            'handelsregister_nr', 'registergericht',
            'ust_id', 'steuernummer',
            'bank_name', 'iban', 'bic', 'website',
        ]));

        // Persist company-wide PDF branding into the tenant.settings JSON.
        // The brand colour set in the "Bestellungen" PDF editor is read back
        // here so it also reaches the Monatsabschluss worker PDFs. Only the
        // provided keys are merged so a partial update never wipes the rest.
        $settingsTouched = false;
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        if ($request->has('firmen_zusatz')) {
            $settings['firmen_zusatz'] = trim((string) $request->input('firmen_zusatz', ''));
            $settingsTouched = true;
        }
        if ($request->has('pdf_branding')) {
            $existing = is_array($settings['pdf_branding'] ?? null) ? $settings['pdf_branding'] : [];
            $incoming = (array) $request->input('pdf_branding', []);
            foreach (['primary_color', 'accent_color', 'font_family', 'font_size', 'logo_position'] as $k) {
                if (array_key_exists($k, $incoming) && $incoming[$k] !== null && $incoming[$k] !== '') {
                    $existing[$k] = $incoming[$k];
                }
            }
            $settings['pdf_branding'] = $existing;
            $settingsTouched = true;
        }

        // Break tiers — a complete list, replaced wholesale when the key is
        // sent (the account form only sends it while the Unternehmensdaten
        // tab is rendered, so saves from other tabs never touch it).
        if ($request->has('pause_rules')) {
            [$rules, $ruleError] = self::normalizePauseRules((array) $request->input('pause_rules', []));
            if ($ruleError) return response()->json(['error' => $ruleError], 422);
            $settings['pause_rules'] = $rules;
            $settingsTouched = true;
        }
        // ArbZG planning hints — switch + limits, only when the key is sent.
        if ($request->has('arbzg')) {
            $cur = \App\Support\TenantProfile::arbzg($tenant);
            $in = (array) $request->input('arbzg', []);
            $settings['arbzg'] = [
                'aktiv'    => array_key_exists('aktiv', $in) ? (bool) $in['aktiv'] : $cur['aktiv'],
                'max_tag'  => isset($in['max_tag']) && $in['max_tag'] !== '' ? (float) $in['max_tag'] : $cur['max_tag'],
                'ruhezeit' => isset($in['ruhezeit']) && $in['ruhezeit'] !== '' ? (float) $in['ruhezeit'] : $cur['ruhezeit'],
            ];
            $settingsTouched = true;
        }
        // AÜG module switch — only when the key is sent.
        if ($request->has('aueg.aktiv')) {
            $settings['aueg'] = ['aktiv' => (bool) $request->input('aueg.aktiv')];
            $settingsTouched = true;
        }
        if ($settingsTouched) {
            $tenant->settings = $settings;
            $tenant->save();
        }

        // Return the same payload shape used by login/check so the
        // frontend can replace its localStorage `hms_tenant` in one go,
        // keeping the PDF footer, sidebar logo, etc. immediately in sync.
        return response()->json([
            'success' => true,
            'tenant'  => \App\Http\Controllers\Api\AuthController::buildTenantPayload($tenant->fresh()),
        ]);
    }

    /**
     * Upload tenant logo.
     * POST /api/dashboard/logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048',
        ]);

        $tenant = $request->user()->tenant;

        // Defense-in-depth: validate the tenant slug before we use it to
        // build a storage path. Slugs are produced by Str::slug() during
        // provisioning so they should already be safe, but we never trust
        // upstream invariants — a corrupt slug could make the upload land
        // in another tenant's folder.
        if (!preg_match('/^[a-z0-9][-a-z0-9_]{0,63}$/', (string) $tenant->slug)) {
            \Illuminate\Support\Facades\Log::critical('Logo upload: invalid tenant slug', [
                'tenant_id' => $tenant->id,
                'slug'      => $tenant->slug,
            ]);
            return response()->json(['error' => 'Ungültige Mandanten-Konfiguration'], 500);
        }

        $expectedPrefix = "tenants/{$tenant->slug}/logo/";
        $path = $request->file('logo')->store("tenants/{$tenant->slug}/logo", 'public');

        // Belt-and-suspenders: assert the resulting path is inside the
        // tenant's own folder. If Storage somehow handed us back something
        // different, abort and clean up before persisting the wrong path.
        if (!is_string($path) || !str_starts_with($path, $expectedPrefix)) {
            try { \Illuminate\Support\Facades\Storage::disk('public')->delete((string) $path); }
            catch (\Throwable $e) { /* best-effort cleanup */ }
            \Illuminate\Support\Facades\Log::critical('Logo upload: path scope violation', [
                'tenant_id' => $tenant->id,
                'expected'  => $expectedPrefix,
                'got'       => $path,
            ]);
            return response()->json(['error' => 'Speicherort ungültig'], 500);
        }

        // Remove the previous logo file (if any) to keep storage tidy.
        if ($tenant->logo_path) {
            try { \Illuminate\Support\Facades\Storage::disk('public')->delete($tenant->logo_path); }
            catch (\Throwable $e) { /* best-effort cleanup */ }
        }

        $tenant->update(['logo_path' => $path]);

        return response()->json([
            'success' => true,
            'logo_path' => $path,
        ]);
    }

    /**
     * Remove the tenant logo.
     * DELETE /api/dashboard/logo
     */
    public function deleteLogo(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        if ($tenant->logo_path) {
            try { \Illuminate\Support\Facades\Storage::disk('public')->delete($tenant->logo_path); }
            catch (\Throwable $e) { /* best-effort cleanup */ }
            $tenant->update(['logo_path' => null]);
        }
        return response()->json(['success' => true]);
    }

    // ====================================================================
    // Company documents (Gewerbeschein, Handelsregisterauszug, …)
    //
    // Files are stored on the public disk under tenants/{slug}/documents/.
    // The DB row carries the metadata; the actual bytes are streamed back
    // through `/api/serve?path=…` so the same Bearer-auth + path-traversal
    // guard runs on every read.
    //
    // tenant_id is filtered explicitly on every query as defense-in-depth
    // even though `tenant.boundary` middleware already pins the request.
    // ====================================================================

    /**
     * GET /api/dashboard/documents
     */
    public function listDocuments(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $rows = \App\Models\TenantDocument::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get();

        // Resolve uploader names in one round-trip from central_users.
        $uploaderIds = $rows->pluck('uploaded_by')->filter()->unique()->values()->all();
        $uploaders = [];
        if (!empty($uploaderIds)) {
            $uploaders = \Illuminate\Support\Facades\DB::connection('mysql')
                ->table('central_users')
                ->where('tenant_id', $tenant->id) // hard tenant scope
                ->whereIn('id', $uploaderIds)
                ->pluck('full_name', 'id')
                ->toArray();
        }

        $items = $rows->map(function ($d) use ($uploaders) {
            return [
                'id'                => $d->id,
                'category'          => $d->category,
                'category_label'    => \App\Models\TenantDocument::CATEGORIES[$d->category]
                                        ?? $d->category,
                'title'             => $d->title,
                'original_filename' => $d->original_filename,
                'mime_type'         => $d->mime_type,
                'size_bytes'        => (int) $d->size_bytes,
                'uploaded_by'       => $d->uploaded_by,
                'uploaded_by_name'  => $d->uploaded_by ? ($uploaders[$d->uploaded_by] ?? null) : null,
                'created_at'        => $d->created_at?->toISOString(),
                // SECURITY: We deliberately do NOT expose `file_path` here.
                // The frontend addresses files by ID and the bytes are streamed
                // through the dedicated /view + /download endpoints below, which
                // re-verify tenant ownership in the DB.
            ];
        });

        return response()->json([
            'categories' => \App\Models\TenantDocument::CATEGORIES,
            'items'      => $items,
        ]);
    }

    /**
     * POST /api/dashboard/documents
     *
     * Form-data: file, category, title.
     * 10 MB max; whitelist: pdf, png, jpg, jpeg, webp.
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $allowedCategories = array_keys(\App\Models\TenantDocument::CATEGORIES);

        $request->validate([
            'file'     => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg,webp',
            'category' => 'required|string|in:' . implode(',', $allowedCategories),
            'title'    => 'required|string|max:200',
        ]);

        $tenant = $request->user()->tenant;
        $file   = $request->file('file');

        // Defense-in-depth: even though slugs come from Str::slug() during
        // tenant provisioning, refuse to build a path with anything outside
        // a strict whitelist. A bad slug here would silently break tenant
        // isolation when files land in the wrong folder.
        if (!preg_match('/^[a-z0-9][-a-z0-9_]{0,63}$/', (string) $tenant->slug)) {
            \Illuminate\Support\Facades\Log::critical('TenantDocument upload: invalid tenant slug', [
                'tenant_id' => $tenant->id,
                'slug'      => $tenant->slug,
            ]);
            return response()->json(['error' => 'Ung\u00fcltige Mandanten-Konfiguration'], 500);
        }

        // Whitelist the extension we actually allow on disk and reject
        // anything else, so a crafted Content-Type header can't smuggle
        // an unintended extension past the framework's mime guesser.
        $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
        $ext        = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (!in_array($ext, $allowedExt, true)) {
            return response()->json(['error' => 'Dateityp nicht erlaubt'], 422);
        }

        // Store under a UUID filename to prevent guessable URLs while
        // preserving the extension for the streamer's Content-Type.
        $filename = \Illuminate\Support\Str::uuid()->toString() . '.' . $ext;
        $dir      = "tenants/{$tenant->slug}/documents";
        $path     = $file->storeAs($dir, $filename, 'public');

        if (!$path) {
            return response()->json(['error' => 'Datei konnte nicht gespeichert werden'], 500);
        }

        // Sanitize the original filename: strip path components (defense
        // against ../foo and Windows-style names from broken clients) and
        // cap the length so it can't blow up the Content-Disposition header.
        $rawName  = $file->getClientOriginalName();
        $safeName = basename(str_replace('\\', '/', (string) $rawName));
        $safeName = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $safeName); // drop control chars
        if (strlen($safeName) > 200) $safeName = substr($safeName, 0, 200);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            $safeName = 'dokument.' . $ext;
        }

        $doc = \App\Models\TenantDocument::create([
            'tenant_id'         => $tenant->id,
            'category'          => $request->input('category'),
            'title'             => trim((string) $request->input('title')),
            'file_path'         => $path,
            'original_filename' => $safeName,
            'mime_type'         => $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes'        => $file->getSize() ?: 0,
            'uploaded_by'       => $request->user()->id,
        ]);

        // Best-effort thumbnail generation. Failures are logged inside the
        // service and do not bubble up — the upload itself stays successful
        // and the UI falls back to the generic icon.
        \App\Services\DocumentThumbnailer::generate('public', $path);

        return response()->json([
            'success' => true,
            'id'      => $doc->id,
        ], 201);
    }

    /**
     * GET /api/dashboard/documents/{id}/thumb
     *
     * Streams a small JPG preview (300×400) of the document. Same
     * security model as the /view endpoint: tenant_id filter in DB +
     * path prefix verification + private no-store cache headers.
     * If the thumbnail does not exist yet (legacy upload pre-feature),
     * it is rendered on the fly in this request.
     */
    public function thumbDocument(Request $request, int $id)
    {
        $tenant = $request->user()->tenant;

        $doc = \App\Models\TenantDocument::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();
        if (!$doc) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        $expectedPrefix = "tenants/{$tenant->slug}/documents/";
        if (!str_starts_with($doc->file_path, $expectedPrefix)) {
            return response()->json(['error' => 'Zugriff verweigert'], 403);
        }

        $thumbPath = \App\Services\DocumentThumbnailer::generate('public', $doc->file_path);
        if (!$thumbPath) {
            return response()->json(['error' => 'Thumbnail nicht verfügbar'], 404);
        }

        return response()->file(
            \Illuminate\Support\Facades\Storage::disk('public')->path($thumbPath),
            [
                'Content-Type'           => 'image/jpeg',
                'Cache-Control'          => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * DELETE /api/dashboard/documents/{id}
     */
    public function deleteDocument(Request $request, int $id): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $doc = \App\Models\TenantDocument::where('tenant_id', $tenant->id) // tenant scope
            ->where('id', $id)
            ->first();

        if (!$doc) {
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        try { \Illuminate\Support\Facades\Storage::disk('public')->delete($doc->file_path); }
        catch (\Throwable $e) { /* best-effort cleanup */ }
        \App\Services\DocumentThumbnailer::delete('public', $doc->file_path);

        $doc->delete();

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/dashboard/documents/{id}/view
     *
     * Streams the file inline (so PDFs / images render in a browser tab).
     * Security model — three independent layers:
     *   1. auth:sanctum middleware (Bearer token required).
     *   2. tenant.boundary middleware pins the active tenant DB connection.
     *   3. The DB lookup itself filters by `tenant_id = current_tenant->id`,
     *      so even a leaked / guessed numeric id from another tenant returns
     *      a 404 — the file_path never reaches the wire.
     */
    public function viewDocument(Request $request, int $id)
    {
        return $this->streamDocument($request, $id, /*download*/ false);
    }

    /**
     * GET /api/dashboard/documents/{id}/download
     */
    public function downloadDocument(Request $request, int $id)
    {
        return $this->streamDocument($request, $id, /*download*/ true);
    }

    /**
     * Shared streamer — does the tenant scope check then hands the bytes
     * to Laravel's response helpers. Never returns the file_path to the
     * caller (intentional — the path is an internal implementation detail).
     */
    private function streamDocument(Request $request, int $id, bool $forceDownload)
    {
        $tenant = $request->user()->tenant;

        $doc = \App\Models\TenantDocument::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();

        if (!$doc) {
            // 404 — same response shape regardless of whether the row
            // exists in another tenant or not at all (no information leak).
            return response()->json(['error' => 'Dokument nicht gefunden'], 404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$disk->exists($doc->file_path)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }

        // Belt-and-suspenders: the on-disk path MUST sit under
        // tenants/{slug}/documents/. If it doesn't, abort — the row was
        // either crafted by a buggy code-path or we have a bigger problem.
        $expectedPrefix = "tenants/{$tenant->slug}/documents/";
        if (!str_starts_with($doc->file_path, $expectedPrefix)) {
            \Illuminate\Support\Facades\Log::critical('TenantDocument path scope violation', [
                'tenant_id' => $tenant->id,
                'doc_id'    => $doc->id,
                'expected'  => $expectedPrefix,
                'got'       => $doc->file_path,
            ]);
            return response()->json(['error' => 'Zugriff verweigert'], 403);
        }

        $absolute = $disk->path($doc->file_path);
        $headers  = [
            'Content-Type'  => $doc->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            // Prevent Cloudflare and any intermediate proxy from caching
            // private documents.
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
        ];

        if ($forceDownload) {
            return response()->download(
                $absolute,
                $doc->original_filename ?: ('dokument-' . $doc->id),
                $headers
            );
        }

        return response()->file($absolute, $headers);
    }

    /**
     * Rich operational overview for the Übersicht page.
     * GET /api/dashboard/overview
     *
     * Returns a single payload combining:
     *  - month KPIs vs previous month (Umsatz / Gewinn / Einsätze / Stunden)
     *  - 30-day revenue trend for the sparkline
     *  - today's Einsätze (the operational schedule)
     *  - top customers + top objects of the month
     *  - alert center (stale ausstehend, expiring docs, new incoming orders, …)
     *  - active Stammdaten counts
     *  - recent activity feed (last Einsätze + Bestellungen)
     *
     * All queries run on the `tenant` connection and use existing indexes
     * (idx_einsaetze_datum, idx_einsaetze_datum_hms, idx_bestellungen_status).
     */
    public function overview(Request $request): JsonResponse
    {
        $now             = Carbon::now();
        $today           = $now->toDateString();
        $allowedPeriods  = ['today','yesterday','week','last_week','month','last_month','custom'];
        $period          = in_array($request->input('period'), $allowedPeriods, true)
            ? $request->input('period') : 'month';

        $prevLabel = 'Vormonat';
        switch ($period) {
            case 'today':
                $rangeStart = $today;
                $rangeEnd   = $today;
                $prevRangeStart = $now->copy()->subDay()->toDateString();
                $prevRangeEnd   = $prevRangeStart;
                $periodLabel = 'Heute';
                $prevLabel   = 'Gestern';
                break;
            case 'yesterday':
                $rangeStart = $now->copy()->subDay()->toDateString();
                $rangeEnd   = $rangeStart;
                $prevRangeStart = $now->copy()->subDays(2)->toDateString();
                $prevRangeEnd   = $prevRangeStart;
                $periodLabel = 'Gestern';
                $prevLabel   = 'Vortag';
                break;
            case 'week':
                $rangeStart = $now->copy()->startOfWeek()->toDateString();
                $rangeEnd   = $now->copy()->endOfWeek()->toDateString();
                $prevRangeStart = $now->copy()->subWeek()->startOfWeek()->toDateString();
                $prevRangeEnd   = $now->copy()->subWeek()->endOfWeek()->toDateString();
                $periodLabel = 'Diese Woche';
                $prevLabel   = 'Vorwoche';
                break;
            case 'last_week':
                $rangeStart = $now->copy()->subWeek()->startOfWeek()->toDateString();
                $rangeEnd   = $now->copy()->subWeek()->endOfWeek()->toDateString();
                $prevRangeStart = $now->copy()->subWeeks(2)->startOfWeek()->toDateString();
                $prevRangeEnd   = $now->copy()->subWeeks(2)->endOfWeek()->toDateString();
                $periodLabel = 'Letzte Woche';
                $prevLabel   = 'Vorwoche';
                break;
            case 'last_month':
                $rangeStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
                $rangeEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();
                $prevRangeStart = $now->copy()->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
                $prevRangeEnd   = $now->copy()->subMonthsNoOverflow(2)->endOfMonth()->toDateString();
                $periodLabel = 'Letzter Monat';
                $prevLabel   = 'Vormonat';
                break;
            case 'custom':
                try {
                    $from = Carbon::parse($request->input('from', $today))->startOfDay();
                    $to   = Carbon::parse($request->input('to',   $today))->startOfDay();
                } catch (\Throwable $e) {
                    $from = $now->copy()->startOfDay();
                    $to   = $now->copy()->startOfDay();
                }
                if ($to->lt($from)) { [$from, $to] = [$to, $from]; }
                $days = $from->diffInDays($to) + 1;
                $rangeStart = $from->toDateString();
                $rangeEnd   = $to->toDateString();
                $prevRangeEnd   = $from->copy()->subDay()->toDateString();
                $prevRangeStart = $from->copy()->subDays($days)->toDateString();
                $periodLabel = $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
                $prevLabel   = 'Vorperiode';
                break;
            default:
                $rangeStart = $now->copy()->startOfMonth()->toDateString();
                $rangeEnd   = $now->copy()->endOfMonth()->toDateString();
                $prevRangeStart = $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
                $prevRangeEnd   = $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();
                $periodLabel = 'Dieser Monat';
                $prevLabel   = 'Vormonat';
                break;
        }

        // slim=1 (new Übersicht): skip the lists it no longer shows.
        $slim = $request->boolean('slim');
        $thirtyDaysAgo   = $now->copy()->subDays(29)->toDateString();
        $sevenDaysAgo    = $now->copy()->subDays(7)->toDateString();
        $thirtyDaysAhead = $now->copy()->addDays(30)->toDateString();

        // ------ Permission scoping ------
        // The Übersicht only shows the blocks the current user is allowed to
        // see. e.g. a user with just `stammdaten.kunden.view` sees the Kunden
        // count but NOT revenue/profit (controlling.view) or the operational
        // Einsatz schedule (auftraege.dispo.view). Owners/admins hold every
        // key (CentralUser::getEffectivePermissions) so they see everything.
        $actor = $request->user();
        $can = fn (string $key): bool => $actor && method_exists($actor, 'hasPermission')
            ? $actor->hasPermission($key) : false;

        $canCtrl    = $can('controlling.view');           // Umsatz/Gewinn/Trend/Top
        $canDispo   = $can('auftraege.dispo.view');        // Einsätze KPIs + schedule
        $canEingang = $can('auftraege.eingang.view');
        $canBestell = $can('auftraege.bestellungen.view');
        $canKunden  = $can('stammdaten.kunden.view');
        $canObjekte = $can('stammdaten.objekte.view');
        $canNu      = $can('stammdaten.nu.view');
        $canMa      = $can('stammdaten.mitarbeiter.view');

        // ------ Bereich scoping (on top of module permissions) ------
        // A restricted user (allowed_bereiche whitelist) only ever sees and
        // aggregates rows inside his Bereiche — every count, KPI, list and
        // alert below carries the matching predicate. Unrestricted users
        // get a '1=1' fragment, so nothing changes for them.
        $allowedBereiche = BereichScope::for($actor);
        $einsatzScopeE   = BereichScope::inList($allowedBereiche, BereichScope::derivedExprSub('e'));
        $einsatzScopeT   = BereichScope::inList($allowedBereiche, BereichScope::derivedExprSub('einsaetze'));
        $bestellScopeT   = BereichScope::inList($allowedBereiche, BereichScope::derivedExprSub('bestellungen'));
        $bestellScopeB   = BereichScope::inList($allowedBereiche, BereichScope::derivedExprSub('b'));
        $eingangScopeT   = BereichScope::inList($allowedBereiche, BereichScope::derivedExprSub('bestelleingang'));
        $kundeScope      = BereichScope::kundePredicate($actor, 'kunden');
        $objektScope     = BereichScope::objektPredicate($actor, 'objekte');
        $nuScope         = BereichScope::nuPredicate($actor, 'nachunternehmer');
        $workerScope     = BereichScope::workerPredicate($actor, 'mitarbeiter');
        $workerScopeM    = BereichScope::workerPredicate($actor, 'm');
        $artikelScope    = BereichScope::artikelPredicate($actor, 'artikel');

        // ------ Monthly KPIs (current + previous month for delta) ------
        // Only computed when a financial/operational widget needs them.
        $needKpis = $canCtrl || $canDispo;
        $monthRow = $needKpis ? $this->kpiForRange($rangeStart, $rangeEnd) : null;
        $prevRow  = $needKpis ? $this->kpiForRange($prevRangeStart, $prevRangeEnd) : null;

        // ------ Financial KPIs: the SAME calculation as the Controlling
        // cockpit (operative view = freigegeben + geleistet, hours from the
        // Zeiterfassung, DB II after Lohnnebenkosten, like-for-like compare).
        $fin = null;
        $ctx = null;
        $op = [];
        if ($canCtrl) {
            $ctx = Cockpit::context();
            $op = Cockpit::statusesFor('operativ');
            $curT = Cockpit::totals($ctx, $rangeStart, $rangeEnd);
            $cmp = Cockpit::comparison($rangeStart, $rangeEnd, 'prev');
            $curCmpT = ($cmp && $cmp['cur_to'] !== $rangeEnd) ? Cockpit::totals($ctx, $rangeStart, $cmp['cur_to']) : $curT;
            $prevT = $cmp ? Cockpit::totals($ctx, $cmp['from'], $cmp['to']) : Cockpit::totals($ctx, $prevRangeStart, $prevRangeEnd);
            $fin = [
                'cur'     => Cockpit::result($ctx, $curT, $op),
                'cur_cmp' => Cockpit::result($ctx, $curCmpT, $op),
                'prev'    => Cockpit::result($ctx, $prevT, $op),
                'erw'     => Cockpit::result($ctx, $curT, ['freigegeben', 'offen', 'geplant']),
                'offen'   => $curT['offen']['einsaetze'],
                'label'   => $prevLabel . ($cmp && $cmp['cut'] ? ' bis ' . Carbon::parse($cmp['to'])->format('d.m.') : ''),
            ];
        }

        $delta = fn($curr, $prev) => $prev > 0
            ? round((($curr - $prev) / $prev) * 100, 1)
            : ($curr > 0 ? null : 0.0);

        // ------ Today vs yesterday Einsätze counts (operational) ------
        $tenantDb = DB::connection('tenant');
        $todayCount = $canDispo
            ? (int) $tenantDb->table('einsaetze')->whereDate('datum', $today)
                ->whereRaw($einsatzScopeT[0], $einsatzScopeT[1])->count()
            : 0;
        $yesterdayCount = $canDispo
            ? (int) $tenantDb->table('einsaetze')
                ->whereDate('datum', $now->copy()->subDay()->toDateString())
                ->whereRaw($einsatzScopeT[0], $einsatzScopeT[1])
                ->count()
            : 0;

        // ------ Schedule for the selected period (operational list) ------
        $scheduleTotal = $canDispo
            ? (int) $tenantDb->table('einsaetze')->whereBetween('datum', [$rangeStart, $rangeEnd])
                ->whereRaw($einsatzScopeT[0], $einsatzScopeT[1])->count()
            : 0;
        $todaysSchedule = ($canDispo && !$slim) ? $tenantDb->select("
            SELECT e.einsatz_id, e.datum, e.ist_start, e.ist_ende,
                   e.hms_bestaetigt, e.ist_std_menge,
                   k.kundenname, o.objektname, n.firmenname AS nu_name
              FROM einsaetze e
              LEFT JOIN kunden          k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte         o ON e.obj_id    = o.obj_id
              LEFT JOIN nachunternehmer n ON e.nu_id     = n.nu_id
             WHERE e.datum BETWEEN ? AND ?
               AND {$einsatzScopeE[0]}
          ORDER BY e.datum ASC, COALESCE(e.ist_start, '99:99') ASC, e.id ASC
             LIMIT 25
        ", array_merge([$rangeStart, $rangeEnd], $einsatzScopeE[1])) : [];

        // ------ Revenue trend for the sparkline (controlling.view) ------
        // Same calculation as the Controlling cockpit (operative view).
        // Follows the selected period. A 1–6 day range would give a useless
        // 1–6 point line, so short ranges are widened to the 14 days ending
        // at the range end (the selected days are still inside the window).
        $trendStart = $rangeStart;
        $trendEnd   = $rangeEnd;
        $trendLabel = $periodLabel;
        if (Carbon::parse($rangeStart)->diffInDays(Carbon::parse($rangeEnd)) < 6) {
            $trendStart = Carbon::parse($rangeEnd)->subDays(13)->toDateString();
            $trendLabel = '14 Tage bis ' . Carbon::parse($rangeEnd)->format('d.m.');
        }
        $trend = [];
        if ($canCtrl) {
            $byDay = [];
            foreach (Cockpit::rows($ctx, "DATE_FORMAT(e.datum, '%Y-%m-%d') AS dim_id", 'dim_id', '',
                         $trendStart, $trendEnd, [], $op, '', [], ['dim_id']) as $r) {
                $byDay[$r['dim_id']] = $r;
            }
            // Fill missing days so the sparkline has a continuous x-axis.
            $cursor = Carbon::parse($trendStart);
            $end    = Carbon::parse($trendEnd);
            while ($cursor <= $end) {
                $d = $cursor->toDateString();
                $trend[] = [
                    'bucket'    => $d,
                    'umsatz'    => $byDay[$d]['umsatz'] ?? 0,
                    'einsaetze' => $byDay[$d]['einsaetze'] ?? 0,
                ];
                $cursor->addDay();
            }
        }

        // ------ Top 5 customers / objects (controlling.view) ------
        $top = function (string $idCol, string $join, string $nameCol) use ($ctx, $op, $rangeStart, $rangeEnd): array {
            $rows = Cockpit::rows($ctx, "{$idCol} AS dim_id, COALESCE(MAX({$nameCol}), '–') AS dim_value", $idCol, $join,
                $rangeStart, $rangeEnd, [], $op, '', [], ['dim_id', 'dim_value']);
            usort($rows, fn ($a, $b) => [$b['umsatz'], $b['einsaetze']] <=> [$a['umsatz'], $a['einsaetze']]);
            return array_map(fn ($r) => (object) [
                'dim_id' => $r['dim_id'], 'dim_value' => $r['dim_value'], 'einsaetze' => $r['einsaetze'],
                'umsatz' => $r['umsatz'], 'db2' => $r['db2'],
            ], array_slice($rows, 0, 5));
        };
        $topCustomers = ($canCtrl && !$slim) ? $top('e.kunden_id', 'LEFT JOIN kunden k ON e.kunden_id = k.kunde_id', 'k.kundenname') : [];
        $topObjects   = ($canCtrl && !$slim) ? $top('e.obj_id', 'LEFT JOIN objekte o ON e.obj_id = o.obj_id', 'o.objektname') : [];

        // ------ Alerts ------
        $alerts = [];

        // 1. ausstehend Einsätze older than 7 days (auftraege.dispo.view)
        if ($canDispo) {
            $staleAusstehend = (int) $tenantDb->table('einsaetze')
                ->where('hms_bestaetigt', 'ausstehend')
                ->whereDate('datum', '<', $sevenDaysAgo)
                ->whereRaw($einsatzScopeT[0], $einsatzScopeT[1])
                ->count();
            if ($staleAusstehend > 0) {
                $alerts[] = [
                    'level' => 'warning', 'icon' => 'fa-hourglass-half',
                    'text'  => "{$staleAusstehend} Einsätze warten seit über 7 Tagen auf Bestätigung",
                    'cta'   => 'Bestätigen', 'page' => 'tasks',
                ];
            }
        }

        // 2. unprocessed Bestelleingänge (auftraege.eingang.view)
        if ($canEingang) {
            $incoming = (int) $tenantDb->table('bestelleingang')
                ->where('status', 'unbearbeitet')
                ->whereRaw($eingangScopeT[0], $eingangScopeT[1])
                ->count();
            if ($incoming > 0) {
                $alerts[] = [
                    'level' => 'info', 'icon' => 'fa-inbox',
                    'text'  => "{$incoming} neue Bestelleingänge zu bearbeiten",
                    'cta'   => 'Öffnen', 'page' => 'incoming_orders',
                ];
            }
        }

        // 3. expiring worker documents (next 30 days, stammdaten.mitarbeiter.view)
        if ($canMa && Schema::connection('tenant')->hasTable('mitarbeiter_dokumente')) {
            $expiring = (int) $tenantDb->table('mitarbeiter_dokumente')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'mitarbeiter_dokumente.dienst_nr')
                ->whereNotNull('mitarbeiter_dokumente.ablaufdatum')
                ->whereDate('mitarbeiter_dokumente.ablaufdatum', '>=', $today)
                ->whereDate('mitarbeiter_dokumente.ablaufdatum', '<=', $thirtyDaysAhead)
                ->whereRaw($workerScopeM[0], $workerScopeM[1])
                ->count();
            if ($expiring > 0) {
                $alerts[] = [
                    'level' => 'warning', 'icon' => 'fa-id-card',
                    'text'  => "{$expiring} Mitarbeiter-Dokumente laufen in 30 Tagen ab",
                    'cta'   => 'Verwalten', 'page' => 'workers',
                ];
            }

            $expired = (int) $tenantDb->table('mitarbeiter_dokumente')
                ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'mitarbeiter_dokumente.dienst_nr')
                ->whereNotNull('mitarbeiter_dokumente.ablaufdatum')
                ->whereDate('mitarbeiter_dokumente.ablaufdatum', '<', $today)
                ->whereRaw($workerScopeM[0], $workerScopeM[1])
                ->count();
            if ($expired > 0) {
                $alerts[] = [
                    'level' => 'danger', 'icon' => 'fa-exclamation-circle',
                    'text'  => "{$expired} Mitarbeiter-Dokumente sind bereits abgelaufen",
                    'cta'   => 'Sofort prüfen', 'page' => 'workers',
                ];
            }
        }

        // 4. open Bestellungen (auftraege.bestellungen.view)
        if ($canBestell) {
            $openOrders = (int) $tenantDb->table('bestellungen')
                ->where('status', 'offen')
                ->whereRaw($bestellScopeT[0], $bestellScopeT[1])
                ->count();
            if ($openOrders > 0) {
                $alerts[] = [
                    'level' => 'info', 'icon' => 'fa-file-invoice',
                    'text'  => "{$openOrders} offene Bestellungen",
                    'cta'   => 'Anzeigen', 'page' => 'orders',
                ];
            }
        }

        // 5. Artikel without prices (silent killer for revenue tracking,
        // stammdaten.objekte.view). Pricing lives on the artikel table.
        if ($canObjekte) {
            $artNoPrice = Schema::connection('tenant')->hasTable('artikel')
                ? (int) $tenantDb->table('artikel')
                    ->where('aktiv', 1)
                    ->where(function ($q) {
                        $q->where('preis_kunde', 0)->orWhereNull('preis_kunde');
                    })
                    ->whereRaw($artikelScope[0], $artikelScope[1])
                    ->count()
                : 0;
            if ($artNoPrice > 0) {
                $alerts[] = [
                    'level' => 'warning', 'icon' => 'fa-tag',
                    'text'  => "{$artNoPrice} Artikel ohne Kundenpreis — Umsätze werden nicht berechnet",
                    'cta'   => 'Preise pflegen', 'page' => 'objects',
                ];
            }
        }

        // ------ Active Stammdaten counts (each gated by its view permission) ------
        // The totals are a live snapshot (aktiv = 1) and cannot follow the
        // period; `neu` = records created within the selected period.
        $counts = [];
        $neu = [];
        $createdInRange = function (string $table, array $pred) use ($tenantDb, $rangeStart, $rangeEnd): int {
            $q = $tenantDb->table($table)
                ->whereBetween(DB::raw('DATE(created_at)'), [$rangeStart, $rangeEnd]);
            if ($pred[0] !== '1=1') {
                $q->whereRaw($pred[0], $pred[1]);
            }
            return (int) $q->count();
        };
        if ($canKunden) {
            $counts['kunden'] = (int) $tenantDb->table('kunden')->where('aktiv', 1)
                ->whereRaw($kundeScope[0], $kundeScope[1])->count();
            $neu['kunden']    = $createdInRange('kunden', $kundeScope);
        }
        if ($canObjekte) {
            $counts['objekte'] = (int) $tenantDb->table('objekte')->where('aktiv', 1)
                ->whereRaw($objektScope[0], $objektScope[1])->count();
            $neu['objekte']    = $createdInRange('objekte', $objektScope);
        }
        if ($canNu) {
            $counts['nu'] = (int) $tenantDb->table('nachunternehmer')->where('aktiv', 1)
                ->whereRaw($nuScope[0], $nuScope[1])->count();
            $neu['nu']    = $createdInRange('nachunternehmer', $nuScope);
        }
        if ($canMa) {
            $counts['mitarbeiter'] = Schema::connection('tenant')->hasTable('mitarbeiter')
                ? (int) $tenantDb->table('mitarbeiter')->where('aktiv', 1)
                    ->whereRaw($workerScope[0], $workerScope[1])->count()
                : 0;
            $neu['mitarbeiter'] = Schema::connection('tenant')->hasTable('mitarbeiter')
                ? $createdInRange('mitarbeiter', $workerScope) : 0;
            // Only count clock-ins from the last 24h — a forgotten "Stop"
            // from days ago must not keep someone "im Einsatz" forever.
            $counts['im_einsatz'] = Schema::connection('tenant')->hasTable('zeiterfassung')
                ? (int) $tenantDb->table('zeiterfassung')
                    ->join('mitarbeiter as m', 'm.dienst_nr', '=', 'zeiterfassung.dienst_nr')
                    ->whereIn('zeiterfassung.status', ['aktiv', 'pause'])
                    ->where('zeiterfassung.clock_in', '>=', now()->subDay())
                    ->whereRaw($workerScopeM[0], $workerScopeM[1])
                    ->distinct()->count('zeiterfassung.dienst_nr')
                : 0;
        }

        // ------ Recent activity feed within the selected period ------
        // (Einsätze need dispo.view, Bestellungen need bestellungen.view)
        $recentEinsaetze = ($canDispo && !$slim) ? $tenantDb->select("
            SELECT 'einsatz' AS kind, e.einsatz_id AS id, e.datum AS at,
                   e.hms_bestaetigt AS status, k.kundenname AS who, o.objektname AS what
              FROM einsaetze e
              LEFT JOIN kunden  k ON e.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON e.obj_id    = o.obj_id
             WHERE e.datum BETWEEN ? AND ?
               AND {$einsatzScopeE[0]}
          ORDER BY e.datum DESC, e.id DESC
             LIMIT 8
        ", array_merge([$rangeStart, $rangeEnd], $einsatzScopeE[1])) : [];
        $recentOrders = ($canBestell && !$slim) ? $tenantDb->select("
            SELECT 'bestellung' AS kind, b.bestell_id AS id,
                   COALESCE(b.datum_von, DATE(b.created_at)) AS at,
                   b.status, k.kundenname AS who, o.objektname AS what
              FROM bestellungen b
              LEFT JOIN kunden  k ON b.kunden_id = k.kunde_id
              LEFT JOIN objekte o ON b.obj_id    = o.obj_id
             WHERE COALESCE(b.datum_von, DATE(b.created_at)) BETWEEN ? AND ?
               AND {$bestellScopeB[0]}
          ORDER BY at DESC, b.id DESC
             LIMIT 8
        ", array_merge([$rangeStart, $rangeEnd], $bestellScopeB[1])) : [];
        $recent = array_merge($recentEinsaetze, $recentOrders);
        usort($recent, fn($a, $b) => strcmp((string) $b->at, (string) $a->at));
        $recent = array_slice($recent, 0, 10);

        // ------ User greeting ------
        $hour = (int) $now->format('H');
        $greeting = $hour < 5  ? 'Gute Nacht'
                  : ($hour < 11 ? 'Guten Morgen'
                  : ($hour < 17 ? 'Guten Tag'
                  :                'Guten Abend'));
        $user = $request->user();
        $userName = $user->full_name ?? $user->name ?? $user->username ?? '';

        // German weekday/month names
        $weekdaysDe = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        $monthsDe   = ['','Januar','Februar','März','April','Mai','Juni','Juli',
                       'August','September','Oktober','November','Dezember'];

        // ------ KPI cards (only the ones the user is permitted to see) ------
        // Financial KPIs (Umsatz/Gewinn) require controlling.view; operational
        // KPIs (Einsätze/Stunden/heute/ausstehend) require auftraege.dispo.view.
        $kpis = [];
        if ($fin) {
            // Same numbers as the Controlling cockpit (operative view). The
            // delta compares like-for-like windows (cut at today), "erwartet"
            // is the cockpit Hochrechnung (+ planned shifts).
            $kpis['umsatz_monat'] = [
                'value'      => $fin['cur']['umsatz'],
                'prev'       => $fin['prev']['umsatz'],
                'prev_label' => $fin['label'],
                'delta'      => $delta($fin['cur_cmp']['umsatz'], $fin['prev']['umsatz']),
                'erwartet'   => $fin['erw']['umsatz'],
                'offen'      => $fin['offen'],
            ];
            $kpis['gewinn_monat'] = [
                'label'      => 'Deckungsbeitrag II',
                'value'      => $fin['cur']['db2'],
                'marge'      => $fin['cur']['marge_db2'],
                'ampel'      => $fin['cur']['ampel'],
                'prev'       => $fin['prev']['db2'],
                'prev_label' => $fin['label'],
                'delta'      => $fin['prev']['db2'] != 0
                    ? round(($fin['cur_cmp']['db2'] - $fin['prev']['db2']) / abs($fin['prev']['db2']) * 100, 1)
                    : ($fin['cur_cmp']['db2'] != 0 ? null : 0.0),
                'erwartet'   => $fin['erw']['db2'],
                'offen'      => $fin['offen'],
            ];
        }
        if ($canDispo && $monthRow && $prevRow) {
            $kpis['einsaetze_monat'] = [
                'value'  => (int) $monthRow->einsaetze_count,
                'prev'   => (int) $prevRow->einsaetze_count,
                'delta'  => $delta((float) $monthRow->einsaetze_count, (float) $prevRow->einsaetze_count),
            ];
            $kpis['stunden_monat'] = [
                'value'  => round((float) $monthRow->ist_stunden, 2),
                'prev'   => round((float) $prevRow->ist_stunden, 2),
                'delta'  => $delta((float) $monthRow->ist_stunden, (float) $prevRow->ist_stunden),
            ];
            $kpis['today_einsaetze'] = [
                'value' => $todayCount,
                'prev'  => $yesterdayCount,
            ];
            $kpis['ausstehend_count'] = (int) $monthRow->ausstehend_count;
        }

        return response()->json([
            'user' => [
                'greeting' => $greeting,
                'name'     => $userName,
            ],
            'date' => [
                'today'   => $today,
                'weekday' => $weekdaysDe[(int) $now->format('w')],
                'month'   => $monthsDe[(int) $now->format('n')],
                'kw'      => (int) $now->format('W'),
            ],
            'period'       => $period,
            'period_label' => $periodLabel,
            'prev_label'   => $prevLabel,
            'range'        => ['from' => $rangeStart, 'to' => $rangeEnd],
            // Capability flags so the frontend renders exactly the blocks the
            // user may see (mirror of the server-side gating above).
            'can' => [
                'controlling'  => $canCtrl,
                'dispo'        => $canDispo,
                'eingang'      => $canEingang,
                'bestellungen' => $canBestell,
                'kunden'       => $canKunden,
                'objekte'      => $canObjekte,
                'nu'           => $canNu,
                'mitarbeiter'  => $canMa,
            ],
            'kpis'           => $kpis,
            'counts'         => $counts,
            'counts_neu'     => $neu,
            'today_schedule' => $todaysSchedule,
            'schedule_total' => $scheduleTotal,
            'trend_30d'      => $trend,
            'trend_label'    => $trendLabel,
            'top_customers'  => $topCustomers,
            'top_objects'    => $topObjects,
            'alerts'         => $alerts,
            'recent'         => $recent,
        ]);
    }

    /**
     * Internal helper: OPERATIONAL KPIs (Einsätze, ausstehend, Stunden) for a
     * date range. Money lives in ControllingCockpitService (see $fin above).
     */
    private function kpiForRange(string $from, string $to): object
    {
        $bill     = ControllingAggregationService::billableStdExpr();
        $billJoin = ControllingAggregationService::billableJoin();
        // Bereich restriction: derived Bereich (artikel → kunde) must be in
        // the caller's whitelist. '1=1' for unrestricted users.
        [$scopeSql, $scopeParams] = BereichScope::inList(
            BereichScope::for(auth()->user()),
            BereichScope::derivedExprSub('e')
        );
        return DB::connection('tenant')->selectOne("
            SELECT
              COUNT(*)                                                    AS einsaetze_count,
              SUM(CASE WHEN e.hms_bestaetigt = 'ausstehend' THEN 1 ELSE 0 END) AS ausstehend_count,
              COALESCE(SUM({$bill}), 0)                                   AS ist_stunden
            FROM einsaetze e
            LEFT JOIN artikel a ON e.artikel_id = a.artikel_id
            {$billJoin}
            WHERE e.datum BETWEEN ? AND ?
              AND {$scopeSql}
        ", array_merge([$from, $to], $scopeParams));
    }

    /**
     * Pausenregelung as RANGES of the shift duration: [{von, bis, min}]
     * (HH:MM, inclusive, von <= bis <= 24:00, no overlaps). Old threshold
     * rules [{ab_h, min}] convert to ranges (each up to 1 Min before the
     * next threshold, the last one up to 24:00). Every rule also carries
     * `ab_h` = von in hours for cached old clients.
     *
     * @return array{0: array, 1: ?string}
     */
    public static function normalizePauseRules(array $input): array
    {
        $toMin = function ($v): ?int {
            if (!is_string($v) || !preg_match('/^(\d{1,2}):([0-5]\d)$/', trim($v), $m)) return null;
            $t = (int) $m[1] * 60 + (int) $m[2];
            return $t <= 1440 ? $t : null;
        };
        $fmt = fn (int $t) => sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
        $ranges = [];
        $legacy = [];
        foreach ($input as $r) {
            if (!is_array($r) || !isset($r['min']) || $r['min'] === '') continue;
            $min = max(0, min(480, (int) $r['min']));
            if (isset($r['von']) || isset($r['bis'])) {
                $von = $toMin($r['von'] ?? null);
                $bis = $toMin($r['bis'] ?? null);
                if ($von === null || $bis === null) return [[], 'Pausenregelung: Von und Bis im Format HH:MM angeben (z. B. 06:01).'];
                if ($bis < $von) return [[], 'Pausenregelung: „Bis“ (' . $fmt($bis) . ') liegt vor „Von“ (' . $fmt($von) . ').'];
                $ranges[] = ['v' => $von, 'b' => $bis, 'min' => $min];
            } elseif (isset($r['ab_h']) && is_numeric($r['ab_h'])) {
                $legacy[] = ['v' => (int) round(((float) $r['ab_h']) * 60), 'min' => $min];
            }
        }
        if ($legacy) {
            usort($legacy, fn ($a, $b) => $a['v'] <=> $b['v']);
            foreach ($legacy as $i => $l) {
                $next = $legacy[$i + 1]['v'] ?? 1441;
                $ranges[] = ['v' => $l['v'], 'b' => min(1440, $next - 1), 'min' => $l['min']];
            }
        }
        usort($ranges, fn ($a, $b) => $a['v'] <=> $b['v']);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['v'] <= $ranges[$i - 1]['b']) {
                return [[], 'Pausenregelung: Die Bereiche ' . $fmt($ranges[$i - 1]['v']) . '–' . $fmt($ranges[$i - 1]['b'])
                    . ' und ' . $fmt($ranges[$i]['v']) . '–' . $fmt($ranges[$i]['b']) . ' überschneiden sich.'];
            }
        }
        return [array_map(fn ($r) => ['von' => $fmt($r['v']), 'bis' => $fmt($r['b']), 'min' => $r['min'],
            'ab_h' => round($r['v'] / 60, 4)], $ranges), null];
    }
}
