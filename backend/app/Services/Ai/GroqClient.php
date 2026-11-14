<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the Groq/OpenAI-compatible chat-completions API.
 *
 * Why a dedicated class
 * ---------------------
 * Keeping all "talk to the model" plumbing here lets the controller stay
 * focused on the orchestration logic (tool-calling loop, tenant scoping,
 * rate limiting). Swapping providers later (Groq → OpenAI → Anthropic)
 * means changing this one file, not every endpoint.
 *
 * Wire format
 * -----------
 * The Groq endpoint is bit-for-bit compatible with OpenAI's
 * `/v1/chat/completions`, including `tools` and `tool_choice` and
 * `tool_calls` in the assistant message. We use that contract directly.
 */
class GroqClient implements AiClientInterface
{
    /**
     * Issue a chat completion call.
     *
     * @param array $messages  Conversation transcript in the OpenAI format
     *                         (each entry has at minimum `role` + `content`,
     *                         and assistant messages may also carry
     *                         `tool_calls`; tool result messages have
     *                         `role=tool` + `tool_call_id`).
     * @param array $tools     OpenAI tools schema (function definitions);
     *                         empty array means "no tools available".
     *
     * @return array Decoded response (the raw `choices[0]` payload PLUS
     *               `_usage` if the provider returned token counts). On
     *               transport failure throws RuntimeException so the
     *               controller can degrade gracefully.
     */
    /**
     * @param array $messages
     * @param array $tools
     * @param array $options Optional overrides: model, temperature,
     *                       max_tokens, response_format. Each key is
     *                       only applied if non-null/non-empty.
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $apiKey = config('ai.api_key');
        if (!$apiKey) {
            throw new \RuntimeException('AI_API_KEY missing — set it in .env first.');
        }

        $payload = [
            'model'       => $options['model']       ?? config('ai.model'),
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? config('ai.temperature'),
            'max_tokens'  => $options['max_tokens']  ?? config('ai.max_tokens'),
        ];
        // OpenAI-style structured output: pass {"type":"json_object"} or
        // a full JSON-Schema response_format. Groq + IONOS both honour this.
        if (!empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }
        if (!empty($tools)) {
            $payload['tools']       = $tools;
            // `auto` = let the model decide whether to call a tool or
            // reply directly. We never force a specific tool from the
            // server side — the system prompt is enough steering.
            $payload['tool_choice'] = 'auto';
        }

        // We retry ONCE on 429 (rate-limited) after honouring the provider's
        // suggested wait time. Anything beyond that is bubbled up so the
        // controller can show a clean message — retrying repeatedly would
        // just stack the latency and make the UI feel frozen.
        $attempt = 0;
        while (true) {
            $resp = Http::withToken($apiKey)
                ->timeout(config('ai.timeout'))
                ->acceptJson()
                ->asJson()
                ->post(config('ai.endpoint'), $payload);

            if ($resp->successful()) {
                break;
            }

            if ($resp->status() === 429 && $attempt === 0) {
                // Groq returns the wait time in two places — the JSON body's
                // error.message "Please try again in 5.735s" AND/OR the
                // standard "Retry-After" header (seconds). We prefer the
                // header but parse the body as a fallback.
                $wait = (float) ($resp->header('Retry-After') ?? 0);
                if ($wait <= 0) {
                    if (preg_match('/try again in ([0-9.]+)s/', $resp->body(), $m)) {
                        $wait = (float) $m[1];
                    }
                }
                $wait = max(0.5, min(8.0, $wait + 0.3)); // clamp + small jitter
                Log::info('ai.provider.retry_after_429', ['wait' => $wait]);
                usleep((int) ($wait * 1_000_000));
                $attempt++;
                continue;
            }

            // Log enough to debug provider issues, but NEVER the API key.
            Log::warning('ai.provider.error', [
                'status' => $resp->status(),
                'body'   => substr($resp->body(), 0, 800),
            ]);

            // 429 even after retry → throw a marker exception the controller
            // can catch separately to surface a nice user-facing message.
            if ($resp->status() === 429) {
                throw new \RuntimeException('AI_RATE_LIMITED');
            }
            throw new \RuntimeException(
                'AI provider returned HTTP ' . $resp->status()
                . ' — ' . substr($resp->body(), 0, 200)
            );
        }

        $json    = $resp->json();
        $choice  = $json['choices'][0]   ?? null;
        if (!$choice) {
            throw new \RuntimeException('AI provider returned no choices.');
        }

        return [
            'message' => $choice['message']       ?? [],
            'finish'  => $choice['finish_reason'] ?? null,
            '_usage'  => $json['usage']           ?? null,
        ];
    }
}
