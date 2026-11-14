<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use App\Models\Tenant;
use App\Services\TenantService;

/**
 * Migrate existing NU portal worker photos from the legacy shared folder
 *   storage/app/public/nu_workers/photos/{hash}.{ext}
 * to per-tenant isolated folders:
 *   storage/app/public/nu_workers/{tenant_slug}/{worker_id}/foto_{timestamp}.{ext}
 *
 * Updates the `nu_portal_mitarbeiter.foto` column in each tenant DB to the new path.
 *
 * Run with: php artisan nu:migrate-worker-photos
 * Add --dry to preview without changes.
 */
class MigrateNuWorkerPhotos extends Command
{
    protected $signature = 'nu:migrate-worker-photos {--dry : Preview only, no changes}';
    protected $description = 'Migrate NU portal worker photos to per-tenant isolated folders';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $publicRoot = storage_path('app/public');
        $legacyDir = $publicRoot . '/nu_workers/photos';

        $this->info('=== NU Portal Worker Photos Migration ===');
        $this->line('Mode: ' . ($dry ? 'DRY-RUN (no changes will be made)' : 'LIVE'));
        $this->line('Legacy folder: ' . $legacyDir);
        $this->newLine();

        $tenants = Tenant::where('aktiv', true)->get();
        $this->line('Found ' . $tenants->count() . ' active tenants');
        $this->newLine();

        $totalMigrated = 0;
        $totalSkipped = 0;
        $totalMissing = 0;

        foreach ($tenants as $tenant) {
            $this->line("--- Tenant: <info>{$tenant->slug}</info> ({$tenant->name}) ---");
            try {
                TenantService::switchToTenant($tenant);
            } catch (\Exception $e) {
                $this->error("  ✗ Cannot connect to tenant DB: " . $e->getMessage());
                continue;
            }

            $workers = DB::connection('tenant')->table('nu_portal_mitarbeiter')
                ->whereNotNull('foto')->where('foto', '!=', '')->get();

            if ($workers->isEmpty()) {
                $this->line('  (no workers with photos)');
                continue;
            }

            foreach ($workers as $w) {
                $oldFoto = $w->foto;
                $oldRel  = ltrim(preg_replace('#^storage/#', '', $oldFoto), '/');
                $oldAbs  = $publicRoot . '/' . $oldRel;

                // Skip if already in the new layout (nu_workers/<slug>/<id>/...)
                $newPrefix = 'nu_workers/' . $tenant->slug . '/' . $w->id . '/';
                if (str_starts_with($oldRel, $newPrefix)) {
                    $this->line("  [skip] worker {$w->id}: already migrated → {$oldRel}");
                    $totalSkipped++;
                    continue;
                }

                if (!File::exists($oldAbs)) {
                    $this->warn("  [missing] worker {$w->id}: file not found → {$oldRel}");
                    $totalMissing++;
                    continue;
                }

                $ext = pathinfo($oldAbs, PATHINFO_EXTENSION) ?: 'jpg';
                $newRel = $newPrefix . 'foto_' . time() . '_' . $w->id . '.' . $ext;
                $newAbs = $publicRoot . '/' . $newRel;
                $newDir = dirname($newAbs);

                $this->line("  [move] worker {$w->id} ({$w->vorname} {$w->name}):");
                $this->line("         FROM: {$oldRel}");
                $this->line("         TO:   {$newRel}");

                if (!$dry) {
                    if (!File::isDirectory($newDir)) {
                        File::makeDirectory($newDir, 0775, true);
                    }
                    if (!File::move($oldAbs, $newAbs)) {
                        $this->error("         ✗ Move failed!");
                        continue;
                    }
                    DB::connection('tenant')->table('nu_portal_mitarbeiter')
                        ->where('id', $w->id)
                        ->update(['foto' => 'storage/' . $newRel]);
                }
                $totalMigrated++;
            }
            $this->newLine();
        }

        $this->info("=== Summary ===");
        $this->line("Migrated: {$totalMigrated}");
        $this->line("Already-migrated (skipped): {$totalSkipped}");
        $this->line("Missing files: {$totalMissing}");

        if ($dry) {
            $this->newLine();
            $this->warn('DRY-RUN — no changes were made. Re-run without --dry to apply.');
        }

        return self::SUCCESS;
    }
}
