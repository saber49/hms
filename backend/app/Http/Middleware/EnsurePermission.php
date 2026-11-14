<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on a single permission key from PermissionRegistry.
 *
 * Usage:
 *   Route::middleware('permission:users.manage')->get(...);
 *   Route::middleware('permission:a.view|b.view')->get(...);   // any of
 *
 * - Returns 401 if the request is unauthenticated.
 * - Returns 403 with the missing key if the user lacks the permission.
 * - Owners and super_admins always pass (handled inside hasPermission()).
 *
 * Tenant isolation: this middleware never inspects another tenant's
 * data — it only asks the request's authenticated user whether *they*
 * hold the key.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Nicht angemeldet'], 401);
        }

        // "a|b" = any of the listed keys (e.g. Vertrieb OR Objektleitung).
        $keys = explode('|', $permission);
        if (!method_exists($user, 'hasPermission')
            || !collect($keys)->contains(fn ($k) => $user->hasPermission($k))) {
            return response()->json([
                'error'    => 'Sie haben keine Berechtigung für diese Aktion.',
                'required' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
