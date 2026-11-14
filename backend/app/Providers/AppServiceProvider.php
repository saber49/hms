<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Services\TenantService;
use App\Services\Ai\Contracts\AiClientInterface;
use App\Services\Ai\GroqClient;
use App\Services\Ai\VertexAiClient;
use App\Helpers\SchichtartCalculator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerAiClient();
    }

    /**
     * Pick the chat-completions backend based on `AI_PROVIDER` in .env.
     *
     *   AI_PROVIDER=gemini  → Google Vertex AI Gemini (default region:
     *                         europe-west3 / Frankfurt for GDPR)
     *   AI_PROVIDER=groq    → IONOS or any OpenAI-compatible endpoint
     *                         using a static API key (the legacy path)
     *
     * Both controllers + services inject the `AiClientInterface`
     * contract, so switching providers is a one-line .env change.
     *
     * Singleton: each request reuses the same client instance — important
     * for the Vertex client because it caches OAuth tokens in memory
     * between calls within a single request.
     */
    protected function registerAiClient(): void
    {
        $this->app->singleton(AiClientInterface::class, function () {
            $provider = (string) config('ai.provider', 'groq');
            return match ($provider) {
                'gemini', 'vertex' => new VertexAiClient(),
                default            => new GroqClient(),
            };
        });

        // Keep the concrete-class container bindings working for any
        // legacy callers that still type-hint `GroqClient` or
        // `VertexAiClient` directly (e.g. older controllers, tests).
        $this->app->bind(GroqClient::class, function ($app) {
            return $app->make(AiClientInterface::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerOctaneTenantResetHooks();

        // IONOS SMTP aborts a session after a few mails ("451 Mails per session
        // limit exceeded"); the long-running queue worker reuses ONE session, so
        // after a burst every further mail failed. Reconnect every 10 mails.
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, static function () {
            try {
                $t = app('mail.manager')->mailer()->getSymfonyTransport();
                if ($t instanceof \Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) {
                    $t->setRestartThreshold(10, 1);
                }
            } catch (\Throwable $e) {
                // never block a mail because of this tweak
            }
        });

        // Admin API tokens: invalid after 30 days WITHOUT use (sliding — an
        // active user is never logged out, a lost/stolen token dies).
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(
            static fn ($token, bool $isValid) => $isValid
                && ($token->last_used_at ?? $token->created_at) !== null
                && \Illuminate\Support\Carbon::parse($token->last_used_at ?? $token->created_at)->gt(now()->subDays(30))
        );
    }

    /**
     * Octane / FrankenPHP keep PHP workers alive across many requests, so
     * any tenant-scoped state (active DB connection name, cached holiday
     * lists, etc.) MUST be flushed at request boundaries — otherwise
     * Tenant A's data can leak to Tenant B.
     *
     * The Octane events are loaded conditionally so this provider keeps
     * working under classic PHP-FPM where the package is absent.
     */
    protected function registerOctaneTenantResetHooks(): void
    {
        $reset = static function (): void {
            TenantService::reset();
            SchichtartCalculator::clearCache();
        };

        // Belt: clear before a worker picks up a new request.
        if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            Event::listen(\Laravel\Octane\Events\RequestReceived::class, $reset);
        }

        // Suspenders: clear after the response is sent, even on exception.
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            Event::listen(\Laravel\Octane\Events\RequestTerminated::class, $reset);
        }

        // Tasks and ticks run outside the normal HTTP cycle but still
        // share the worker — flush there too.
        if (class_exists(\Laravel\Octane\Events\TaskTerminated::class)) {
            Event::listen(\Laravel\Octane\Events\TaskTerminated::class, $reset);
        }
        if (class_exists(\Laravel\Octane\Events\TickTerminated::class)) {
            Event::listen(\Laravel\Octane\Events\TickTerminated::class, $reset);
        }

        // Worker shutdown — last line of defence.
        if (class_exists(\Laravel\Octane\Events\WorkerStopping::class)) {
            Event::listen(\Laravel\Octane\Events\WorkerStopping::class, $reset);
        }
    }
}
