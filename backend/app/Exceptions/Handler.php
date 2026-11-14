<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * Always return JSON 401 for /api/* requests (and JSON-expecting requests),
     * regardless of Accept header. For requests that carry a redirect target
     * (e.g. the Filament panel's auth middleware points at
     * filament.admin.auth.login), honor it with a real redirect — the app
     * itself still has no 'login' named route, so without a target we keep
     * the JSON 401 to avoid RouteNotFoundException.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        $redirectTo = $exception->redirectTo($request);

        if ($redirectTo && ! $request->is('api/*') && ! $request->expectsJson()) {
            return redirect()->guest($redirectTo);
        }

        return response()->json(['error' => 'Unauthenticated.'], 401);
    }
}
