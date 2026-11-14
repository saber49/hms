<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generate small JPG previews for documents (PDFs and images) so the UI
 * can render a recognisable thumbnail card instead of a generic "PDF" tile.
 *
 * Strategy:
 *   - For images (jpg/jpeg/png/webp): GD resamples to fit 300x400 keeping
 *     aspect ratio; output is a JPG at quality 75.
 *   - For PDFs: pdftoppm (poppler-utils) renders page 1 to a temporary PNG
 *     scaled to 600px wide; GD then resamples that to 300x400 JPG.
 *
 * Output path: <dir-of-source>/.thumbs/<basename-without-ext>.jpg
 *
 * The class is stateless and safe to reuse across requests (Octane).
 * Failures are logged and return null — the caller MUST treat the
 * thumbnail as best-effort and fall back to an icon when missing.
 */
class DocumentThumbnailer
{
    /** Target box for the thumbnail JPG. */
    public const THUMB_W = 300;
    public const THUMB_H = 400;
    /** JPG encoding quality (0–100). */
    public const THUMB_Q = 75;

    /**
     * Generate (or refresh) the thumbnail for a file living on a Laravel
     * Storage disk and return the relative thumb path on the same disk,
     * or null on failure.
     *
     * @param string $disk          Storage disk name (e.g. 'public')
     * @param string $sourcePath    Path of the source file relative to the disk
     * @param bool   $forceRefresh  Re-render even if the thumb already exists
     */
    public static function generate(string $disk, string $sourcePath, bool $forceRefresh = false): ?string
    {
        $storage = Storage::disk($disk);

        if (!$storage->exists($sourcePath)) {
            return null;
        }

        $thumbPath = self::thumbPathFor($sourcePath);

        // Skip if up-to-date.
        if (!$forceRefresh && $storage->exists($thumbPath)) {
            try {
                $srcMtime = $storage->lastModified($sourcePath);
                $dstMtime = $storage->lastModified($thumbPath);
                if ($dstMtime >= $srcMtime) {
                    return $thumbPath;
                }
            } catch (\Throwable $e) {
                // Fall through and re-render.
            }
        }

        $absoluteSrc = $storage->path($sourcePath);
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        try {
            $jpgBytes = match ($ext) {
                'pdf'                       => self::renderPdfFirstPage($absoluteSrc),
                'jpg', 'jpeg', 'png', 'webp' => self::renderImage($absoluteSrc, $ext),
                default                      => null,
            };
        } catch (\Throwable $e) {
            Log::warning('DocumentThumbnailer: render failed', [
                'source'    => $sourcePath,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$jpgBytes) {
            return null;
        }

        if (!$storage->put($thumbPath, $jpgBytes)) {
            return null;
        }

        return $thumbPath;
    }

    /**
     * Compute the deterministic thumb location for a given source path.
     * Public so callers (controllers, backfill command) can pre-compute
     * the URL or check existence cheaply.
     */
    public static function thumbPathFor(string $sourcePath): string
    {
        $dir  = dirname($sourcePath);
        $base = pathinfo($sourcePath, PATHINFO_FILENAME);
        // Hidden subfolder so file listings stay tidy and the generated
        // JPGs don't collide with user-supplied filenames.
        return ($dir === '.' ? '' : $dir . '/') . '.thumbs/' . $base . '.jpg';
    }

    /**
     * Render the first page of a PDF via pdftoppm and scale-to-fit.
     * Returns the raw JPG bytes or null on failure.
     */
    private static function renderPdfFirstPage(string $absoluteSrc): ?string
    {
        $pdftoppm = self::pdftoppmBinary();
        if (!$pdftoppm) {
            Log::warning('DocumentThumbnailer: pdftoppm not available');
            return null;
        }

        // Render to a temp PNG (pdftoppm appends -1.png to the output prefix).
        $tmpDir    = sys_get_temp_dir();
        $tmpPrefix = $tmpDir . DIRECTORY_SEPARATOR . 'thumb_' . bin2hex(random_bytes(6));

        // -f 1 -l 1 → only page 1; -png → PNG output;
        // -scale-to-x 600 → render width 600px, preserve aspect ratio.
        $cmd = sprintf(
            '%s -f 1 -l 1 -png -scale-to-x 600 -scale-to-y -1 %s %s 2>&1',
            escapeshellcmd($pdftoppm),
            escapeshellarg($absoluteSrc),
            escapeshellarg($tmpPrefix)
        );

        exec($cmd, $output, $rc);
        if ($rc !== 0) {
            Log::warning('DocumentThumbnailer: pdftoppm exit '.$rc, [
                'cmd' => $cmd,
                'out' => $output,
            ]);
            return null;
        }

        // pdftoppm appends -1.png (or -01.png depending on version).
        $candidates = [$tmpPrefix.'-1.png', $tmpPrefix.'-01.png', $tmpPrefix.'.png'];
        $pngPath = null;
        foreach ($candidates as $c) {
            if (is_file($c)) { $pngPath = $c; break; }
        }
        if (!$pngPath) return null;

        try {
            $jpg = self::renderImage($pngPath, 'png');
        } finally {
            @unlink($pngPath);
        }

        return $jpg;
    }

    /**
     * Resample any of the supported raster formats into the target
     * THUMB_W × THUMB_H box, padded with white so every card looks
     * uniform regardless of source aspect ratio.
     */
    private static function renderImage(string $absoluteSrc, string $ext): ?string
    {
        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($absoluteSrc),
            'png'         => @imagecreatefrompng($absoluteSrc),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absoluteSrc) : null,
            default       => null,
        };
        if (!$src) return null;

        $sw = imagesx($src);
        $sh = imagesy($src);

        // Compute the destination rectangle that fits within THUMB_W × THUMB_H
        // while preserving aspect ratio (no cropping).
        $ratio = min(self::THUMB_W / $sw, self::THUMB_H / $sh);
        $dw = max(1, (int) floor($sw * $ratio));
        $dh = max(1, (int) floor($sh * $ratio));
        $dx = (int) floor((self::THUMB_W - $dw) / 2);
        $dy = (int) floor((self::THUMB_H - $dh) / 2);

        $dst = imagecreatetruecolor(self::THUMB_W, self::THUMB_H);
        // White background — matches the surrounding card and matches the
        // visual style of the existing cards in the screenshot.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, self::THUMB_W, self::THUMB_H, $white);
        imagecopyresampled($dst, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        $src = null;   // imagedestroy() is a deprecated no-op since PHP 8.5

        ob_start();
        imagejpeg($dst, null, self::THUMB_Q);
        $bytes = ob_get_clean();
        $dst = null;

        return $bytes ?: null;
    }

    /** Resolve the pdftoppm binary path; cached per-process for Octane. */
    private static ?string $pdftoppm = null;
    private static function pdftoppmBinary(): ?string
    {
        if (self::$pdftoppm !== null) {
            return self::$pdftoppm ?: null;
        }
        foreach (['/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm'] as $candidate) {
            if (is_executable($candidate)) {
                return self::$pdftoppm = $candidate;
            }
        }
        // Last resort: PATH lookup.
        $which = @shell_exec('command -v pdftoppm 2>/dev/null');
        if ($which) {
            $which = trim($which);
            if ($which !== '' && is_executable($which)) {
                return self::$pdftoppm = $which;
            }
        }
        return self::$pdftoppm = '';
    }

    /**
     * Has a thumb already been generated for this source? Cheap stat-only
     * check, used by API endpoints to decide whether to lazy-render.
     */
    public static function thumbExists(string $disk, string $sourcePath): bool
    {
        return Storage::disk($disk)->exists(self::thumbPathFor($sourcePath));
    }

    /**
     * Best-effort delete of the thumb when the source file is removed.
     */
    public static function delete(string $disk, string $sourcePath): void
    {
        try { Storage::disk($disk)->delete(self::thumbPathFor($sourcePath)); }
        catch (\Throwable $e) { /* best-effort cleanup */ }
    }
}
