<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\TenantService;

/**
 * Tripwire middleware: must run AFTER one of the resolvers
 * (ResolveTenant / ResolveWorkerToken / ResolveNuPortalToken). It verifies
 * that the active 'tenant' connection matches the user/worker/NU's
 * tenant_id and that no stale state from a previous request leaked in.
 *
 * Under PHP-FPM this is mostly a belt-and-suspenders check. Under Octane /
 * FrankenPHP, where workers are long-lived, it is a hard guarantee against
 * cross-tenant data leakage if a future code path forgets to call
 * TenantService::switchToTenant().
 */
class EnsureTenantBoundary
{
    public function handle(Request $request, Closure $next)
    {
        // The resolver middlewares (ResolveTenant / ResolveWorkerToken /
        // ResolveNuPortalToken) all set this from the *token's* tenant_id,
        // i.e. the authoritative source — never from the row in the tenant
        // DB (which could be the wrong DB if there was a leak).
        $expected = $request->attributes->get('tenant_id');
        if ($expected !== null) {
            $expected = (int) $expected;
        }

        $current = TenantService::current();
        if (!$current) {
            Log::warning('TenantBoundary: no tenant resolved', [
                'path' => $request->path(),
            ]);
            return response()->json(['error' => 'Tenant nicht aufgelöst'], 500);
        }

        if ($expected !== null && (int) $current->id !== $expected) {
            Log::critical('TenantBoundary VIOLATION', [
                'path'     => $request->path(),
                'expected' => $expected,
                'current'  => $current->id,
            ]);
            // Fail closed — never let a request leak across tenants.
            TenantService::reset();
            return response()->json(['error' => 'Tenant boundary violation'], 500);
        }

        return $next($request);
    }
}
