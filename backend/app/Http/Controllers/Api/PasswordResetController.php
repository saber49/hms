<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\CentralUser;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-service "Passwort vergessen?" workflow.
 *
 *  POST /api/auth/forgot-password   public, throttled — accepts an email
 *                                   and (silently) emails a one-time reset
 *                                   link if that email belongs to a known
 *                                   active CentralUser.
 *
 *  POST /api/auth/reset-password    public, throttled — consumes a token
 *                                   from the link, sets the new password,
 *                                   invalidates the token, and revokes all
 *                                   existing Sanctum tokens so any active
 *                                   sessions on other devices are kicked.
 *
 * Security notes:
 *  - We NEVER reveal whether an email exists. Both happy path and "no such
 *    user" return the same 200 message, preventing account enumeration.
 *  - Tokens are 64-byte URL-safe random, but we only store SHA-256 of them.
 *    A leaked DB row cannot reset anyone's password.
 *  - 60-minute expiry; single-use (consumed_at is set on success).
 *  - Successful reset deletes ALL prior reset rows for that email AND
 *    revokes every Sanctum token of that user (defense against an attacker
 *    who already has a stolen token).
 */
class PasswordResetController extends Controller
{
    /** Token lifetime in minutes — long enough for a slow inbox. */
    private const TTL_MINUTES = 60;

    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($request->input('email')));

        // Generic response — same body whether the email exists or not.
        $genericResponse = response()->json([
            'success' => true,
            'message' => 'Falls die E-Mail-Adresse bei uns hinterlegt ist, '
                       . 'erhalten Sie in Kürze eine Nachricht mit einem '
                       . 'Link zum Zurücksetzen Ihres Passworts.',
        ]);

        $user = CentralUser::where('email', $email)
            ->where('aktiv', true)
            ->first();

        if (!$user) {
            // Spend a tiny bit of compute so timing doesn't betray existence.
            Hash::check('dummy', '$2y$12$' . str_repeat('a', 53));
            return $genericResponse;
        }

        // Generate a 64-byte URL-safe token; only its SHA-256 hash is stored.
        $plainToken = Str::random(64);
        $tokenHash  = hash('sha256', $plainToken);
        $now        = now();
        $expiresAt  = $now->copy()->addMinutes(self::TTL_MINUTES);

        // Wipe any older reset rows for this email — only one live link at a time.
        DB::connection('mysql')->table('password_resets')
            ->where('email', $email)
            ->delete();

        DB::connection('mysql')->table('password_resets')->insert([
            'email'      => $email,
            'token_hash' => $tokenHash,
            'ip_address' => $request->ip(),
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);

        $resetUrl = rtrim(config('app.url'), '/')
                  . '/reset-password.php?token=' . urlencode($plainToken)
                  . '&email=' . urlencode($email);

        $companyName = $user->tenant?->name ?? 'Clood-X';

        try {
            Mail::to($user->email, $user->full_name ?: $user->name)
                ->queue(new SystemMail(
                    blade: 'emails.password-reset',
                    subjectLine: 'Passwort zurücksetzen — ' . $companyName,
                    payload: [
                        'company'   => $companyName,
                        'full_name' => $user->full_name ?: $user->name,
                        'email'     => $user->email,
                        'reset_url' => $resetUrl,
                    ],
                ));
        } catch (\Throwable $e) {
            // Don't leak the failure to the client — still return generic ok.
            // Operations can find the trail via the log + the DB row.
            Log::warning('password-reset mail queue failed', [
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
        ]);

        $email      = strtolower(trim($request->input('email')));
        $plainToken = $request->input('token');
        $tokenHash  = hash('sha256', $plainToken);

        $row = DB::connection('mysql')->table('password_resets')
            ->where('email', $email)
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return response()->json([
                'error' => 'Der Link ist ungültig oder abgelaufen. '
                         . 'Bitte fordern Sie einen neuen an.',
            ], 422);
        }

        $user = CentralUser::where('email', $email)
            ->where('aktiv', true)
            ->first();

        if (!$user) {
            // Token row exists but user is now inactive — fail closed.
            return response()->json([
                'error' => 'Konto nicht gefunden oder deaktiviert.',
            ], 422);
        }

        $newHash = Hash::make($request->input('password'));

        DB::transaction(function () use ($user, $newHash, $row) {
            // 1. Update central password
            $user->update(['password' => $newHash]);

            // 2. Mirror to tenant.users (legacy table) so chat/audit stays in sync
            try {
                $tenant = $user->tenant;
                if ($tenant) {
                    TenantService::switchToTenant($tenant);
                    DB::connection('tenant')->table('users')
                        ->where('username', $user->name)
                        ->update(['password_hash' => $newHash]);
                }
            } catch (\Throwable $e) {
                Log::warning('password-reset tenant.users sync failed', [
                    'user_id' => $user->id,
                    'err'     => $e->getMessage(),
                ]);
            }

            // 3. Revoke ALL sessions of this user — anyone with a stolen
            //    token now has to log in again.
            $user->tokens()->delete();

            // 4. Mark this token consumed; purge any other live tokens for
            //    the same email so they can't be used either.
            DB::connection('mysql')->table('password_resets')
                ->where('id', $row->id)
                ->update(['consumed_at' => now()]);
            DB::connection('mysql')->table('password_resets')
                ->where('email', $user->email)
                ->where('id', '!=', $row->id)
                ->whereNull('consumed_at')
                ->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Passwort erfolgreich geändert. Sie können sich jetzt anmelden.',
        ]);
    }
}
