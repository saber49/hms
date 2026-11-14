<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Qm\TerminalService;
use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Objekt-Terminal session (QM module). The tenant comes from the token, the
 * Objekt from the terminal row — a terminal can never reach another Objekt.
 *
 * Sets qm_terminal, qm_sitzung, qm_terminal_obj. With the parameter
 * "wachperson" a guard must be signed in (personal PIN): then the same
 * worker_dienst_nr / worker attributes as the worker app are set, so the
 * worker-app QM controllers run unchanged, limited to this Objekt.
 */
class QmTerminalAuth
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $token = $request->bearerToken();
        if (!$token) return response()->json(['error' => 'Nicht angemeldet'], 401);

        $pat = DB::connection('mysql')->table('personal_access_tokens')
            ->where('token', hash('sha256', $token))->where('tokenable_type', TerminalService::TOKEN_TYPE)->first();
        if (!$pat || ($pat->expires_at && now()->gt($pat->expires_at))) {
            return response()->json(['error' => 'Sitzung abgelaufen — bitte neu anmelden', 'abgemeldet' => true], 401);
        }
        $tenantId = (json_decode($pat->abilities, true) ?? [])['tenant_id'] ?? null;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if (!$tenant || !$tenant->aktiv) return response()->json(['error' => 'Firma nicht gefunden oder deaktiviert', 'abgemeldet' => true], 401);

        TenantService::switchToTenant($tenant);
        $request->attributes->set('tenant_id', (int) $tenant->id);

        $db = DB::connection('tenant');
        $s = $db->table('qm_terminal_sitzungen')->where('id', $pat->tokenable_id)->first();
        $t = $s ? $db->table('qm_terminals')->where('id', $s->terminal_id)->first() : null;
        if (!$s || $s->ende || now()->gt($s->laeuft_ab) || !$t || !$t->aktiv || $t->gesperrt_seit) {
            return response()->json(['error' => 'Terminal-Sitzung beendet — bitte neu anmelden', 'abgemeldet' => true], 401);
        }
        $request->attributes->set('qm_terminal', $t);
        $request->attributes->set('qm_sitzung', $s);
        $request->attributes->set('qm_terminal_obj', (string) $t->obj_id);

        if ($s->dienst_nr) {
            $w = $db->table('mitarbeiter')->where('dienst_nr', $s->dienst_nr)->where('aktiv', 1)->first();
            $pin = $db->table('qm_mitarbeiter_pins')->where('dienst_nr', $s->dienst_nr)->first();
            if ($w && $pin && !$pin->gesperrt && !$pin->start_pin) {
                $request->attributes->set('worker_id', $w->id);
                $request->attributes->set('worker_dienst_nr', $w->dienst_nr);
                $request->attributes->set('worker', $w);
            } else {
                $db->table('qm_terminal_sitzungen')->where('id', $s->id)->update(['dienst_nr' => null, 'wachperson_seit' => null]);
            }
        }
        if ($mode === 'wachperson' && !$request->attributes->get('worker_dienst_nr')) {
            return response()->json(['error' => 'Bitte zuerst als Wachperson anmelden.', 'wachperson_noetig' => true], 403);
        }
        return $next($request);
    }
}
