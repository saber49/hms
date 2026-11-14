<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard server-side read-only guard for the public demo account.
 *
 * The landing-page embed logs visitors into a shared, passwordless
 * `demo-viewer` user (see AuthController::demoLogin). The viewer role
 * preset already blocks every permission-gated write, but a handful of
 * endpoints are only auth-gated (onboarding, internal chat, own-account
 * password change, notification state, ...). This middleware closes that
 * gap: ANY mutating request (non GET/HEAD/OPTIONS) from the demo user is
 * rejected with 403 before it reaches a controller.
 *
 * The client-side demo sandbox in index.php intercepts and simulates
 * writes in sessionStorage anyway, so legitimate demo UX never hits this
 * — only hand-crafted API calls with a demo token do.
 */
class DenyDemoWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = $request->user();
        $slug = (string) config('services.demo.tenant_slug');

        if (
            $user
            && $slug !== ''
            && $user->name === 'demo-viewer'
            && optional($user->tenant)->slug === $slug
        ) {
            return response()->json([
                'success' => false,
                'demo'    => true,
                'error'   => 'Demo-Modus: Änderungen sind deaktiviert.',
            ], 403);
        }

        return $next($request);
    }
}
