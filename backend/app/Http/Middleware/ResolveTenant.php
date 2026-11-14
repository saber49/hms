<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TenantService;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        $tenant = $user->tenant;

        if (!$tenant || !$tenant->aktiv) {
            return response()->json(['error' => 'Firma nicht gefunden oder deaktiviert'], 403);
        }

        TenantService::switchToTenant($tenant);

        // Stash the authoritative tenant id so EnsureTenantBoundary can
        // verify the active 'tenant' DB connection.
        $request->attributes->set('tenant_id', (int) $tenant->id);

        return $next($request);
    }
}
