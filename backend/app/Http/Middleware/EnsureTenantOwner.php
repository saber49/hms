<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Company master data (Unternehmensdaten, Rechtliches, Bank, Firmendokumente,
 * logo, settings) belongs to the Inhaber alone — not even an Administrator
 * with users.manage may read or change it. super_admin keeps platform access.
 */
class EnsureTenantOwner
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || (!$user->isOwner() && $user->role !== 'super_admin')) {
            return response()->json(['error' => 'Nur der Firmen-Inhaber darf die Firmendaten verwalten.'], 403);
        }
        return $next($request);
    }
}
