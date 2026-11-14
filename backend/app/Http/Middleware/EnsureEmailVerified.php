<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates protected routes on the authenticated user having a confirmed email
 * address (central_users.email_verified_at IS NOT NULL).
 *
 * Usage: attach to the authenticated route group AFTER auth:sanctum.
 *
 * - Returns 401 if unauthenticated.
 * - Returns 403 with code 'email_not_verified' if the email is unconfirmed,
 *   so the SPA can redirect the user to the verification screen.
 *
 * Scope: only self-registered tenant owners ever reach this in the
 * unverified state — sub-users are auto-verified at creation and all
 * pre-existing users were backfilled to verified. The endpoints required
 * to RECOVER from this state (auth/check, auth/logout, resend-verification,
 * the public verify-email) are intentionally NOT behind this middleware.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        if (method_exists($user, 'isVerified') && !$user->isVerified()) {
            return response()->json([
                'error' => 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse.',
                'code'  => 'email_not_verified',
                'email' => $user->email,
            ], 403);
        }

        return $next($request);
    }
}
