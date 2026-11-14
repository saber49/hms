<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Services\DocumentThumbnailer;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill thumbnails for documents that pre-date the thumbnail feature.
 *
 * Walks every active tenant, plus the central tenant_documents table,
 * and renders a thumb for each row whose source file exists. Idempotent:
 * existing thumbs are skipped unless --force is passed.
 *
 *   php artisan documents:generate-thumbs                # all tenants, all modules
 *   php artisan documents:generate-thumbs --tenant=slug  # one tenant only
 *   php artisan documents:generate-thumbs --module=worker
 *   php artisan documents:generate-thumbs --force        # re-render existing thumbs
 */
class GenerateDocumentThumbnails extends Command
{
    protected $signature = 'documents:generate-thumbs
        {--tenant= : Limit to a single tenant slug}
        {--module=all : Which module: tenant|worker|customer|nu|all}
        {--force : Re-render thumbnails even when already present}';

    protected $description = 'Generate thumbnails for existing documents (backfill).';

    public function handle(): int
    {
        $tenantSlug = $this->option('tenant');
        $module     = $this->option('module') ?: 'all';
        $force      = (bool) $this->option('force');

        $totals = ['done' => 0, 'skipped' => 0, 'failed' => 0];

        // 1) Central-DB tenant_documents — does not need a tenant DB switch.
        if (in_array($module, ['all', 'tenant'], true)) {
            $totals = $this->mergeTotals($totals, $this->backfillTenantDocuments($tenantSlug, $force));
        }

        // 2) Tenant-DB modules — must switch the tenant connection per tenant.
        if (in_array($module, ['all', 'worker', 'customer', 'nu'], true)) {
            $tenantsQ = Tenant::where('aktiv', true);
            if ($tenantSlug) $tenantsQ->where('slug', $tenantSlug);

            foreach ($tenantsQ->get() as $tenant) {
                $this->info("Tenant: {$tenant->name} ({$tenant->slug})");
                TenantService::switchToTenant($tenant);

                if (in_array($module, ['all', 'worker'], true)) {
                    $totals = $this->mergeTotals(
                        $totals,
                        $this->backfillTenantTable('mitarbeiter_dokumente', 'dateipfad', 'uploads/workers/', $force)
                    );
                }
                if (in_array($module, ['all', 'customer'], true)) {
                    $totals = $this->mergeTotals(
                        $totals,
                        $this->backfillTenantTable('kunden_dokumente', 'dateipfad', 'uploads/customers/', $force)
                    );
                }
                if (in_array($module, ['all', 'nu'], true)) {
                    $totals = $this->mergeTotals(
                        $totals,
                        $this->backfillTenantTable('nu_dokumente', 'dateipfad', 'uploads/subcontractors/', $force)
                    );
                }
            }

            // Critical: drop the last switched tenant connection so this
            // long-running CLI doesn't leak state into anything else that
            // might run after it.
            TenantService::reset();
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Generated: %d, Skipped: %d, Failed: %d',
            $totals['done'], $totals['skipped'], $totals['failed']
        ));
        return self::SUCCESS;
    }

    private function backfillTenantDocuments(?string $tenantSlug, bool $force): array
    {
        $totals = ['done' => 0, 'skipped' => 0, 'failed' => 0];

        $q = TenantDocument::query();
        if ($tenantSlug) {
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if (!$tenant) return $totals;
            $q->where('tenant_id', $tenant->id);
        }

        foreach ($q->get(['id', 'file_path']) as $doc) {
            if (!$doc->file_path) { $totals['skipped']++; continue; }
            $totals = $this->mergeTotals($totals, $this->renderOne($doc->file_path, $force));
        }

        return $totals;
    }

    /**
     * Backfill one tenant-side document table. Generic over the table
     * name and path column so all three legacy tables share this.
     */
    private function backfillTenantTable(string $table, string $pathColumn, string $expectedPrefix, bool $force): array
    {
        $totals = ['done' => 0, 'skipped' => 0, 'failed' => 0];

        if (!Schema::connection('tenant')->hasTable($table)) {
            return $totals;
        }

        $rows = DB::connection('tenant')->table($table)
            ->select(['id', $pathColumn])
            ->whereNotNull($pathColumn)
            ->get();

        foreach ($rows as $row) {
            $path = $row->{$pathColumn};
            if (!$path || !str_starts_with($path, $expectedPrefix)) {
                $totals['skipped']++;
                continue;
            }
            $totals = $this->mergeTotals($totals, $this->renderOne($path, $force));
        }

        return $totals;
    }

    private function renderOne(string $sourcePath, bool $force): array
    {
        try {
            $thumb = DocumentThumbnailer::generate('public', $sourcePath, $force);
            if ($thumb) {
                $this->line('  ✓ ' . $sourcePath);
                return ['done' => 1, 'skipped' => 0, 'failed' => 0];
            }
            $this->line('  - skip ' . $sourcePath);
            return ['done' => 0, 'skipped' => 1, 'failed' => 0];
        } catch (\Throwable $e) {
            $this->line('  ✗ fail ' . $sourcePath . ' — ' . $e->getMessage());
            return ['done' => 0, 'skipped' => 0, 'failed' => 1];
        }
    }

    private function mergeTotals(array $a, array $b): array
    {
        return [
            'done'    => $a['done']    + $b['done'],
            'skipped' => $a['skipped'] + $b['skipped'],
            'failed'  => $a['failed']  + $b['failed'],
        ];
    }
}
