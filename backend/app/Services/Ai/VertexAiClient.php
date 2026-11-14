<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClientInterface;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Vertex AI client for the AI assistant.
 *
 * Talks to Vertex AI's **OpenAI-compatible** chat-completions endpoint
 *   https://{region}-aiplatform.googleapis.com/v1beta1/projects/{project}/
 *     locations/{region}/endpoints/openapi/chat/completions
 *
 * This endpoint accepts the same payload shape as OpenAI / Groq / IONOS,
 * including tool calling. The only operational differences are:
 *
 *   1. AUTH — instead of a static API key, we mint short-lived OAuth
 *      access tokens by signing a JWT with the Service Account's
 *      private key (the JSON file at GEMINI_SERVICE_ACCOUNT_PATH).
 *      Tokens are cached for ~55 minutes (they expire after 60).
 *
 *   2. MODEL ID — uses the `google/<model>` prefix
 *      (e.g. `google/gemini-2.5-flash`).
 *
 *   3. THINKING MODE — Gemini 2.5 silently spends a large chunk of the
 *      output token budget on internal "thinking". We disable that by
 *      default via `extra_body.google.thinking_config.thinking_budget=0`
 *      so responses are fast/cheap. Callers can opt back in by passing
 *      `enable_thinking: true` in $options.
 *
 *   4. PDF / IMAGE — Gemini accepts files as a content part
 *      `{type: "input_file", file_data: {mime_type, data}}` (base64).
 *      We expose this via the same `image_url` content shape used by
 *      OpenAI by translating it for the Vertex endpoint.
 *
 * GDPR
 * ----
 * The region is pinned via `GEMINI_REGION` env (default europe-west3 =
 * Frankfurt). All inference happens in-region; no data leaves the EU.
 */
class VertexAiClient implements AiClientInterface
{
    /** Cache TTL for OAuth access tokens (seconds). They actually expire
     *  at 3600s; we refresh a little early to avoid race conditions. */
    public const TOKEN_CACHE_TTL = 3300;

    /** Scope required to call Vertex AI inference APIs. */
    protected const OAUTH_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $project = config('ai.gemini.project_id');
        $region  = config('ai.gemini.region', 'europe-west3');
        $model   = $options['model'] ?? config('ai.gemini.model', 'gemini-2.5-flash');

        if (!$project) {
            throw new \RuntimeException('GEMINI_PROJECT_ID missing — set it in .env first.');
        }

        // Vertex expects `google/<model>` for OpenAI-compat. Accept both
        // bare model name and prefixed form for caller convenience.
        if (!str_contains($model, '/')) {
            $model = 'google/' . $model;
        }

        $endpoint = "https://{$region}-aiplatform.googleapis.com/v1beta1/projects/{$project}"
            . "/locations/{$region}/endpoints/openapi/chat/completions";

        // -- Build payload (OpenAI shape, with Gemini-specific extras) --
        $payload = [
            'model'       => $model,
            'messages'    => $this->normaliseMessages($messages),
            'temperature' => $options['temperature'] ?? config('ai.temperature'),
            'max_tokens'  => $options['max_tokens']  ?? config('ai.max_tokens'),
        ];

        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        if (!empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        // Disable Gemini 2.5 thinking unless explicitly opted in.
        // Thinking burns through max_tokens silently — see header docs.
        if (empty($options['enable_thinking'])) {
            $payload['extra_body'] = [
                'google' => [
                    'thinking_config' => ['thinking_budget' => 0],
                ],
            ];
        }

        // -- Auth --
        $token = $this->getAccessToken();

        // -- HTTP with single retry on 401 (token might have expired
        //    between our cache hit and the actual call) --
        $attempt = 0;
        while (true) {
            $resp = Http::withToken($token)
                ->timeout(config('ai.timeout', 25))
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payload);

            if ($resp->successful()) {
                break;
            }

            if ($resp->status() === 401 && $attempt === 0) {
                Log::info('ai.vertex.token_refresh_on_401');
                $this->invalidateTokenCache();
                $token   = $this->getAccessToken(forceFresh: true);
                $attempt++;
                continue;
            }

            if ($resp->status() === 429 && $attempt === 0) {
                $wait = (float) ($resp->header('Retry-After') ?? 1.5);
                $wait = max(0.5, min(8.0, $wait + 0.3));
                Log::info('ai.vertex.retry_after_429', ['wait' => $wait]);
                usleep((int) ($wait * 1_000_000));
                $attempt++;
                continue;
            }

            // Log enough to debug but never log the access token.
            Log::warning('ai.vertex.error', [
                'status' => $resp->status(),
                'body'   => substr($resp->body(), 0, 800),
                'model'  => $model,
            ]);

            if ($resp->status() === 429) {
                throw new \RuntimeException('AI_RATE_LIMITED');
            }
            throw new \RuntimeException(
                'Vertex AI returned HTTP ' . $resp->status()
                . ' — ' . substr($resp->body(), 0, 200)
            );
        }

