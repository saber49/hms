<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Services\TenantService;

/**
 * Middleware to authenticate NU portal requests using a custom token.
 * NU users are stored in the nachunternehmer table (tenant DB).
 * This middleware also resolves the tenant from the token's stored tenant_id.
 */
class ResolveNuPortalToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        $tokenHash = hash('sha256', $token);

        $pat = DB::connection('mysql')->table('personal_access_tokens')
            ->where('token', $tokenHash)
            ->where('tokenable_type', 'nu_portal')
            ->first();

        // 30 days without use → expired (sliding, like the admin tokens).
        $lastUse = $pat->last_used_at ?? $pat->created_at;
        if (!$pat || !$lastUse || now()->subDays(30)->gt(\Illuminate\Support\Carbon::parse($lastUse))) {
            if ($pat) DB::connection('mysql')->table('personal_access_tokens')->where('id', $pat->id)->delete();
            return response()->json(['error' => 'Ungültiges Token'], 401);
        }

        $abilities = json_decode($pat->abilities, true) ?? [];
        $tenantId = $abilities['tenant_id'] ?? null;

        if (!$tenantId) {
            return response()->json(['error' => 'Tenant nicht zugeordnet'], 401);
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant || !$tenant->aktiv) {
            return response()->json(['error' => 'Firma nicht gefunden oder deaktiviert'], 403);
        }

        TenantService::switchToTenant($tenant);

        // Stash the authoritative tenant id (from the token, not the row)
        // so EnsureTenantBoundary can verify the active connection.
        $request->attributes->set('tenant_id', (int) $tenant->id);

        // Resolve NU from tenant DB
        $nu = DB::connection('tenant')->table('nachunternehmer')->where('id', $pat->tokenable_id)->first();
        if (!$nu || !$nu->portal_aktiv) {
            return response()->json(['error' => 'Nachunternehmer nicht gefunden oder Portal deaktiviert'], 401);
        }

        // Attach NU info to request
        $request->attributes->set('nu_id', $nu->nu_id);
        $request->attributes->set('nu', $nu);

        // Touch last_used_at
        DB::connection('mysql')->table('personal_access_tokens')->where('id', $pat->id)->update(['last_used_at' => now()]);

        return $next($request);
    }
}
