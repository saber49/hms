<?php

namespace App\Support;

use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Access control for files on the `public` disk served by /api/serve and
 * /api/download.
 *
 * Only branding images and random-named chat photos stay public. Every other
 * file needs a short-lived signed link, issued by GET /api/files/link after
 * checking that the file belongs to the caller's tenant (it must be referenced
 * by a row in the tenant DB, or live in the tenant's own folder) and that the
 * user may see that record (permission + BereichScope).
 */
class FileAccess
{
    /**
     * Served without signature (used in <img> tags / PDF rendering, random
     * 40-char names): company logos, signature + stamp images, chat photos.
     * NOT tenants/<slug>/documents/ (company papers have their own endpoints).
     */
    public const PUBLIC_PATTERN = '#^(logos/([^/]+/)?[^/]+'
        . '|chat-fotos/[^/]+/\d{4}/\d{2}/[0-9a-f]{40}\.(jpe?g|png|webp)'
        . '|tenants/[^/]+/(logo|signature|stamp)/[^/]+'
        . '|tenants/[^/]+/users/\d+/(signature|stamp)/[^/]+)$#';

    public const LINK_MINUTES = 10;

    public static function isPublic(string $path): bool
    {
        return (bool) preg_match(self::PUBLIC_PATTERN, $path);
    }

    /** May $user (active tenant) open $path? */
    public static function allows($user, string $path): bool
    {
        if (!$user) return false;
        $tenant = TenantService::current();
        if (!$tenant || (int) $user->tenant_id !== (int) $tenant->id) return false;
        if (self::isPublic($path)) return true;

        $slug = (string) $tenant->slug;
        $seg = explode('/', $path);
        $can = fn (string $perm) => method_exists($user, 'hasPermission') && $user->hasPermission($perm);

        // Files inside the tenant's own folder (photos, saved order PDFs).
        if (str_starts_with($path, 'pdfs/')) return ($seg[1] ?? '') === $slug && $can('auftraege.bestellungen.view');
        if (str_starts_with($path, 'uploads/lager/')) return ($seg[2] ?? '') === $slug && $can('stammdaten.lager.view');
        if (str_starts_with($path, 'uploads/workers/') && ($seg[2] ?? '') === $slug) return $can('stammdaten.mitarbeiter.view');

        $db = DB::connection('tenant');
        $row = fn (string $table, string $col, string $key) => Schema::connection('tenant')->hasTable($table)
            ? $db->table($table)->where($col, $path)->value($key) : null;

        if (str_starts_with($path, 'uploads/workers/')) {
            $nr = $row('mitarbeiter_dokumente', 'dateipfad', 'dienst_nr') ?? $row('mitarbeiter_dokument_anfragen', 'dateipfad', 'dienst_nr');
            return $nr !== null && $can('stammdaten.mitarbeiter.view') && BereichScope::allowsWorker($user, (string) $nr);
        }
        if (str_starts_with($path, 'uploads/customers/')) {
            $k = $row('kunden_dokumente', 'dateipfad', 'kunde_id');
            return $k !== null && $can('stammdaten.kunden.view') && BereichScope::allowsKunde($user, (string) $k);
        }
        if (str_starts_with($path, 'uploads/subcontractors/')) {
            $nu = $row('nu_dokumente', 'dateipfad', 'nu_id');
            return $nu !== null && $can('stammdaten.nu.view') && BereichScope::allowsNu($user, (string) $nu);
        }
        // Worker message attachments (Stundennachweise, documents, photos).
        $nr = $row('mitarbeiter_nachrichten', 'datei_pfad', 'dienst_nr');
        if ($nr !== null) return $can('auftraege.nachrichten.view') && BereichScope::allowsWorker($user, (string) $nr);

        return false;
    }

    /** Short-lived relative signed URL to /api/serve or /api/download. */
    public static function signedUrl(string $path, bool $download = false): string
    {
        return URL::temporarySignedRoute(
            $download ? 'files.download' : 'files.serve',
            now()->addMinutes(self::LINK_MINUTES),
            ['path' => $path],
            false
        );
    }
}