        $json   = $resp->json();
        $choice = $json['choices'][0] ?? null;
        if (!$choice) {
            throw new \RuntimeException('Vertex AI returned no choices.');
        }

        // The OpenAI-compat layer sometimes attaches Gemini-only metadata
        // (e.g. thought_signature) under `extra_content`. Strip it so the
        // controller sees a clean OpenAI-shape message.
        $message = $choice['message'] ?? [];
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as &$call) {
                unset($call['extra_content']);
            }
            unset($call);
        }

        return [
            'message' => $message,
            'finish'  => $choice['finish_reason'] ?? null,
            '_usage'  => $json['usage'] ?? null,
        ];
    }

    /**
     * Convert OpenAI-style messages so that any inline image / file
     * parts use Vertex's preferred shape. The OpenAI-compat endpoint
     * accepts most shapes verbatim, but `image_url` with data: URLs
     * needs the URL kept intact (no rewrite). This method exists so we
     * have a single hook to massage messages if Google tightens the
     * accepted shapes later.
     */
    protected function normaliseMessages(array $messages): array
    {
        // No-op for now — the OpenAI-compat endpoint accepts the same
        // multi-part content arrays Groq does (text + image_url). We
        // keep this as a hook for future tweaks.
        return $messages;
    }

    /**
     * Mint (or fetch from cache) an OAuth2 access token for the
     * configured Service Account.
     */
    public function getAccessToken(bool $forceFresh = false): string
    {
        $cacheKey = 'ai:vertex:token:' . md5(config('ai.gemini.service_account_path', ''));

        if (!$forceFresh) {
            $cached = Cache::get($cacheKey);
            if ($cached) return $cached;
        }

        $sa = $this->loadServiceAccount();

        // Build & sign the JWT for the token exchange.
        $now = time();
        $assertion = JWT::encode(
            [
                'iss'   => $sa['client_email'],
                'scope' => self::OAUTH_SCOPE,
                'aud'   => $sa['token_uri'],
                'iat'   => $now,
                'exp'   => $now + 3600,
            ],
            $sa['private_key'],
            'RS256'
        );

        // Exchange the JWT for an access token.
        $resp = Http::asForm()->post($sa['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);

        if (!$resp->successful()) {
            Log::error('ai.vertex.token_exchange_failed', [
                'status' => $resp->status(),
                'body'   => substr($resp->body(), 0, 500),
            ]);
            throw new \RuntimeException(
                'Could not obtain OAuth access token from Google: HTTP '
                . $resp->status() . ' — '
                . substr($resp->body(), 0, 200)
            );
        }

        $token = (string) ($resp->json('access_token') ?? '');
        if ($token === '') {
            throw new \RuntimeException('Google token response missing access_token.');
        }

        // expires_in is typically 3599; we cache slightly less to avoid
        // edge-of-validity 401s.
        $expiresIn = (int) ($resp->json('expires_in') ?? 3600);
        $ttl       = max(60, min(self::TOKEN_CACHE_TTL, $expiresIn - 300));
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /** Clear the cached OAuth token (used on 401 retry). */
    public function invalidateTokenCache(): void
    {
        $cacheKey = 'ai:vertex:token:' . md5(config('ai.gemini.service_account_path', ''));
        Cache::forget($cacheKey);
    }

    /**
     * Read + validate the Service Account JSON.
     * Throws if file missing or required keys absent.
     */
    protected function loadServiceAccount(): array
    {
        $path = (string) config('ai.gemini.service_account_path');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException(
                'GEMINI_SERVICE_ACCOUNT_PATH does not point at a readable file: ' . $path
            );
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read service account file: {$path}");
        }
        $sa = json_decode($raw, true);
        if (!is_array($sa)) {
            throw new \RuntimeException("Service account file is not valid JSON: {$path}");
        }

        foreach (['client_email', 'private_key', 'token_uri'] as $required) {
            if (empty($sa[$required])) {
                throw new \RuntimeException("Service account file is missing `{$required}`.");
            }
        }
        return $sa;
    }
}
