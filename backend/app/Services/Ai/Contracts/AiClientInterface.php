<?php

namespace App\Services\Ai\Contracts;

/**
 * Common shape for any chat-completions provider we want to plug in
 * behind the AI assistant (Groq, IONOS, Google Vertex AI Gemini, ...).
 *
 * The contract is intentionally **OpenAI-compatible**:
 *   - `$messages` follows the OpenAI Chat Completions message schema
 *     (role, content, optional tool_calls / tool_call_id).
 *   - `$tools` follows the OpenAI "tools" / "function calling" schema
 *     ([{type:"function", function:{name, description, parameters}}, ...]).
 *   - Returned `message` is the raw `choices[0].message` payload, also
 *     OpenAI-shaped.
 *
 * Concrete clients are responsible for translating to/from their
 * provider's native wire format if needed. Vertex AI exposes an
 * OpenAI-compatible endpoint so its implementation is mostly a pass-
 * through with different auth.
 */
interface AiClientInterface
{
    /**
     * Send a chat-completions request and return the model's reply.
     *
     * @param  array $messages   OpenAI-style messages array
     * @param  array $tools      OpenAI-style tools schema (empty = none)
     * @param  array $options    Optional overrides: model, temperature,
     *                           max_tokens, response_format. Each key
     *                           is applied only if non-null/non-empty.
     *
     * @return array {
     *     message: array {
     *         role: string,
     *         content?: string,
     *         tool_calls?: array  // OpenAI-shape tool calls
     *     },
     *     finish: ?string,
     *     _usage: ?array
     * }
     *
     * @throws \RuntimeException on transport / provider error. Callers
     *         catch this and degrade gracefully.
     */
    public function chat(array $messages, array $tools = [], array $options = []): array;
}
