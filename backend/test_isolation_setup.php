<?php
/**
 * Setup script: locate or create test tokens for two distinct tenants.
 * Used by isolation_attack.py to run cross-tenant pressure test.
 *
 * Outputs (to stdout, parseable by python):
 *   TENANT_A_ID=<id>
 *   TENANT_A_NAME=<name>
 *   TENANT_A_TOKEN=<token>
 *   TENANT_B_ID=<id>
 *   ...
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$targetTenantIds = [40, 52];

$results = [];

foreach ($targetTenantIds as $tenantId) {
    $tenant = \App\Models\Tenant::find($tenantId);
    if (!$tenant) {
        fwrite(STDERR, "Tenant $tenantId not found\n");
        exit(1);
    }

    // Find a CentralUser belonging to this tenant
    $user = \App\Models\CentralUser::where('tenant_id', $tenantId)
        ->where('aktiv', true)
        ->first();

    if (!$user) {
        fwrite(STDERR, "No active central_user for tenant $tenantId\n");
        exit(1);
    }

    // Revoke any previous test tokens to keep things clean
    $user->tokens()->where('name', 'isolation-test')->delete();

    $token = $user->createToken('isolation-test')->plainTextToken;

    $results[] = [
        'tenant_id'   => $tenant->id,
        'tenant_name' => $tenant->name,
        'tenant_slug' => $tenant->slug,
        'user_id'     => $user->id,
        'user_name'   => $user->name,
        'token'       => $token,
    ];
}

// Emit machine-readable output
$letters = ['A', 'B', 'C', 'D'];
foreach ($results as $i => $r) {
    $L = $letters[$i];
    echo "TENANT_{$L}_ID={$r['tenant_id']}\n";
    echo "TENANT_{$L}_NAME={$r['tenant_name']}\n";
    echo "TENANT_{$L}_SLUG={$r['tenant_slug']}\n";
    echo "TENANT_{$L}_USER_ID={$r['user_id']}\n";
    echo "TENANT_{$L}_TOKEN={$r['token']}\n";
}
