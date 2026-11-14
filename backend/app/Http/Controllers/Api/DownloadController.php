<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Support\FileAccess;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    /** GET /api/files/link?path=&download=0|1 — signed link after tenant + permission check. */
    public function link(Request $request)
    {
        $path = $this->resolvePath($request);
        $path = $path ? $this->sanitizePath($path) : null;
        if (!$path || !FileAccess::allows($request->user(), $path)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }
        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }
        return response()->json(['url' => FileAccess::signedUrl($path, $request->boolean('download'))]);
    }

    public function serve(Request $request)
    {
        $path = $this->resolvePath($request);
        if (!$path) return response()->json(['error' => 'Pfad erforderlich'], 400);

        $path = $this->sanitizePath($path);
        if (!$path) return response()->json(['error' => 'Ungültiger Pfad'], 400);
        // Only branding images / chat photos are public; everything else needs
        // a short-lived signed link from GET /api/files/link (FileAccess).
        if (!FileAccess::isPublic($path) && !$request->hasValidRelativeSignature()) {
            return response()->json(['error' => 'Link ungültig oder abgelaufen'], 403);
        }

        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }

        if (str_ends_with($path, '.html')) {
            $html = Storage::disk('public')->get($path);

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response()->file(Storage::disk('public')->path($path));
    }

    public function download(Request $request)
    {
        $path = $this->resolvePath($request);
        if (!$path) return response()->json(['error' => 'Pfad erforderlich'], 400);

        $path = $this->sanitizePath($path);
        if (!$path) return response()->json(['error' => 'Ungültiger Pfad'], 400);
        // Only branding images / chat photos are public; everything else needs
        // a short-lived signed link from GET /api/files/link (FileAccess).
        if (!FileAccess::isPublic($path) && !$request->hasValidRelativeSignature()) {
            return response()->json(['error' => 'Link ungültig oder abgelaufen'], 403);
        }

        if (!Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'Datei nicht gefunden'], 404);
        }

        return response()->download(Storage::disk('public')->path($path));
    }

    /**
     * Sanitize and validate the file path to prevent directory traversal.
     * Returns null if the path is invalid or escapes the storage root.
     */
    private function sanitizePath(string $path): ?string
    {
        // Decode and normalize
        $path = urldecode($path);

        // Reject null bytes
        if (str_contains($path, "\0")) {
            return null;
        }

        // Normalize slashes and remove any traversal sequences
        $path = str_replace('\\', '/', $path);

        // Resolve the absolute path and ensure it stays within storage
        $storagePath = Storage::disk('public')->path('');
        $fullPath = realpath(Storage::disk('public')->path($path));

        // If realpath returns false, file doesn't exist (Storage::exists handles this)
        // But we still need to validate the *intended* path doesn't escape
        if ($fullPath && !str_starts_with($fullPath, rtrim($storagePath, '/\\'))) {
            return null;
        }

        // Also check unrealized path for traversal patterns
        $normalized = preg_replace('#/+#', '/', $path);
        $segments = explode('/', $normalized);
        $depth = 0;
        foreach ($segments as $segment) {
            if ($segment === '..') {
                $depth--;
            } elseif ($segment !== '.' && $segment !== '') {
                $depth++;
            }
            if ($depth < 0) {
                return null;
            }
        }

        return ltrim($normalized, '/');
    }

    /**
     * Resolve the file path from query params.
     * Supports ?path=... or ?type=worker_message&id=...
     */
    private function resolvePath(Request $request): ?string
    {
        return $request->query('path') ?: null;
    }
}
