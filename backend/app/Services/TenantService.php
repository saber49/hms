<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class TenantService
{
    protected static ?Tenant $currentTenant = null;

    public static function switchToTenant(Tenant $tenant): void
    {
        // Always start from a clean slate so a stale connection from a
        // previous request (Octane / FrankenPHP worker) cannot leak into
        // this request. switchToTenant() is the ONLY supported way to
        // bind the 'tenant' connection.
        static::reset();

        static::$currentTenant = $tenant;

        Config::set('database.connections.tenant.database', $tenant->database);

        DB::purge('tenant');
        DB::reconnect('tenant');

        Schema::connection('tenant')->getConnection()->reconnect();
    }

    /**
     * Tear down any tenant-scoped state. MUST be called at the end of every
     * request when running under a long-lived worker (Octane / FrankenPHP),
     * and before any tenant switch to avoid cross-tenant data leakage.
     */
    public static function reset(): void
    {
        static::$currentTenant = null;

        // Null out the database name so any accidental query on the
        // 'tenant' connection between requests fails loudly instead of
        // silently hitting the previous tenant's database.
        Config::set('database.connections.tenant.database', null);

        try {
            DB::purge('tenant');
        } catch (\Throwable $e) {
            // Connection may not have been resolved yet — safe to ignore.
        }
    }

    public static function current(): ?Tenant
    {
        return static::$currentTenant;
    }

    /**
     * Fail closed when code runs without a fully resolved tenant context.
     * Services that read tenant data must call this before touching the
     * tenant connection so a missing resolver can never fall back to a
     * stale database under Octane.
     */
    public static function assertActive(): Tenant
    {
        $current = static::$currentTenant;
        $configuredDb = Config::get('database.connections.tenant.database');

        if (!$current || $configuredDb !== $current->database) {
            throw new \RuntimeException('Tenant context is not active');
        }

        return $current;
    }

    /**
     * Assert that the active 'tenant' connection points at the expected
     * tenant. Used as a tripwire by EnsureTenantBoundary middleware to
     * detect cross-tenant leaks early (especially under Octane).
     */
    public static function assertCurrentMatches(int $expectedTenantId): void
    {
        $current = static::$currentTenant;

        if (!$current || (int) $current->id !== $expectedTenantId) {
            throw new \RuntimeException(
                'Tenant boundary violation: expected tenant ' . $expectedTenantId
                . ', got ' . ($current?->id ?? 'null')
            );
        }

        $configuredDb = Config::get('database.connections.tenant.database');
        if ($configuredDb !== $current->database) {
            throw new \RuntimeException(
                'Tenant DB mismatch: configured "' . $configuredDb
                . '", expected "' . $current->database . '"'
            );
        }
    }

    public static function createTenantDatabase(Tenant $tenant): void
    {
        $dbName = static::validateDatabaseName($tenant->database);
        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}");

        static::switchToTenant($tenant);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    public static function deleteTenantDatabase(Tenant $tenant): void
    {
        $dbName = static::validateDatabaseName($tenant->database);
        DB::statement("DROP DATABASE IF EXISTS `{$dbName}`");
    }

    /**
     * Validate that a database name contains only safe characters.
     * Prevents SQL injection through crafted tenant/database names.
     */
    private static function validateDatabaseName(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid database name: {$name}");
        }

        if (strlen($name) > 64) {
            throw new \InvalidArgumentException('Database name too long (max 64 chars)');
        }

        return $name;
    }

    public static function seedTenantAdmin(Tenant $tenant, string $username, string $password, string $fullName): void
    {
        static::switchToTenant($tenant);

        DB::connection('tenant')->table('users')->insert([
            'username' => $username,
            'password_hash' => bcrypt($password),
            'full_name' => $fullName,
            'role' => 'admin',
            'aktiv' => 1,
            'created_at' => now(),
        ]);
    }

    /**
     * Provision a new tenant: create DB, run migrations, seed admin, set trial.
     */
    public static function provisionTenant(string $companyName, string $plan = 'trial'): Tenant
    {
        $slug = \Illuminate\Support\Str::slug($companyName);

        // Ensure unique slug
        $originalSlug = $slug;
        $counter = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        $database = 'hms_tenant_' . str_replace('-', '_', $slug);

        $planConfig = config("plans.plans.{$plan}", config('plans.plans.trial'));
        $trialDays = config('plans.trial_days', 14);

        $tenant = Tenant::create([
            'name' => $companyName,
            'slug' => $slug,
            'database' => $database,
            'plan' => $plan,
            'aktiv' => true,
            'trial_ends_at' => $plan === 'trial' ? now()->addDays($trialDays) : null,
            'max_workers' => $planConfig['max_workers'] ?? 10,
            'max_customers' => $planConfig['max_customers'] ?? 20,
            'max_users' => $planConfig['max_users'] ?? 3,
        ]);

        static::createTenantDatabase($tenant);

        return $tenant;
    }

    /**
     * Upgrade or change a tenant's plan.
     */
    public static function changePlan(Tenant $tenant, string $newPlan): Tenant
    {
        $planConfig = config("plans.plans.{$newPlan}");

        if (!$planConfig) {
            throw new \InvalidArgumentException("Unknown plan: {$newPlan}");
        }

        $tenant->update([
            'plan' => $newPlan,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addMonth(),
            'max_workers' => $planConfig['max_workers'],
            'max_customers' => $planConfig['max_customers'],
            'max_users' => $planConfig['max_users'],
        ]);

        return $tenant->fresh();
    }

    /**
     * Get usage stats for a tenant.
     */
    public static function getUsageStats(Tenant $tenant): array
    {
        static::switchToTenant($tenant);

        $db = DB::connection('tenant');

        return [
            'workers' => [
                'current' => $db->table('mitarbeiter')->where('aktiv', 1)->count(),
                'limit' => $tenant->max_workers,
            ],
            'customers' => [
                'current' => $db->table('kunden')->where('aktiv', 1)->count(),
                'limit' => $tenant->max_customers,
            ],
            'users' => [
                'current' => $db->table('users')->where('aktiv', 1)->count(),
                'limit' => $tenant->max_users,
            ],
        ];
    }
}
