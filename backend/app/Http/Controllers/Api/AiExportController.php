<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Serves CSV/XLSX exports produced by the AI assistant tools
 * (e.g. export_einsaetze_csv).
 *
 * Security model
 * --------------
 * 1. Route is wrapped in Sanctum auth + the standard tenant boundary
 *    middleware, so an unauthenticated request never reaches us.
 * 2. The URL itself is signed via Laravel's `URL::temporarySignedRoute`
 *    (15-minute expiry, see AiTools::exportEinsaetzeCsv) — the `signed`
 *    middleware on the route enforces it.
 * 3. We base64-decode the path and verify it sits inside the caller's
 *    own tenant folder. Even if a signed URL leaks across tenants, the
 *    download is rejected here.
 * 4. We stream the file with a hard-coded content-type so a forged
 *    `.htaccess` or symlink never lets the route serve PHP/HTML.
 */
class AiExportController extends Controller
{
    public function download(Request $request)
    {
        $b64 = (string) $request->query('path', '');
        $path = base64_decode($b64, true);

        if ($path === false || $path === '') {
            return response()->json(['error' => 'invalid_path'], 400);
        }

        // Defense-in-depth: prevent path traversal in case base64 decoded
        // to something with .. or absolute path components.
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return response()->json(['error' => 'invalid_path'], 400);
        }

        // Path must be `ai-exports/{tenant_slug}/{filename.ext}` and
        // the extension must be one we actually generate (csv / xlsx).
        if (!preg_match('#^ai-exports/([a-z0-9_-]+)/[a-z0-9_.-]+\.(csv|xlsx)$#i', $path, $m)) {
            return response()->json(['error' => 'invalid_path'], 400);
        }
        $pathTenant = $m[1];
        $ext        = strtolower($m[2]);

        // Verify the path's tenant matches the caller's current tenant.
        $current = TenantService::current();
        if (!$current) {
            return response()->json(['error' => 'no_tenant'], 403);
        }
        $expectedSlug = preg_replace('/[^a-z0-9_-]/i', '_', (string) $current->slug);
        if ($pathTenant !== $expectedSlug) {
            return response()->json(['error' => 'tenant_mismatch'], 403);
        }

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'file_not_found'], 404);
        }

        $contentType = $ext === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';

        $filename = basename($path);
        return Storage::disk('local')->download($path, $filename, [
            'Content-Type'           => $contentType,
            'Cache-Control'          => 'no-store, no-cache, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
