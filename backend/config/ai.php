<?php

/*
|--------------------------------------------------------------------------
| AI Assistant configuration
|--------------------------------------------------------------------------
|
| Centralised settings for the in-app AI assistant (floating chat widget).
| The assistant talks to a chat-completions API that is OpenAI-compatible
| (Groq is the default provider — same wire format as OpenAI but cheaper
| and faster).
|
| All settings are tenant-agnostic — per-tenant isolation is enforced by
| the controller via TenantService + JWT, not here.
|
*/

return [

    /**
     * Which chat-completions provider to use. The container binds
     * `App\Services\Ai\Contracts\AiClientInterface` to the matching
     * concrete client based on this value.
     *
     *   'groq'   → static API key (Groq / IONOS / any OpenAI-compat)
     *   'gemini' → Google Vertex AI (Service Account JSON, EU-hosted)
     */
    'provider' => env('AI_PROVIDER', 'groq'),

    /**
     * Provider chat endpoint. Groq's API mirrors OpenAI's so this is also
     * compatible with `https://api.openai.com/v1` and OpenRouter
     * (`https://openrouter.ai/api/v1`) by simply swapping host + API key.
     * Only used when provider=groq.
     */
    'endpoint' => env('AI_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions'),

    /** Provider API key. Read from .env — NEVER commit. */
    'api_key'  => env('AI_API_KEY'),

    /**
     * Default model. Groq supports `llama-3.3-70b-versatile` (best for
     * tool-use, multilingual) and `llama-3.1-8b-instant` (cheaper).
     * Override per tenant later if needed.
     */
    'model'    => env('AI_MODEL', 'llama-3.3-70b-versatile'),

    /** Sampling. Low temperature = more grounded, less hallucination. */
    'temperature' => (float) env('AI_TEMPERATURE', 0.2),

    /** Hard cap on output tokens per response (safety + cost). */
    'max_tokens'  => (int) env('AI_MAX_TOKENS', 2048),

    /**
     * Max times the assistant is allowed to call a tool in a single turn.
     * Prevents infinite tool-call loops and runaway cost.
     */
    'max_tool_iterations' => (int) env('AI_MAX_TOOL_ITERATIONS', 6),

    /** HTTP timeout (seconds) — Groq is fast so this can stay tight. */
    'timeout' => (int) env('AI_TIMEOUT', 25),

    /**
     * Per-user / per-tenant rate limits. Stored in cache via the throttle
     * helper inside the controller.
     */
    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_PER_MINUTE', 20),
        'per_day'    => (int) env('AI_RATE_PER_DAY',    500),
    ],

    /**
     * Optional dedicated model for image OCR / vision tasks. Falls back
     * to the main `model` when unset. On IONOS the recommended OCR
     * model is `lightonai/LightOnOCR-2-1B`. Set it in .env to enable
     * image-to-text uploads cheaply; otherwise the chat model handles
     * vision (more expensive but works if the model supports images).
     */
    'ocr_model' => env('AI_OCR_MODEL'),

    /**
     * File-upload constraints for the AI assistant's "drop a PDF and
     * register the data" feature. Per-user upload throttle is enforced
     * inside AiUploadController.
     */
    'upload' => [
        'max_mb'     => (int) env('AI_UPLOAD_MAX_MB',     10),
        'per_minute' => (int) env('AI_UPLOAD_PER_MINUTE',  5),
        'per_day'    => (int) env('AI_UPLOAD_PER_DAY',    50),
    ],

    /**
     * Google Vertex AI / Gemini settings. Only consulted when
     * provider=gemini. See VertexAiClient for the auth flow.
     *
     *   project_id            GCP project the Vertex API is enabled in.
     *   region                Inference region. Stick to EU regions for
     *                         GDPR — europe-west3 = Frankfurt 🇩🇪.
     *   model                 Default Gemini model. `gemini-2.5-flash`
     *                         is cheap+fast; `gemini-2.5-pro` is higher
     *                         quality but ~5× more expensive.
     *   service_account_path  Absolute path to the Service Account JSON
     *                         key file. The web user (www-data) MUST
     *                         have read access; chmod 640, owner root,
     *                         group www-data is recommended.
     */
    'gemini' => [
        'project_id'           => env('GEMINI_PROJECT_ID'),
        'region'               => env('GEMINI_REGION', 'europe-west3'),
        'model'                => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'service_account_path' => env('GEMINI_SERVICE_ACCOUNT_PATH', '/var/www/secrets/gcp-vertex.json'),
    ],

];
