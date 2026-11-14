<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Services\TenantService;

/**
 * Middleware to authenticate worker app requests using a simple token.
 * Workers are stored in the mitarbeiter table (tenant DB), not in the central users table.
 * This middleware also resolves the tenant from the token's stored tenant_id.
 */
class ResolveWorkerToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        $tokenHash = hash('sha256', $token);

        // Look up token in central DB personal_access_tokens
        $pat = DB::connection('mysql')->table('personal_access_tokens')
            ->where('token', $tokenHash)
            ->where('tokenable_type', 'worker')
            ->first();

        if (!$pat) {
            return response()->json(['error' => 'Ungültiges Token'], 401);
        }

        // Enforce token expiry. A lost/stolen device loses access after the
        // inactivity window; the expiry is slid forward below on every use.
        if ($pat->expires_at !== null && now()->greaterThan(\Illuminate\Support\Carbon::parse($pat->expires_at))) {
            DB::connection('mysql')->table('personal_access_tokens')->where('id', $pat->id)->delete();
            return response()->json(['error' => 'Sitzung abgelaufen, bitte erneut anmelden'], 401);
        }

        // Resolve tenant from token abilities (stored as JSON with tenant_id)
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

        // Resolve worker from tenant DB. app_zugang is re-checked on EVERY
        // request (not only at login) — revoking a worker's app access must
        // take effect immediately, not after the 30-day sliding token expiry.
        $worker = DB::connection('tenant')->table('mitarbeiter')->where('id', $pat->tokenable_id)->first();
        if (!$worker || !$worker->aktiv || !($worker->app_zugang ?? 0)) {
            return response()->json(['error' => 'Mitarbeiter nicht gefunden oder inaktiv'], 401);
        }

        // Attach worker info to request
        $request->attributes->set('worker_id', $worker->id);
        $request->attributes->set('worker_dienst_nr', $worker->dienst_nr);
        $request->attributes->set('worker', $worker);

        // Touch last_used_at and slide the inactivity expiry forward so an
        // actively used app never forces a re-login, while an idle (lost)
        // device still expires after TOKEN_TTL_DAYS of no use.
        DB::connection('mysql')->table('personal_access_tokens')->where('id', $pat->id)->update([
            'last_used_at' => now(),
            'expires_at'   => now()->addDays(\App\Http\Controllers\Api\WorkerAppAuthController::TOKEN_TTL_DAYS),
        ]);

        return $next($request);
    }
}
