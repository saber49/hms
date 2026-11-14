<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'Nicht angemeldet'], 401);
        if ($user->role === 'super_admin') return $next($request);
        $tenant = $user->tenant;
        if (!$tenant) return response()->json(['error' => 'Kein Unternehmen zugeordnet'], 403);
        if (!$tenant->isSubscriptionActive()) {
            return response()->json(['error' => 'Ihr Abonnement ist abgelaufen.', 'code' => 'subscription_expired', 'plan' => $tenant->plan, 'upgrade_url' => '/settings/billing'], 402);
        }
        return $next($request);
    }
}
