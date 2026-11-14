<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class WorkerAppAuthController extends Controller
{
    /**
     * Absolute lifetime of a freshly issued worker token. It is slid
     * forward on every authenticated request (see ResolveWorkerToken),
     * so an actively used app never forces a re-login, while a lost or
     * stolen device loses access after this period of inactivity.
     */
    public const TOKEN_TTL_DAYS = 30;

    /**
     * Worker login — public, no auth required.
     * Authenticates by email + password from the mitarbeiter table
     * of every active tenant. The first tenant where the credentials
     * match (ordered by tenant.id ASC for determinism) wins.
     *
     * NOTE: We intentionally accept the same e-mail existing in multiple
     * tenants — in practice the password is the disambiguator, since two
     * unrelated companies are extremely unlikely to share the exact same
     * worker password. If a true collision ever happens, the worker can
     * still be moved by an admin or given a unique password.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|string|email',
            'password' => 'required|string',
        ]);

        $matchedTenant = null;
        $matchedWorker = null;

        // Iterate active tenants in a stable order and take the first
        // tenant where the password verifies for this e-mail. The tenant of the
        // last successful login (central hint) is tried first, so a normal
        // login touches one tenant DB instead of all of them.
        $tenants = Tenant::where('aktiv', true)->orderBy('id')->get();
        $hint = \App\Support\LoginHint::tenantFor('worker', (string) $request->email);
        if ($hint !== null) {
            $tenants = $tenants->sortBy(fn ($t) => (int) $t->id === $hint ? 0 : 1, SORT_REGULAR, false)->values();
        }

        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);

                $worker = DB::connection('tenant')->table('mitarbeiter')
                    ->where('email', $request->email)
                    ->where('aktiv', 1)
                    ->where('app_zugang', 1)
                    ->first();

                if ($worker && $worker->password_hash && Hash::check($request->password, $worker->password_hash)) {
                    $matchedTenant = $tenant;
                    $matchedWorker = $worker;
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$matchedTenant || !$matchedWorker) {
            return response()->json(['error' => 'Ungültige E-Mail-Adresse oder Passwort'], 401);
        }

        TenantService::switchToTenant($matchedTenant);
        \App\Support\LoginHint::remember('worker', (string) $request->email, (int) $matchedTenant->id);

        // Remember that this worker actually uses the app (first + last login).
        // Drives the green/red "App" badge on the Mitarbeiter card.
        try {
            if (Schema::connection('tenant')->hasColumn('mitarbeiter', 'app_last_login')) {
                DB::connection('tenant')->table('mitarbeiter')
                    ->where('id', $matchedWorker->id)
                    ->update([
                        'app_last_login' => now(),
                        'app_first_login' => DB::raw('COALESCE(app_first_login, NOW())'),
                    ]);
            }
        } catch (\Throwable $e) {
            // Never block a login because of bookkeeping.
        }

        // Create a custom token in the central personal_access_tokens table
        $tokenPlain = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $tokenPlain);

        DB::connection('mysql')->table('personal_access_tokens')->insert([
            'tokenable_type' => 'worker',
            'tokenable_id'   => $matchedWorker->id,
            'name'           => 'worker-app',
            'token'          => $tokenHash,
            'abilities'      => json_encode(['worker-app', 'tenant_id' => $matchedTenant->id]),
            'expires_at'     => now()->addDays(self::TOKEN_TTL_DAYS),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'success' => true,
            'token'   => $tokenPlain,
            'user'    => [
                'id'        => $matchedWorker->id,
                'dienst_nr' => $matchedWorker->dienst_nr,
                'vorname'   => $matchedWorker->vorname,
                'name'      => $matchedWorker->name,
                'bereich'   => $matchedWorker->bereich,
                'foto'      => $matchedWorker->foto,
            ],
        ]);
    }

    /**
     * Change password for authenticated worker.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:8',
        ], ['new_password.min' => 'Das neue Passwort muss mindestens 8 Zeichen haben.']);

        $dienstNr = $request->attributes->get('worker_dienst_nr');
        $current  = DB::connection('tenant')->table('mitarbeiter')->where('dienst_nr', $dienstNr)->value('password_hash');

        if (!Hash::check($request->old_password, $current)) {
            return response()->json(['error' => 'Altes Passwort ist falsch'], 401);
        }

        DB::connection('tenant')->table('mitarbeiter')
            ->where('dienst_nr', $dienstNr)
            ->update(['password_hash' => Hash::make($request->new_password)]);

        // Log out the worker's OTHER devices (current token stays valid).
        $workerId = $request->attributes->get('worker_id');
        if ($workerId) {
            DB::connection('mysql')->table('personal_access_tokens')
                ->where('tokenable_type', 'worker')->where('tokenable_id', $workerId)
                // mitarbeiter.id is only unique per tenant
                ->whereRaw("JSON_EXTRACT(abilities, '$.tenant_id') = ?", [(int) $request->attributes->get('tenant_id')])
                ->where('token', '!=', hash('sha256', (string) $request->bearerToken()))
                ->delete();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Logout — revoke the presented worker token server-side so it can
     * never be reused, even if a copy lingers on the device.
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            $tokenHash = hash('sha256', $token);
            DB::connection('mysql')->table('personal_access_tokens')
                ->where('token', $tokenHash)
                ->where('tokenable_type', 'worker')
                ->delete();
        }

        return response()->json(['success' => true]);
    }
}
