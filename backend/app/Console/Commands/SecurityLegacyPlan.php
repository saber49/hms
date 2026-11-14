<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LegacySecurityMigration;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SecurityLegacyPlan extends Command
{
    protected $signature = 'security:legacy-plan
                            {--tenant= : Limit to one tenant slug}
                            {--apply : Make access modes explicit and assign unambiguous security service types}
                            {--archive : With --apply: deactivate legacy Hauptbereiche in the catalog (data stays)}';

    protected $description = 'Inventory of non-security Bereiche and reviewed migration to the security-only model (dry run by default)';

    public function handle(): int
    {
        if ($this->option('archive') && !$this->option('apply')) {
            $this->error('--archive requires --apply');
            return 1;
        }
        $central = config('database.connections.mysql.database');
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->where('database', '!=', $central)->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->error('No tenant found');
            return 1;
        }
        $stamp = now()->format('Ymd_His');
        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                $plan = LegacySecurityMigration::plan($tenant);
                $report = ['plan' => $plan];
                if ($this->option('apply')) {
                    $report['aenderungen'] = LegacySecurityMigration::apply($tenant, $plan, (bool) $this->option('archive'));
                    $report['plan_danach'] = LegacySecurityMigration::plan($tenant);
                }
                $path = "security-migration/{$tenant->slug}_{$stamp}" . ($this->option('apply') ? '_apply' : '_plan') . '.json';
                Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $z = $plan['zusammenfassung'];
                $this->info("{$tenant->slug}: {$z['sicherheitsbereiche']} Sicherheitsbereiche, {$z['altbereiche']} Altbereiche ({$z['altbereiche_aktiv']} aktiv), "
                    . "Artikel: {$z['artikel_vorschlag']} Vorschlag / {$z['artikel_pruefen']} prüfen / {$z['artikel_altbereich']} Altbereich, "
                    . "Benutzer: {$z['benutzer_modus_offen']} Modus offen, {$z['benutzer_nur_altbereiche']} nur Altbereiche");
                if (isset($report['aenderungen'])) {
                    $c = $report['aenderungen'];
                    $this->info('  angewendet: ' . count($c['benutzer']) . ' Benutzer, ' . count($c['artikel']) . ' Artikel, ' . count($c['bereiche']) . ' Katalogeinträge archiviert');
                }
                $this->line('  Bericht: ' . Storage::disk('local')->path($path));
            } finally {
                TenantService::reset();
            }
        }
        return 0;
    }
}
