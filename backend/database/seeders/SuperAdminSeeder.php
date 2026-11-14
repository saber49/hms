<?php

namespace Database\Seeders;

use App\Models\CentralUser;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $systemTenant = Tenant::firstOrCreate(
            ['slug' => 'clood-x-system'],
            [
                'name' => 'Clood-X System',
                'database' => 'hms_central',
                'plan' => 'enterprise',
                'aktiv' => true,
                'onboarding_completed' => true,
                'max_workers' => -1,
                'max_customers' => -1,
                'max_users' => -1,
            ]
        );

        CentralUser::firstOrCreate(
            ['email' => 'admin@clood-x.com'],
            [
                'tenant_id' => $systemTenant->id,
                'password' => Hash::make('Clood-X2024!'),
                'full_name' => 'Super Admin',
                'name' => 'Super Admin',
                'role' => 'super_admin',
                'aktiv' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Super admin created: admin@clood-x.com');
        $this->command->warn('Default password: Clood-X2024! -- CHANGE THIS IMMEDIATELY!');
    }
}
