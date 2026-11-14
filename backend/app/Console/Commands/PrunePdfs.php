<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Prune saved PDFs older than --days for each active tenant.
 *
 * Usage:
 *   php artisan pdf:prune                 # dry-run, default 365 days
 *   php artisan pdf:prune --days=180      # delete > 180 days
 *   php artisan pdf:prune --force         # actually delete
 *   php artisan pdf:prune --tenant=acme   # one tenant only
 *
 * Removes:
 *   - Physical files under storage/app/public/pdfs/{slug}/
 *   - bestellung_pdfs rows
 *   - Cascade: pdf_share_links rows (FK ON DELETE CASCADE, plus belt-and-suspenders)
 *
 * Also cleans up expired share links (any age) per tenant.
 */
class PrunePdfs extends Command
{
    protected $signature = 'pdf:prune
                            {--days=365 : Age threshold in days; PDFs older than this are removed}
                            {--tenant= : Limit to a single tenant slug}
                            {--force : Actually delete (otherwise dry-run only)}';

    protected $description = 'Remove saved PDFs older than N days and expired share links';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $force = (bool) $this->option('force');
        $slug  = $this->option('tenant');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('aktiv', true)->get();

        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');
            return 0;
        }

        $cutoff = now()->subDays($days);
        $this->info(sprintf(
            '%s PDFs older than %s (%d days) across %d tenant(s)…',
            $force ? 'Pruning' : '[DRY-RUN] Would prune',
            $cutoff->toDateTimeString(),
            $days,
            $tenants->count(),
        ));

        $totalPdfs = 0;
        $totalBytes = 0;
        $totalExpiredShares = 0;

        foreach ($tenants as $tenant) {
            TenantService::switchToTenant($tenant);

            // Skip tenants that don't have the PDF tables yet
            if (!\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('bestellung_pdfs')) {
                continue;
            }

            $pdfs = DB::connection('tenant')->table('bestellung_pdfs')
                ->where('generated_at', '<', $cutoff)
                ->get(['id', 'file_path', 'file_size', 'name']);

            $tenantBytes = 0;
            foreach ($pdfs as $pdf) {
                $tenantBytes += (int) $pdf->file_size;
                if ($force && $pdf->file_path && Storage::disk('public')->exists($pdf->file_path)) {
                    try { Storage::disk('public')->delete($pdf->file_path); } catch (\Throwable $e) {}
                }
            }

            if ($force && $pdfs->count() > 0) {
                $ids = $pdfs->pluck('id')->all();
                if (\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('pdf_share_links')) {
                    DB::connection('tenant')->table('pdf_share_links')
                        ->whereIn('bestellung_pdf_id', $ids)->delete();
                }
                DB::connection('tenant')->table('bestellung_pdfs')->whereIn('id', $ids)->delete();
            }

            // Always also clean up expired share links (regardless of --days)
            $expiredShares = 0;
            if (\Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('pdf_share_links')) {
                $q = DB::connection('tenant')->table('pdf_share_links')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now());
                $expiredShares = $q->count();
                if ($force) $q->delete();
            }

            $this->line(sprintf(
                '  %-30s pdfs=%-4d bytes=%-12s expired_shares=%d',
                substr($tenant->slug, 0, 30),
                $pdfs->count(),
                $this->humanBytes($tenantBytes),
                $expiredShares,
            ));

            $totalPdfs           += $pdfs->count();
            $totalBytes          += $tenantBytes;
            $totalExpiredShares  += $expiredShares;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d PDF(s), %s freed, %d expired share link(s) %s.',
            $force ? 'Removed' : 'Would remove',
            $totalPdfs,
            $this->humanBytes($totalBytes),
            $totalExpiredShares,
            $force ? 'cleaned' : '(not cleaned — dry-run)',
        ));

        if (!$force) {
            $this->comment('Re-run with --force to actually delete.');
        }

        return 0;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
