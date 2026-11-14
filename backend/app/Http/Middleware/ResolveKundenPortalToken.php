<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Qm\PortalService;
use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticates Kundenportal requests (custom bearer token, same scheme as
 * the NU portal). The tenant comes from the token's stored tenant_id, the
 * customer from the token's access row — never from request input — so a
 * customer can only ever read his own data.
 *
 * Sets kunde_id, kunde, kp_zugang and kp_objekte (obj_ids this access may
 * see). While a start password is not changed only me/passwort/logout work.
 */
class ResolveKundenPortalToken
{
    /** Tokens older than this are rejected (re-login required). */
    public const TOKEN_TTL_DAYS = 30;

    private const OHNE_PASSWORTWECHSEL = ['kunden-portal/me', 'kunden-portal/passwort', 'kunden-portal/logout'];

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        $pat = DB::connection('mysql')->table('personal_access_tokens')
            ->where('token', hash('sha256', $token))
            ->where('tokenable_type', 'kunden_portal')
            ->first();
        if (!$pat || ($pat->created_at && now()->subDays(self::TOKEN_TTL_DAYS)->gt($pat->created_at))) {
            return response()->json(['error' => 'Sitzung abgelaufen — bitte neu anmelden'], 401);
        }

        $tenantId = (json_decode($pat->abilities, true) ?? [])['tenant_id'] ?? null;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if (!$tenant || !$tenant->aktiv) {
            return response()->json(['error' => 'Firma nicht gefunden oder deaktiviert'], 403);
        }

        TenantService::switchToTenant($tenant);
        $request->attributes->set('tenant_id', (int) $tenant->id);

        $zugang = Schema::connection('tenant')->hasTable('kunden_portal_zugaenge')
            ? DB::connection('tenant')->table('kunden_portal_zugaenge')->where('id', $pat->tokenable_id)->first()
            : null;
        $kunde = $zugang && $zugang->aktiv && empty($zugang->gesperrt_seit)
            ? DB::connection('tenant')->table('kunden')->where('kunde_id', $zugang->kunde_id)->where('aktiv', 1)->first()
            : null;
        if (!$kunde) {
            return response()->json(['error' => 'Kundenportal nicht aktiv'], 401);
        }

        if (!empty($zugang->passwort_aendern) && !in_array(ltrim($request->path(), '/'), array_map(fn ($p) => 'api/' . $p, self::OHNE_PASSWORTWECHSEL), true)) {
            return response()->json(['error' => 'Bitte zuerst ein eigenes Passwort festlegen.', 'passwort_aendern' => true], 403);
        }

        $request->attributes->set('kunde_id', (string) $kunde->kunde_id);
        $request->attributes->set('kunde', $kunde);
        $request->attributes->set('kp_zugang', $zugang);
        $request->attributes->set('kp_objekte', PortalService::objekte($zugang));

        DB::connection('mysql')->table('personal_access_tokens')->where('id', $pat->id)->update(['last_used_at' => now()]);

        return $next($request);
    }
}
