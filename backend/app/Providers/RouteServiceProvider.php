<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // API rate limit:
        //  - Authenticated users: 600 req/min. A single dashboard page (e.g.
        //    "Auswertung & Controlling") fans out ~10 parallel requests and
        //    users routinely hit F5 several times in quick succession, so the
        //    Laravel default of 60/min was producing HTTP 429 floods that
        //    surfaced in the UI as "KPIs konnten nicht geladen werden".
        //  - Anonymous / IP-only callers: 60 req/min (kept as a bot guard).
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            if ($user) {
                return Limit::perMinute(600)->by('user:' . $user->id);
            }
            return Limit::perMinute(60)->by('ip:' . $request->ip());
        });

        // CRM import (Phase 2): own bucket — an anonymous throttle:N,1 would share
        // the "throttle:<user>" key with the whole /crm group and lock out writes.
        RateLimiter::for('crm-import', function (Request $request) {
            return Limit::perMinute(15)->by('crm-import:' . ($request->user()?->id ?: $request->ip()));
        });

        // QM module: Objekt-Terminal login (public, per IP), PIN entry (per
        // terminal session / worker token), Wachbuch writes incl. offline
        // catch-up bursts. Named buckets — never a bare throttle:N.
        RateLimiter::for('qm-terminal', fn (Request $request) => Limit::perMinute(10)->by('qm-terminal:' . $request->ip()));
        RateLimiter::for('qm-pin', fn (Request $request) => Limit::perMinute(10)->by('qm-pin:' . hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('qm-worker', fn (Request $request) => Limit::perMinute(120)->by('qm-worker:' . hash('sha256', (string) $request->bearerToken())));

        // Public CRM webform (A2): hard IP limit — the endpoint is unauthenticated.
        RateLimiter::for('crm-form', function (Request $request) {
            return Limit::perMinute(10)->by('crm-form:' . $request->ip());
        });

        // Login: per account AND per IP. The account key is built from EVERY
        // identifier field the login endpoints accept (email / username /
        // login) — an attacker can no longer add a random `login` field to
        // get a fresh bucket for each password guess. Per endpoint, so the
        // shared app login (worker → admin → customer) does not trip itself.
        RateLimiter::for('login', function (Request $request) {
            $ids = array_filter(array_map(
                fn ($k) => is_scalar($v = $request->input($k)) ? mb_strtolower(trim((string) $v)) : '',
                ['email', 'username', 'login']
            ));
            $limits = [Limit::perMinute(30)->by('login-ip:' . $request->ip())];
            foreach (array_unique($ids) as $id) {
                $limits[] = Limit::perMinute(5)->by('login-id:' . $request->path() . ':' . $id);
            }
            return $limits;
        });

        // Password-reset endpoints: strict caps to prevent
        //   (a) flooding a user's inbox by repeatedly requesting links, and
        //   (b) brute-forcing the 64-byte token over /reset-password.
        // We rate-limit by EMAIL (so an attacker can't lock out a victim
        // from a different IP than theirs) AND by IP (so one bot can't
        // sweep many emails).
        RateLimiter::for('password-reset', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));
            return [
                Limit::perHour(5)->by('pwreset-email:' . $email),
                Limit::perHour(20)->by('pwreset-ip:' . $request->ip()),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
