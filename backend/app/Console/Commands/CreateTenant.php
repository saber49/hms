<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\CentralUser;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create
                            {name : Company name}
                            {email : Admin email}
                            {password : Admin password}
                            {--full-name= : Admin full name}';

    protected $description = 'Create a new tenant with its database and admin user';

    public function handle(): int
    {
        $name = $this->argument('name');
        $email = $this->argument('email');
        $password = $this->argument('password');
        $fullName = $this->option('full-name') ?: $name . ' Admin';

        $slug = Str::slug($name);
        $database = 'hms_tenant_' . $slug;

        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("Tenant with slug '{$slug}' already exists.");
            return 1;
        }

        if (CentralUser::where('email', $email)->exists()) {
            $this->error("User with email '{$email}' already exists.");
            return 1;
        }

        $this->info("Creating tenant '{$name}'...");

        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'database' => $database,
            'aktiv' => true,
        ]);

        $this->info("Running tenant migrations...");
        TenantService::createTenantDatabase($tenant);

        $this->info("Creating tenant admin user...");
        TenantService::seedTenantAdmin($tenant, 'admin', $password, $fullName);

        $centralUser = CentralUser::create([
            'tenant_id' => $tenant->id,
            'name'      => $fullName,   // central_users.name is NOT NULL
            'email'     => $email,
            'password'  => Hash::make($password),
            'full_name' => $fullName,
            'role'      => 'tenant_admin',
            'aktiv'     => true,
        ]);

        $this->info("Tenant created successfully!");
        $this->table(['Field', 'Value'], [
            ['Tenant ID', $tenant->id],
            ['Name', $tenant->name],
            ['Slug', $tenant->slug],
            ['Database', $tenant->database],
            ['Admin Email', $centralUser->email],
            ['Admin Full Name', $centralUser->full_name],
        ]);

        return 0;
    }
}
