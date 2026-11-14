<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterTenantRequest;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Mail\SystemMail;
use App\Services\TenantService;
use App\Support\TenantProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    /**
     * Register a new tenant (company) with admin user.
     * POST /api/register
     */
    public function register(RegisterTenantRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenant = null;

        try {
            // 1. Provision tenant (creates DB + runs migrations)
            // Note: CREATE DATABASE is DDL and auto-commits, so we cannot wrap this in a transaction
            $tenant = TenantService::provisionTenant(
                $validated['company_name'],
                $validated['plan'] ?? 'trial'
            );

            // 1b. Industry profile: remembered on the tenant (labels) + its
            // main Bereich in the fresh tenant DB (still the active connection).
            if ($validated['branche'] === TenantProfile::INDUSTRY) {
                $tenant->settings = array_merge(is_array($tenant->settings) ? $tenant->settings : [], ['branche' => TenantProfile::INDUSTRY]);
                $tenant->save();
                TenantService::switchToTenant($tenant);
                TenantProfile::seedBereich(TenantProfile::INDUSTRY);
            }

            // 2. Seed tenant-level admin user
            TenantService::seedTenantAdmin(
                $tenant,
                'admin',
                $validated['password'],
                $validated['full_name']
            );

            // 3. Create central user (for API auth via Sanctum)
            // Double opt-in: the owner starts UNVERIFIED and must confirm their
            // email via the link we mail below before they can use the app
            // (enforced by the EnsureEmailVerified middleware). The first user
            // of a tenant is ALWAYS the owner — this guarantees the
            // owner-uniqueness DB constraint sees a clean state.
            $verificationToken = Str::random(64);
            $centralUser = CentralUser::create([
                'tenant_id' => $tenant->id,
                'name' => Str::before($validated['email'], '@'),
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'full_name' => $validated['full_name'],
                'role' => 'tenant_admin',
                'ui_role' => 'owner',
                'is_owner' => true,
                'aktiv' => true,
                'email_verified_at' => null,
                'verification_token' => $verificationToken,
            ]);

            // 4. Update tenant with company email
            $tenant->update([
                'company_email' => $validated['email'],
                'company_phone' => $validated['phone'] ?? null,
            ]);

            // 4b. Send the confirmation email (best-effort — a queue/SMTP
            // hiccup must never roll back a successful registration; the
            // owner can always trigger a resend from the verify screen).
            $this->sendVerificationEmail($centralUser);

            // 5. Auto-login: generate API token
            $token = $centralUser->createToken('auth-token')->plainTextToken;

            $planConfig = $tenant->getPlanConfig();

            return response()->json([
                'success' => true,
                'message' => 'Registrierung erfolgreich! Willkommen bei Clood-X.',
                'token' => $token,
                'user' => [
                    'id'             => $centralUser->id,
                    'username'       => $centralUser->name,
                    'email'          => $centralUser->email,
                    'full_name'      => $centralUser->full_name,
                    'role'           => $centralUser->role,
                    'is_owner'       => $centralUser->isOwner(),
                    // Frontend reads CURRENT_USER.permissions from localStorage
                    // for nav gating. Without this the new owner sees the
                    // empty-state "Keine Berechtigungen zugewiesen" page even
                    // though the backend would have granted them everything.
                    'permissions'    => $centralUser->getEffectivePermissions(),
                    'email_verified' => $centralUser->isVerified(),
                ],
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'plan' => $tenant->plan,
                    'plan_name' => $planConfig['name'],
                    'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
                    'trial_days_remaining' => $tenant->trialDaysRemaining(),
                    'onboarding_completed' => false,
                ],
                'next_step' => '/onboarding',
            ], 201);

        } catch (\Exception $e) {
            // Clean up tenant DB if it was created
            if ($tenant) {
                try {
                    TenantService::deleteTenantDatabase($tenant);
                    $tenant->delete();
                } catch (\Exception $cleanupError) {
                    // Log but don't throw
                }
            }

            return response()->json([
                'error' => 'Registrierung fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Verify email address.
     * GET /api/verify-email?token=xxx
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string|size:64']);

        $user = CentralUser::where('verification_token', $request->token)->first();

        if (!$user) {
            return response()->json(['error' => 'Ungültiger Verifizierungslink.'], 400);
        }

        if ($user->isVerified()) {
            return response()->json(['message' => 'E-Mail bereits verifiziert.']);
        }

        $user->update([
            'email_verified_at' => now(),
            'verification_token' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'E-Mail erfolgreich verifiziert!',
            'email'   => $user->email,
        ]);
    }

    /**
     * Resend verification email.
     * POST /api/resend-verification
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        if ($user->isVerified()) {
            return response()->json(['message' => 'E-Mail bereits verifiziert.']);
        }

        $newToken = Str::random(64);
        $user->update(['verification_token' => $newToken]);

        $this->sendVerificationEmail($user->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Verifizierungs-E-Mail wurde erneut gesendet.',
        ]);
    }

    /**
     * Queue the "E-Mail bestätigen" confirmation email. NEVER throws — the
     * caller treats delivery as a best-effort side channel (failures are
     * logged so ops can investigate / the user can resend).
     */
    private function sendVerificationEmail(CentralUser $user): void
    {
        try {
            $companyName = $user->tenant?->name ?? 'Clood-X';
            $verifyUrl = rtrim(config('app.url'), '/')
                . '/verify-email.php?token=' . urlencode($user->verification_token)
                . '&email=' . urlencode($user->email);

            Mail::to($user->email, $user->full_name ?: $user->name)->queue(new SystemMail(
                blade: 'emails.verify-email',
                subjectLine: 'E-Mail bestätigen — ' . $companyName,
                payload: [
                    'company'    => $companyName,
                    'full_name'  => $user->full_name ?: $user->name,
                    'email'      => $user->email,
                    'verify_url' => $verifyUrl,
                ],
            ));
        } catch (\Throwable $e) {
            Log::warning('verify-email mail queue failed', [
                'email' => $user->email,
                'err'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get available plans for registration page.
     * GET /api/plans
     */
    public function plans(): JsonResponse
    {
        $plans = config('plans.plans');
        $trialDays = config('plans.trial_days');

        $formatted = [];
        foreach ($plans as $key => $plan) {
            $formatted[] = [
                'id' => $key,
                'name' => $plan['name'],
                'price_monthly' => $plan['price_monthly'],
                'price_yearly' => $plan['price_yearly'],
                'max_workers' => $plan['max_workers'],
                'max_customers' => $plan['max_customers'],
                'max_users' => $plan['max_users'],
                'max_objects' => $plan['max_objects'],
                'features' => $plan['features'],
            ];
        }

        return response()->json([
            'trial_days' => $trialDays,
            'plans' => $formatted,
        ]);
    }

    /**
     * Check if a company name / slug is available.
     * GET /api/check-availability?company_name=xxx
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate(['company_name' => 'required|string|max:200']);

        $slug = Str::slug($request->company_name);
        $exists = Tenant::where('slug', $slug)->exists();

        $emailTaken = false;
        if ($request->filled('email')) {
            $emailTaken = CentralUser::where('email', $request->email)->exists();
        }

        return response()->json([
            'company_available' => !$exists,
            'suggested_slug' => $slug,
            'email_available' => !$emailTaken,
        ]);
    }
}
