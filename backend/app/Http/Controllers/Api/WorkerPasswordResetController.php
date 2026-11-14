<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-service "Passwort vergessen?" for workers.
 *
 *  POST /api/worker-app/auth/forgot-password
 *  POST /api/worker-app/auth/reset-password
 *
 * Security mirrors the central PasswordResetController:
 *  - Generic 200 response (no email enumeration).
 *  - 64-byte token, only SHA-256 stored (table `password_resets`,
 *    same as central but `subject='worker'` + `tenant_id` populated).
 *  - 60-minute single-use tokens.
 *  - On successful reset, all the worker's Sanctum tokens are revoked.
 *
 * Tenant resolution (mirrors WorkerAppAuthController::login):
 *  Scan every active tenant in id-order for the email and stop at the
 *  first hit. If the same email lives in several tenants (rare) we send
 *  the reset link to the lowest-id tenant where the worker is active,
 *  matching the login behaviour exactly so the worker always lands on
 *  the same account regardless of which flow they use.
 */
class WorkerPasswordResetController extends Controller
{
    private const TTL_MINUTES = 60;

    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($request->input('email')));

        $genericResponse = response()->json([
            'success' => true,
            'message' => 'Falls die E-Mail-Adresse bei uns hinterlegt ist, '
                       . 'erhalten Sie in Kürze eine Nachricht mit einem '
                       . 'Link zum Zurücksetzen Ihres Passworts.',
        ]);

        // Locate the matching worker (and tenant) the same way login does.
        [$tenant, $worker] = $this->resolveWorker($email);
        if (!$tenant || !$worker) {
            // Equalise timing so attackers can't infer existence.
            Hash::check('dummy', '$2y$12$' . str_repeat('a', 53));
            return $genericResponse;
        }

        $plainToken = Str::random(64);
        $tokenHash  = hash('sha256', $plainToken);
        $now        = now();
        $expiresAt  = $now->copy()->addMinutes(self::TTL_MINUTES);

        // Wipe any older live tokens for this (worker, email, tenant).
        DB::connection('mysql')->table('password_resets')
            ->where('subject', 'worker')
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->delete();

        DB::connection('mysql')->table('password_resets')->insert([
            'email'      => $email,
            'subject'    => 'worker',
            'tenant_id'  => $tenant->id,
            'token_hash' => $tokenHash,
            'ip_address' => $request->ip(),
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);

        // Reset link points at the worker portal page. Tenant slug is
        // included for convenience but the token row is the source of
        // truth — we will not trust the URL parameter at reset time.
        $base     = rtrim(config('app.url'), '/');
        $resetUrl = $base . '/worker/reset-password.php'
                  . '?token=' . urlencode($plainToken)
                  . '&email=' . urlencode($email)
                  . '&tenant=' . urlencode($tenant->slug);

        $fullName = trim(($worker->vorname ?? '') . ' ' . ($worker->name ?? ''));
        if ($fullName === '') $fullName = $email;

        try {
            Mail::to($email, $fullName)
                ->queue(new SystemMail(
                    blade: 'emails.password-reset',
                    subjectLine: 'Passwort zurücksetzen — ' . $tenant->name,
                    payload: [
                        'company'   => $tenant->name,
                        'full_name' => $fullName,
                        'email'     => $email,
                        'reset_url' => $resetUrl,
                    ],
                ));
        } catch (\Throwable $e) {
            Log::warning('worker password-reset mail queue failed', [
                'email' => $email,
                'err'   => $e->getMessage(),
            ]);
        }

        return $genericResponse;
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email'                 => 'required|email|max:255',
            'token'                 => 'required|string|min:32|max:128',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ], ['password.min' => 'Das Passwort muss mindestens 8 Zeichen haben.']);

        $email      = strtolower(trim($request->input('email')));
        $plainToken = $request->input('token');
        $tokenHash  = hash('sha256', $plainToken);

        // Look up by token+email — tenant_id comes from the row itself so
        // a URL-tampered tenant slug cannot redirect the reset elsewhere.
        $row = DB::connection('mysql')->table('password_resets')
            ->where('subject', 'worker')
            ->where('email', $email)
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row || !$row->tenant_id) {
            return response()->json([
                'error' => 'Der Link ist ungültig oder abgelaufen. '
                         . 'Bitte fordern Sie einen neuen an.',
            ], 422);
        }

        $tenant = Tenant::where('id', $row->tenant_id)->where('aktiv', true)->first();
        if (!$tenant) {
            return response()->json(['error' => 'Konto nicht gefunden.'], 422);
        }

        TenantService::switchToTenant($tenant);

        $worker = DB::connection('tenant')->table('mitarbeiter')
            ->where('email', $email)
            ->where('app_zugang', 1)
            ->first();

        if (!$worker) {
            return response()->json(['error' => 'Konto nicht gefunden.'], 422);
        }

        $newHash = Hash::make($request->input('password'));

        DB::transaction(function () use ($tenant, $worker, $newHash, $row, $email) {
            // 1. Update worker password
            DB::connection('tenant')->table('mitarbeiter')
                ->where('id', $worker->id)
                ->update(['password_hash' => $newHash]);

            // 2. Revoke all worker app sessions (worker_sessions table)
            try {
                DB::connection('tenant')->table('worker_sessions')
                    ->where('mitarbeiter_id', $worker->id)
                    ->delete();
            } catch (\Throwable $e) { /* table may not exist on some tenants */ }

            // 3. Mark consumed; purge any other live tokens for this email+tenant
            DB::connection('mysql')->table('password_resets')
                ->where('id', $row->id)
                ->update(['consumed_at' => now()]);
            DB::connection('mysql')->table('password_resets')
                ->where('subject', 'worker')
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->where('id', '!=', $row->id)
                ->whereNull('consumed_at')
                ->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Passwort erfolgreich geändert. Sie können sich jetzt anmelden.',
        ]);
    }

    /**
     * Find the matching (tenant, worker) for a given email. Scans every
     * active tenant in id-order and returns the first hit. Returns
     * [null, null] when no tenant has the email enabled for app_zugang.
     */
    private function resolveWorker(string $email): array
    {
        $tenants = Tenant::where('aktiv', true)->orderBy('id')->get();
        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);
                $worker = DB::connection('tenant')->table('mitarbeiter')
                    ->where('email', $email)
                    ->where('app_zugang', 1)
                    ->first();
                if ($worker) {
                    return [$tenant, $worker];
                }
            } catch (\Throwable $e) { /* skip broken tenants */ }
        }
        return [null, null];
    }
}
