<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Build the tenant payload that's returned to the frontend on login /
     * check / profile-update. Centralised so login(), check() and any
     * future endpoint stay in sync — any new tenant field is added here
     * once and is immediately available in localStorage on the client.
     */
    public static function buildTenantPayload($tenant, $user = null): array
    {
        $planConfig = $tenant->getPlanConfig();
        return [
            'id'                   => $tenant->id,
            'name'                 => $tenant->name,
            'slug'                 => $tenant->slug,
            'plan'                 => $tenant->plan,
            'plan_name'            => $planConfig['name'] ?? null,
            'is_trial'             => $tenant->plan === 'trial',
            'trial_days_remaining' => $tenant->trialDaysRemaining(),
            'subscription_active'  => $tenant->isSubscriptionActive(),
            'onboarding_completed' => $tenant->onboarding_completed,
            // Contact
            'company_email'        => $tenant->company_email,
            'company_phone'        => $tenant->company_phone,
            'company_address'      => $tenant->company_address,
            'company_city'         => $tenant->company_city,
            'company_zip'          => $tenant->company_zip,
            'company_country'      => $tenant->company_country,
            'website'              => $tenant->website,
            // German legal (used in PDF footer)
            'rechtsform'           => $tenant->rechtsform,
            'geschaeftsfuehrer'    => $tenant->geschaeftsfuehrer,
            'handelsregister_nr'   => $tenant->handelsregister_nr,
            'registergericht'      => $tenant->registergericht,
            'ust_id'               => $tenant->ust_id,
            'steuernummer'         => $tenant->steuernummer,
            // Bank details
            'bank_name'            => $tenant->bank_name,
            'iban'                 => $tenant->iban,
            'bic'                  => $tenant->bic,
            // Logo + document assets (signature/stamp for Bestellung PDFs).
            // Per-user assets override the company-wide default on the
            // tenants row — each account can carry its own signature.
            'logo_path'            => $tenant->logo_path,
            'signature_path'       => ($user && $user->signature_path) ? $user->signature_path : $tenant->signature_path,
            'stamp_path'           => ($user && $user->stamp_path) ? $user->stamp_path : $tenant->stamp_path,
            'signature_default'    => $tenant->signature_path,
            'stamp_default'        => $tenant->stamp_path,
            'signature_own'        => $user->signature_path ?? null,
            'stamp_own'            => $user->stamp_path ?? null,
            // Break tiers (Unternehmensdaten → Pausenregelung) — the
            // Bestelleingang wizard auto-fills MA-Pause from these.
            'pause_rules'          => data_get($tenant->settings, 'pause_rules', []),
            // Industry profile + name of the shift log (Wachbuch/Objektbuch/…).
            'branche'              => \App\Support\TenantProfile::branche($tenant),
            'objektbuch_label'     => \App\Support\TenantProfile::objektbuchLabel($tenant),
            // UI wording of the main industry (Kunde/Klient, Objekt/Haushalt …).
            'terms'                => \App\Support\Branchen::terms($tenant),
            // AÜG module (Überlassungshöchstdauer / Equal Pay) switched on.
            'aueg_aktiv'           => \App\Support\Aueg::enabled($tenant),
        ];
    }

    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        // Accept 'email', 'username', or 'login' as the identifier field
        $identifier = $request->input('email') ?? $request->input('username') ?? $request->input('login');

        if (!$identifier) {
            return response()->json(['error' => 'E-Mail oder Benutzername erforderlich'], 422);
        }

        // Try email first, then name/username field
        $user = CentralUser::where('email', $identifier)->where('aktiv', true)->first()
            ?? CentralUser::where('name', $identifier)->where('aktiv', true)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Ungültiger Benutzername oder Passwort'], 401);
        }

        $user->update(['last_login' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->name,
                'email' => $user->email,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'is_owner' => $user->isOwner(),
                'permissions' => $user->getEffectivePermissions(),
                'email_verified' => $user->isVerified(),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'tenant' => self::buildTenantPayload($user->tenant, $user),
        ]);
    }

    /**
     * Passwordless login into the public demo tenant (landing-page embed).
     *
     * Disabled unless DEMO_TENANT_SLUG is set (services.demo.tenant_slug).
     * Issues a token for a dedicated read-only user (ui_role "viewer") so
     * visitors can browse the seeded demo data but never modify anything.
     */
    public function demoLogin()
    {
        $slug = (string) config('services.demo.tenant_slug');
        if ($slug === '') {
            return response()->json(['error' => 'Demo nicht verfügbar'], 404);
        }

        $tenant = Tenant::where('slug', $slug)->where('aktiv', true)->first();
        if (!$tenant) {
            return response()->json(['error' => 'Demo nicht verfügbar'], 404);
        }

        // Dedicated demo account: random unusable password, viewer preset
        // (read-only), verified so EnsureEmailVerified lets it through.
        $user = CentralUser::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'demo-viewer'],
            [
                'email'             => 'demo-viewer@' . $slug . '.invalid',
                'password'          => Hash::make(Str::random(40)),
                'full_name'         => 'Demo Besucher',
                'role'              => 'user',
                'ui_role'           => 'viewer',
                'is_owner'          => false,
                'aktiv'             => true,
                'email_verified_at' => now(),
            ]
        );

        // Keep the token table tidy: demo tokens older than a day are dead.
        $user->tokens()->where('name', 'demo-token')->where('created_at', '<', now()->subDay())->delete();

        $token = $user->createToken('demo-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'demo'    => true,
            'token'   => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->name,
                'email' => $user->email,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'is_owner' => false,
                // Full permission list so the demo UI shows every module and
                // button. Server-side checks still resolve from the DB record
                // (viewer preset) — every write request is rejected with 403.
                'permissions' => \App\Support\PermissionRegistry::all(),
                'email_verified' => true,
                'must_change_password' => false,
            ],
            'tenant' => self::buildTenantPayload($tenant),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true]);
    }

    public function check(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['authenticated' => false]);
        }

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'username' => $user->name,
                'email' => $user->email,
                'full_name' => $user->full_name,
                'role' => $user->role,
                'is_owner' => $user->isOwner(),
                'permissions' => $user->getEffectivePermissions(),
                'email_verified' => $user->isVerified(),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'tenant' => self::buildTenantPayload($user->tenant, $user),
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        // When the account is flagged for a forced password change (a freshly
        // invited sub-user logging in with a temporary starter password), the
        // old password is the temp password they just authenticated with — we
        // don't require them to retype it, only to choose a new one.
        $forced = (bool) $user->must_change_password;

        $request->validate([
            'old_password' => ($forced ? 'nullable' : 'required') . '|string',
            'new_password' => 'required|string|min:8',
        ], ['new_password.min' => 'Das neue Passwort muss mindestens 8 Zeichen haben.']);

        if (!$forced && !Hash::check($request->old_password, $user->password)) {
            return response()->json(['error' => 'Altes Passwort ist falsch'], 401);
        }

        $newHash = Hash::make($request->new_password);
        // Clear the forced-change flag once a fresh password is stored so the
        // user is no longer bounced to the mandatory change screen.
        $user->update(['password' => $newHash, 'must_change_password' => false]);
        // Log out every OTHER device/session (a stolen token dies with the old password).
        $current = $user->currentAccessToken();
        $user->tokens()->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))->delete();

        // Also sync to tenant.users.password_hash (legacy table) so both systems stay aligned
        try {
            $tenant = $user->tenant;
            if ($tenant) {
                TenantService::switchToTenant($tenant);
                DB::connection('tenant')->table('users')
                    ->where('username', $user->name)
                    ->update(['password_hash' => $newHash]);
            }
        } catch (\Exception $e) {
            \Log::warning('Password sync to tenant.users failed: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }
}
