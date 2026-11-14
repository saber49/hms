<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MigrateTenant extends Command
{
    protected $signature = 'tenant:migrate
                            {--tenant= : Specific tenant slug to migrate}
                            {--fresh : Drop and re-create tenant tables}
                            {--seed : Run tenant seeders}';

    protected $description = 'Run migrations for tenant databases';

    public function handle(): int
    {
        $slug = $this->option('tenant');

        if ($slug) {
            $tenants = Tenant::where('slug', $slug)->get();
            if ($tenants->isEmpty()) {
                $this->error("Tenant '{$slug}' not found.");
                return 1;
            }
        } else {
            $tenants = Tenant::where('aktiv', true)->get();
        }

        foreach ($tenants as $tenant) {
            $this->info("Migrating tenant: {$tenant->name} ({$tenant->database})");

            TenantService::switchToTenant($tenant);

            $params = [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ];

            if ($this->option('fresh')) {
                Artisan::call('migrate:fresh', $params);
            } else {
                Artisan::call('migrate', $params);
            }

            $this->info(Artisan::output());
        }

        $this->info('All tenant migrations complete.');
        return 0;
    }
}
