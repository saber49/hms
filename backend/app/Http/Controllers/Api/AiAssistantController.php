<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTools;
use App\Services\Ai\AiToolPermissions;
use App\Services\Ai\Contracts\AiClientInterface;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\WriteTools;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * In-app AI assistant — chat endpoint.
 *
 * Flow per turn:
 *   1. Frontend POSTs the user message + last few turns of history.
 *   2. We prepend a tenant-aware system prompt and call the provider
 *      with the read-only `AiTools` schemas.
 *   3. If the model returns a `tool_calls` instead of a final answer,
 *      we execute the tools locally (still bound to the caller's
 *      tenant DB connection by middleware) and feed the results back
 *      to the model. We loop up to `max_tool_iterations` times.
 *   4. The final assistant message is returned to the frontend.
 *
 * Tenant isolation is enforced at TWO layers:
 *   • The route is mounted inside `auth:sanctum + ResolveTenant + tenant.boundary`,
 *     so the `tenant` DB connection is already pinned to the caller's
 *     company by the time we enter this controller.
 *   • The AiTools class NEVER accepts a tenant_id parameter — it just
 *     uses whatever connection is currently active. The LLM cannot
 *     break out of that scope no matter what it asks for.
 */
class AiAssistantController extends Controller
{
    public function chat(
        Request $request,
        AiClientInterface $client,
        AiTools $tools,
        WriteTools $writer,
        PendingActionService $pending
    ) {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $tenant = TenantService::current();
        if (!$tenant) {
            return response()->json(['error' => 'tenant_not_resolved'], 400);
        }

        // ----- Rate limiting (per user + per tenant) ---------------
        // We keep this in cache (atomic increment) rather than DB to
        // stay sub-millisecond and tolerate Redis OR file cache.
        $rateKey = "ai:rate:{$tenant->id}:{$user->id}:" . now()->format('YmdHi');
        $count   = Cache::increment($rateKey);
        if ($count === 1) Cache::put($rateKey, 1, 65); // ~1 min TTL
        if ($count > config('ai.rate_limit.per_minute')) {
            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Bitte warte einen Moment bevor du die nächste Frage stellst.',
            ], 429);
        }

        // ----- Validate input --------------------------------------
        $userMsg = trim((string) $request->input('message', ''));
        $attachments = $this->validateAttachments($request->input('attachments', []));
        if ($attachments instanceof \Illuminate\Http\JsonResponse) {
            return $attachments; // validation already returned an error response
        }
        // Allow empty text only if at least one attachment is present —
        // Gemini still needs *something* to answer about, but the chip
        // alone counts as the prompt ("Hier ist eine Datei.").
        if ($userMsg === '' && empty($attachments)) {
            return response()->json(['error' => 'message_empty'], 422);
        }
        if (mb_strlen($userMsg) > 2000) {
            return response()->json(['error' => 'message_too_long'], 422);
        }
        // Trim history to last 8 turns to bound token cost
        $history = collect($request->input('history', []))
            ->filter(fn($m) => isset($m['role'], $m['content'])
                && in_array($m['role'], ['user','assistant'], true)
                && is_string($m['content']))
            ->map(fn($m) => ['role' => $m['role'], 'content' => (string) $m['content']])
            ->take(-16) // last 8 user + 8 assistant turns
            ->values()
            ->all();

        // ----- Build the conversation ------------------------------
        // When attachments are present we split them by type:
        //   - images: passed as image_url (multimodal vision channel)
        //   - PDFs:   BOTH paths — pdftotext extract gives the LLM the
        //             *complete* text of every page (visual channels
        //             often quietly drop trailing pages on long PDFs,
        //             which is exactly the bug we hit on the 31.05
        //             shift list). The image_url stays so the model
        //             can still inspect layout / colour / signatures.
        //   - CSV / plain text / spreadsheets: extracted server-side
        //     and inlined as plain text — vision is wasteful here.
        $userTurn = ['role' => 'user', 'content' => ($userMsg !== '' ? $userMsg : 'Hier ist eine Datei.')];
        if (!empty($attachments)) {
            $textParts   = [];
            $imageParts  = [];
            foreach ($attachments as $a) {
                $mime = $a['mime'] ?? '';
                if (str_starts_with($mime, 'image/')) {
                    $imageParts[] = ['type' => 'image_url', 'image_url' => ['url' => $a['dataUrl']]];
                    continue;
                }
                if ($mime === 'application/pdf') {
                    // Always send the PDF as image_url so vision is available …
                    $imageParts[] = ['type' => 'image_url', 'image_url' => ['url' => $a['dataUrl']]];
                    // … but ALSO extract the text from every page so the
                    // model has a deterministic, page-complete copy of
                    // the contents. Required for tables that span the
                    // tail of a multi-page PDF.
                    try {
                        $extracted = $this->extractInlineAttachment($a);
                        if ($extracted !== '') {
                            $textParts[] = "ANHANG \"" . ($a['name'] ?? 'dokument.pdf') . "\" (alle Seiten als Text extrahiert):\n" . $extracted;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('ai.chat.pdf_text_extract_failed', [
                            'name'  => $a['name'] ?? '',
                            'error' => $e->getMessage(),
                        ]);
                        // Vision still has the file — silent fallback.
                    }
                    continue;
                }
                // Inline-extract CSV / plain text. We base64-decode the
                // payload, hand it to FileExtractorService through a
                // temp file so it can normalise CSV → markdown table.
                try {
                    $extracted = $this->extractInlineAttachment($a);
                    $textParts[] = "ANHANG \"" . ($a['name'] ?? 'datei') . "\":\n" . $extracted;
                } catch (\Throwable $e) {
                    Log::warning('ai.chat.attachment_extract_failed', [
                        'name'  => $a['name'] ?? '',
                        'mime'  => $mime,
                        'error' => $e->getMessage(),
                    ]);
                    $textParts[] = "ANHANG \"" . ($a['name'] ?? 'datei') . "\": [konnte nicht gelesen werden — " . $e->getMessage() . "]";
                }
            }

            $leadingText = $userMsg !== '' ? $userMsg : 'Hier ist eine Datei. Bitte interpretiere ihren Inhalt.';
            if (!empty($textParts)) {
                $leadingText .= "\n\n" . implode("\n\n", $textParts);
            }

            $userTurn = empty($imageParts)
                ? ['role' => 'user', 'content' => $leadingText]
                : ['role' => 'user', 'content' => array_merge(
                    [['type' => 'text', 'text' => $leadingText]],
                    $imageParts,
                )];
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($tenant, $user)]],
            $history,
            [$userTurn],
        );

        // Filter the tool list by the caller's permissions. Read tools
        // are always present; write tools (create_*, update_*) appear
        // only if the user holds the matching PermissionRegistry key.
        $schemas = $tools->schemasForUser($user);

        // ----- Tool-calling loop -----------------------------------
        $maxIter = (int) config('ai.max_tool_iterations');
        $usage   = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $pendingActions = []; // surface write proposals to the frontend

        for ($i = 0; $i < $maxIter; $i++) {
            try {
                $resp = $client->chat($messages, $schemas);
            } catch (\Throwable $e) {
                Log::warning('ai.chat.provider_error', [
                    'tenant'  => $tenant->slug,
                    'user'    => $user->id,
                    'error'   => $e->getMessage(),
                ]);
                // Distinguish rate-limiting from generic failures so the
                // user sees actionable feedback instead of "service down".
                if ($e->getMessage() === 'AI_RATE_LIMITED') {
                    return response()->json([
                        'error' => 'ai_rate_limited',
                        'message' => 'Der KI-Dienst ist gerade überlastet. Bitte warte ca. 10 Sekunden und stelle deine Frage erneut.',
                    ], 429);
                }
                return response()->json([
                    'error' => 'ai_provider_error',
                    'message' => 'Der KI-Dienst ist gerade nicht erreichbar. Bitte versuche es in einer Minute erneut.',
                ], 503);
            }

            if (!empty($resp['_usage'])) {
                $usage['prompt_tokens']     += (int) ($resp['_usage']['prompt_tokens']     ?? 0);
                $usage['completion_tokens'] += (int) ($resp['_usage']['completion_tokens'] ?? 0);
            }

            $assistantMsg = $resp['message'];
            $toolCalls    = $assistantMsg['tool_calls'] ?? [];

            // Final answer (no more tools requested)
            if (empty($toolCalls)) {
                return response()->json([
                    'reply'    => $assistantMsg['content'] ?? '',
                    'usage'    => $usage,
                    'tool_calls_made' => $i,
                    'pending_actions' => $pendingActions,
                ]);
            }

            // Otherwise: append the assistant's tool-call message and execute
            // each requested tool, then loop.
            $messages[] = $assistantMsg;

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = [];
                $raw  = $call['function']['arguments'] ?? '{}';
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) $args = $decoded;
                }

                // The LLM occasionally echoes tool names in
                // PascalCase ("BulkCreateEinsaetzeRows") or with stray
                // suffixes ("…Rows"). Map back to the snake_case name
                // we actually registered before we try to dispatch,
                // otherwise the user sees "Funktion nicht verfügbar"
                // for a tool that absolutely does exist.
                $name = $this->normaliseToolName($name);

                // bulk_create_einsaetze expects { rows: [ … ] } but the
                // LLM sometimes flattens a single row to the top level.
                // Treat that as a one-row bulk so the user still gets a
                // preview dialog instead of "rows ist Pflicht".
                if ($name === 'bulk_create_einsaetze'
                    && !isset($args['rows'])
                    && isset($args['bestell_id'], $args['datum'])) {
                    $args = ['rows' => [$args]];
                }

                // create_bestelleingang gets the same treatment for `tage`.
                // The LLM has been observed (logs 2026-05-25 23:47) to
                // call "CreateBestelleingangTage" once per day with flat
                // args {datum, zeit_von, zeit_bis, beschreibung} — it
                // couldn't compose the nested tage[] array, so it tried
                // to do it one row at a time. We catch BOTH shapes:
                //  a) flat args missing the parent context → return a
                //     short, structured error that tells the LLM exactly
                //     what shape we want (it will retry on next turn).
                //  b) single-tag args WITH parent context (kunden_id,
                //     obj_id, monat, jahr, datum) → auto-wrap into a
                //     one-tag tage[] array, same trick as bulk above.
                if ($name === 'create_bestelleingang' && !isset($args['tage'])) {
                    $hasContext = isset($args['kunden_id'], $args['obj_id'], $args['monat'], $args['jahr']);
                    if ($hasContext && isset($args['datum'])) {
                        // single-tag flat call → wrap
                        $args['tage'] = [[
                            'datum'         => $args['datum']         ?? null,
                            'zeit_von'      => $args['zeit_von']      ?? null,
                            'zeit_bis'      => $args['zeit_bis']      ?? null,
                            'soll_std'      => $args['soll_std']      ?? null,
                            'pause_minuten' => $args['pause_minuten'] ?? null,
                            'menge'         => $args['menge']         ?? null,
                            'beschreibung'  => $args['beschreibung']  ?? null,
                            'bemerkung'     => $args['bemerkung']     ?? null,
                        ]];
                        unset($args['datum'], $args['pause_minuten'], $args['menge']);
                    } else {
                        // The LLM is trying day-by-day without the header
                        // context. Bounce it back with a precise schema
                        // hint so the next turn produces a single call
                        // containing every day in tage[].
                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $call['id'] ?? '',
                            'name'         => $name,
                            'content'      => json_encode([
                                'error'   => 'schema_violation',
                                'message' => 'Rufe `create_bestelleingang` GENAU EINMAL auf und packe ALLE Tage in das `tage[]`-Array. Tag-für-Tag-Aufrufe sind NICHT unterstützt. Pflicht: kunden_id, obj_id, monat, jahr, tage[].',
                                'beispiel' => [
                                    'kunden_id' => 'K028',
                                    'obj_id'    => 'O006',
                                    'monat'     => 5,
                                    'jahr'      => 2026,
                                    'tage'      => [
                                        ['datum' => '2026-05-29', 'zeit_von' => '08:00', 'zeit_bis' => '18:00', 'beschreibung' => 'Kontrolle Tor', 'menge' => 1],
                                        ['datum' => '2026-05-30', 'zeit_von' => '07:00', 'zeit_bis' => '18:00', 'beschreibung' => 'Übergänge',    'menge' => 2],
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                        ];
                        Log::info('ai.tool.schema_hint', [
                            'tenant' => $tenant->slug, 'user' => $user->id,
                            'tool'   => $name, 'args' => $args,
                        ]);
                        continue;
                    }
                }

                Log::info('ai.tool.call', [
                    'tenant' => $tenant->slug,
                    'user'   => $user->id,
                    'tool'   => $name,
                    'args'   => $args,
                ]);

                // ── WRITE TOOL: do NOT execute. Build a preview, register
                //    a pending action, and hand the LLM back a synthetic
                //    result that asks it to ask the user for confirmation.
                if (AiToolPermissions::isWrite($name)) {
                    // Defense-in-depth: re-check permission server side
                    // (the schema filter already hid it, but a malicious
                    // history could still smuggle the call name through).
                    $perm = AiToolPermissions::permissionFor($name);
                    if ($perm && (!method_exists($user, 'hasPermission') || !$user->hasPermission($perm))) {
                        $result = [
                            'error'    => 'forbidden',
                            'message'  => "Sie haben keine Berechtigung, '{$name}' aufzurufen.",
                            'required' => $perm,
                        ];
                    } else {
                        try {
                            $preview   = $writer->preview($name, $args);
                            $actionId  = $pending->register(
                                $name,
                                $args,
                                $preview,
                                (int) $user->id,
                                trim(($user->name ?? '') . ' ' . ($user->email ?? ''))
                            );
                            $result = [
                                'requires_confirmation' => true,
                                'action_id'             => $actionId,
                                'tool_name'             => $name,
                                'preview'               => $preview,
                                'expires_in_minutes'    => PendingActionService::PENDING_TTL_MINUTES,
                                'instructions'         => 'Frage den Nutzer auf Deutsch um Bestätigung. Beschreibe kurz was passieren wird (1-2 Sätze) und nenne die Action-ID NICHT — die UI zeigt einen Bestätigungs-Dialog automatisch.',
                            ];
                            // Track for the final response so the
                            // frontend knows which modal(s) to render.
                            $pendingActions[] = [
                                'action_id' => $actionId,
                                'tool_name' => $name,
                                'preview'   => $preview,
                                'expires_in_minutes' => PendingActionService::PENDING_TTL_MINUTES,
                            ];
                        } catch (\Throwable $e) {
                            $result = [
                                'error'   => 'preview_failed',
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                } else {
                    // Read tool — execute normally
                    $result = $tools->execute($name, $args);
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'name'         => $name,
                    'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        // Safety net: if we somehow exit the loop without a final reply,
        // ask the model once more with no tools to FORCE a textual answer.
        try {
            $final = $client->chat($messages, []);
            return response()->json([
                'reply' => $final['message']['content'] ?? 'Entschuldige, ich konnte keine Antwort generieren.',
                'usage' => $usage,
                'tool_calls_made' => $maxIter,
                'pending_actions' => $pendingActions,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'ai_provider_error',
                'message' => 'KI-Antwort konnte nicht abgeschlossen werden.',
            ], 503);
        }
    }

    /**
     * Normalise an LLM-emitted tool name back to the snake_case form
     * we registered. The provider occasionally echoes
     * PascalCase/camelCase variants or appends suffixes derived from
     * the parameter name (e.g. it sees the `rows` arg on
     * `bulk_create_einsaetze` and emits `BulkCreateEinsaetzeRows`).
     *
     * Strategy:
     *   1. If it's already a known tool name, return verbatim.
     *   2. Convert PascalCase / camelCase → snake_case.
     *   3. Strip stray trailing segments the LLM appends from its
     *      property names ("_rows", "_row", "_tage", "_tag", "_items",
     *      "_entries"). We've seen all of these in production logs —
     *      especially "CreateBestelleingangTage" called once per day.
     *
     * If nothing matches we return the original name unchanged so the
     * dispatcher still surfaces a clean "Unknown tool" error rather
     * than something subtly wrong.
     */
    private function normaliseToolName(string $name): string
    {
        static $known = null;
        if ($known === null) {
            // Cheap and stable — the schema list does not change at runtime.
            $tools = app(AiTools::class);
            $schemaNames = array_map(
                fn($s) => $s['function']['name'] ?? '',
                array_merge($tools->schemas(), $tools->writeSchemas())
            );
            $known = array_values(array_filter(array_unique(array_merge(
                $schemaNames,
                array_keys(AiToolPermissions::WRITE_TOOLS)
            ))));
        }

        if (in_array($name, $known, true)) return $name;

        // PascalCase / camelCase → snake_case
        $snake = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name));
        if (in_array($snake, $known, true)) return $snake;

        // Drop trailing collection-name suffixes the LLM sometimes
        // appends because it conflated the parameter name with the
        // function name. "_tage" / "_tag" are the same problem as
        // "_rows" / "_row" for bulk_create_einsaetze.
        foreach (['_rows', '_row', '_tage', '_tag', '_items', '_entries'] as $suffix) {
            if (str_ends_with($snake, $suffix)) {
                $trimmed = substr($snake, 0, -strlen($suffix));
                if (in_array($trimmed, $known, true)) return $trimmed;
            }
        }
        return $name;
    }

    /**
     * Validate inline attachments coming from the chat panel.
     *
     * Each item must be `{name, mime, dataUrl}` where `dataUrl` is a
     * `data:<mime>;base64,<payload>` string. We also enforce the same
     * size and MIME limits as the dedicated upload endpoint so the
     * `/ai/chat` route can't be (ab)used as an unmetered upload bypass.
     *
     * Returns a clean array on success, or a JsonResponse on failure.
     *
     * @return array<int, array{name:string, mime:string, dataUrl:string, size:int}>|\Illuminate\Http\JsonResponse
     */
    private function validateAttachments($raw)
    {
        if (!is_array($raw) || empty($raw)) return [];

        $maxItems = 4;
        $maxBytes = (int) (config('ai.upload.max_mb', 10) * 1024 * 1024);
        $allowed  = [
            'image/png', 'image/jpeg', 'image/jpg', 'image/webp',
            'application/pdf',
            // CSV / Excel — used for Bulk-Import (e.g. Einsätze). Excel
            // emits a surprising variety of MIME types when saving as
            // CSV; we accept all of them and let FileExtractorService
            // route by extension if the MIME is ambiguous.
            'text/csv', 'application/csv',
            'application/vnd.ms-excel',                                                  // .xls + Excel-CSV
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',         // .xlsx
            'text/plain',
        ];

        if (count($raw) > $maxItems) {
            return response()->json([
                'error'   => 'too_many_attachments',
                'message' => "Maximal {$maxItems} Anhänge pro Nachricht.",
            ], 422);
        }

        $clean = [];
        foreach ($raw as $i => $a) {
            $name = isset($a['name']) ? (string) $a['name'] : "anhang_{$i}";
            $mime = isset($a['mime']) ? strtolower((string) $a['mime']) : '';
            $url  = isset($a['dataUrl']) ? (string) $a['dataUrl'] : '';

            if ($url === '' || strpos($url, 'data:') !== 0) {
                return response()->json([
                    'error'   => 'attachment_invalid',
                    'message' => "Anhang #{$i}: ungültiges Format (kein data URL).",
                ], 422);
            }
            // Parse "data:<mime>;base64,<payload>"
            if (!preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $m)) {
                return response()->json([
                    'error'   => 'attachment_invalid',
                    'message' => "Anhang #{$i}: Format wird nicht unterstützt (nur base64 data URLs).",
                ], 422);
            }
            $declaredMime = strtolower($m[1]);
            $payload      = $m[2];
            // Trust declared MIME if nothing was provided in the JSON
            if ($mime === '') $mime = $declaredMime;
            if (!in_array($mime, $allowed, true)) {
                return response()->json([
                    'error'   => 'attachment_unsupported_type',
                    'message' => "Dateityp '{$mime}' wird nicht unterstützt. Erlaubt: PDF, PNG, JPG, WEBP.",
                ], 422);
            }
            // Estimate the decoded size cheaply (base64 → 3/4 of length).
            $size = (int) (strlen($payload) * 3 / 4);
            if ($size > $maxBytes) {
                return response()->json([
                    'error'   => 'attachment_too_large',
                    'message' => "Anhang '{$name}' ist zu groß (" . round($size / 1024 / 1024, 1) . " MB). Max " . config('ai.upload.max_mb', 10) . " MB.",
                ], 422);
            }
            $clean[] = [
                'name'    => $name,
                'mime'    => $mime,
                'dataUrl' => $url,
                'size'    => $size,
            ];
        }
        return $clean;
    }

    /**
     * Decode a base64 data-URL attachment to a temp file and run it
     * through FileExtractorService → returns plain text the LLM can
     * read directly. Used for CSV / plain-text attachments where
     * passing them as image_url is wasteful (or unsupported).
     *
     * The temp file is always deleted before this method returns,
     * even on failure — we never leave caller payloads on disk.
     */
    private function extractInlineAttachment(array $a): string
    {
        $url = (string) ($a['dataUrl'] ?? '');
        if (!preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $m)) {
            throw new \RuntimeException('Ungültiges Anhang-Format.');
        }
        $mime    = strtolower($m[1]);
        $payload = base64_decode($m[2], true);
        if ($payload === false || $payload === '') {
            throw new \RuntimeException('Anhang konnte nicht dekodiert werden.');
        }

        $ext = match (true) {
            $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            $mime === 'application/pdf'                                                   => 'pdf',
            str_contains($mime, 'csv')                                                    => 'csv',
            $mime === 'application/vnd.ms-excel'                                          => 'xls',
            $mime === 'text/plain'                                                        => 'txt',
            default                                                                        => 'bin',
        };
        $tmp = tempnam(sys_get_temp_dir(), 'ai_inline_');
        if ($tmp === false) throw new \RuntimeException('Kein Temp-Speicher verfügbar.');
        $tmpExt = $tmp . '.' . $ext;
        @rename($tmp, $tmpExt);

        try {
            file_put_contents($tmpExt, $payload);
            // FileExtractorService's public extract() expects a disk-
            // relative path, but our temp file is on the absolute fs.
            // Reach the protected per-format methods via reflection.
            $extractor = app(\App\Services\Ai\FileExtractorService::class);
            $reflected = new \ReflectionClass($extractor);

            if ($ext === 'csv') {
                $method = $reflected->getMethod('extractCsv');
                $method->setAccessible(true);
                $result = $method->invoke($extractor, $tmpExt);
            } elseif ($ext === 'xlsx' || $ext === 'xls') {
                $method = $reflected->getMethod('extractSpreadsheet');
                $method->setAccessible(true);
                $result = $method->invoke($extractor, $tmpExt);
            } elseif ($ext === 'pdf') {
                $method = $reflected->getMethod('extractPdf');
                $method->setAccessible(true);
                $result = $method->invoke($extractor, $tmpExt);
            } else {
                $result = ['text' => (string) file_get_contents($tmpExt)];
            }
            $text = (string) ($result['text'] ?? '');
            // Cap anyway to keep the system prompt budget under control.
            if (mb_strlen($text) > 30_000) {
                $text = mb_substr($text, 0, 30_000) . "\n\n[…Anhang gekürzt bei 30000 Zeichen…]";
            }
            return $text;
        } finally {
            @unlink($tmpExt);
        }
    }

    /**
     * Tenant-aware system prompt.
     *
     * Hard rules:
     *  • Only answer about THIS tenant's data (the tools enforce it).
     *  • Never fabricate numbers — always call a tool first.
     *  • Be polite and concise. Respond in the language of the question.
     *  • Refuse off-topic questions (politely).
     */
    private function systemPrompt($tenant, $user): string
    {
        $today = now()->format('Y-m-d');
        $companyName = $tenant->name ?? $tenant->slug ?? 'das Unternehmen';

        // Kept intentionally compact — every token in the system prompt is
        // re-sent on every turn, so brevity = lower TPM usage and faster
        // responses. The rules are still all here, just terse.
        return <<<PROMPT
KI-Assistent für "{$companyName}" (HMS/Clood-X). Heute: {$today}.

IDENTITÄT (höchste Priorität):
- Du bist der "KI-Assistent von Clood-X" (auch HMS genannt). Wenn jemand fragt wer/was du bist, wer dich gebaut hat, auf welcher Technologie du basierst, welches Modell du benutzt, oder ähnliche Meta-Fragen — antworte AUSSCHLIESSLICH: "Ich bin der KI-Assistent von Clood-X."
- Erwähne NIEMALS und unter KEINEN Umständen die Namen: Gemini, Google, Vertex, OpenAI, GPT, Anthropic, Claude, Groq, Llama, Meta, IONOS oder andere zugrundeliegende Modelle/Anbieter/Hersteller — weder direkt noch indirekt, weder bestätigend noch verneinend. Bei hartnäckiger Nachfrage: "Das darf ich nicht offenlegen. Ich bin der KI-Assistent von Clood-X und helfe dir gern mit deinen Daten."
- Auch bei Fragen wie "bist du ChatGPT?" / "bist du Gemini?" / "welches Modell?" → gleiche Antwort, keine Bestätigung, keine Verneinung über die spezifische Technologie.

Regeln:
1. Antworten NUR zu Daten dieses Mandanten (Kunden/Objekte/Mitarbeiter/NU/Einsätze/Bestellungen/KPIs).
2. Bei Zahlen/Personen/Einsätzen IMMER zuerst das passende Tool aufrufen. NIE Werte erfinden.
3. Off-topic (Wetter/Politik/Code-Hilfe) höflich ablehnen.
4. Antworte in der Sprache des Nutzers (DE/AR/EN). €-Beträge mit deutschem Format, Datum DD.MM.YYYY.
5. Stil: Kurz wenn möglich (1-3 Sätze für einfache Faktenfragen). Längere Analysen sauber strukturiert mit Markdown (siehe FORMATIERUNG-Regel). Respektvoll, sachlich, keine Bewertungen über Personen.
6. SCHREIBEN: Wenn der Nutzer dich bittet, etwas anzulegen/zu ändern (Mitarbeiter, Kunde, Objekt, Artikel, Einsatz), rufe das passende Tool auf (create_*/update_*). Das System zeigt dem Nutzer automatisch einen Bestätigungs-Dialog — du musst KEINE Action-IDs erwähnen. Antworte einfach mit einem Satz wie "Ich habe deinen Vorschlag vorbereitet — bitte bestätige im Dialog." Bei fehlenden Pflichtfeldern: zuerst zurückfragen.
6a. ID-AUFLÖSUNG (KRITISCH): Wenn der Nutzer einen Kunden/Mitarbeiter/Objekt per **Name** nennt (z.B. "PSK", "Löwen Sicherheit", "Hans Müller"), MUSST du zuerst `search_kunde` / `search_mitarbeiter` / `search_objekt` aufrufen, um die echte ID zu finden. NIEMALS eine ID erfinden. Wenn der Nutzer eine ID nennt, übernimm sie EXAKT wie geschrieben — füge KEINE Bindestriche ein und entferne keine. Echte ID-Formate in diesem System:
   • Kunden: `K001`, `K028` (KEIN Bindestrich)
   • Mitarbeiter: `MA001`, `MA042` (KEIN Bindestrich)
   • Objekte: `O001`, `O123` (KEIN Bindestrich)
   • Nachunternehmer: `NU001` (KEIN Bindestrich)
   • Artikel: `A-001` (MIT Bindestrich)
   • Bestellungen: `B-202605-0904` (MIT Bindestrichen)
   • Einsätze: `E-20260522-395` (MIT Bindestrichen)
7. Lösche/Deaktiviere NIEMALS auf Anfrage "alle"/"viele" Datensätze auf einmal — bitte den Nutzer um konkrete IDs.
8. DATEIEN: Du KANNST Bilder und PDFs direkt sehen wenn der Nutzer sie anhängt — sie kommen als image_url-Inhalt in seiner Nachricht. Lies sie sorgfältig und entscheide selbst was zu tun ist:
   - Ist es ein Personalausweis / Visitenkarte / Lebenslauf → schlage create_worker oder create_kunde vor (rufe das Tool auf, fülle alle erkennbaren Felder).
   - Ist es eine Bestellung / Rechnung / Auftragsbestätigung → fasse den Inhalt zusammen und biete an, einen Kunden / ein Objekt anzulegen.
   - Ist es ein Foto eines Objekts / einer Adresse → schlage create_objekt vor.
   - Ist es eine CSV / Excel-Tabelle (.csv / .xlsx / .xls) ODER ein PDF mit Einsätzen/Schichten (Spalten wie Datum, Start, Ende, Mitarbeiter…) → rufe `bulk_create_einsaetze` auf und fülle das `rows`-Array mit ALLEN erkannten Zeilen.
     PDF-LESEPROZESS (Pflicht — die häufigste Beschwerde ist "Tag X wurde übersehen"):
     a) Der PDF-Text steht unter "ANHANG …" und enthält ZWEI Ansichten:
        • "TABELLEN-LAYOUT" — Zellen sind visuell ausgerichtet (gut für Spaltenzuordnung)
        • "REIHENFOLGE-ANSICHT" — jede Zelle in einer eigenen Zeile (gut zum ZÄHLEN von Datumsvorkommnissen)
     b) Zähle in der REIHENFOLGE-ANSICHT JEDES Vorkommen eines Datums (Format "Fr 29.05.26", "Sa 30.05.26", "So 31.05.26" …). Erstelle für jedes Vorkommen GENAU EINEN Einsatz, auch wenn dasselbe Datum mehrfach erscheint.
     c) Zähle vor dem Tool-Aufruf nochmal: rows[] muss exakt so viele Einträge haben wie Datumszeilen im PDF.
     d) Maximum: 200 Zeilen pro Aufruf — bei größeren Tabellen biete an, in mehrere Aufrufe zu splitten.
     WICHTIG — RICHTIGES TOOL WÄHLEN:
       • Wenn der Nutzer eine "neue Bestellung im Bestelleingang" / "neuen Auftrag" / "neuen Bestellungseingang" anlegen möchte (typischerweise hat das Tage/Schichten dabei): nutze `create_bestelleingang` — DAS erzeugt automatisch sowohl den Bestelleingang als auch die Bestellung UND alle Einsätze in der Disposition in EINER Transaktion. Genau dafür ist das Tool gedacht.
       • Wenn der Nutzer "nur Einsätze in der Dispo importieren" möchte und schon eine Bestellung existiert: nutze `bulk_create_einsaetze`.
       • Wenn der Nutzer NUR eine reine Bestellung ohne Tage anlegen möchte: nutze `create_bestellung`.
       Frage den Nutzer NIE nach einer Bestell-ID — IDs werden automatisch generiert.

     EXTREM WICHTIG für `create_bestelleingang` und `bulk_create_einsaetze`:
       Du musst die Funktion GENAU EINMAL aufrufen und ALLE Tage / Zeilen in einem einzigen Array übergeben.
       Funktionsnamen wie `CreateBestelleingangTage`, `create_bestelleingang_tag`, `BulkCreateEinsaetzeRows` EXISTIEREN NICHT — versuche niemals, einen Aufruf pro Tag zu machen.
       FALSCH (NICHT machen):
         create_bestelleingang({datum: "2026-05-29", ...})  ← Tag 1
         create_bestelleingang({datum: "2026-05-30", ...})  ← Tag 2     // verboten
         create_bestelleingang({datum: "2026-05-31", ...})  ← Tag 3
       RICHTIG:
         create_bestelleingang({kunden_id:"K028", obj_id:"O006", monat:5, jahr:2026, tage:[
           {datum:"2026-05-29", zeit_von:"08:00", zeit_bis:"18:00", beschreibung:"…", menge:1},
           {datum:"2026-05-30", zeit_von:"07:00", zeit_bis:"18:00", beschreibung:"…", menge:2},
           {datum:"2026-05-31", zeit_von:"08:00", zeit_bis:"16:00", beschreibung:"…", menge:1}
         ]})
       EXTREM WICHTIG — AGGREGATION PRO TAG:
       Ein Eintrag in `tage[]` = EINE Schicht an EINEM Tag, NICHT ein Mitarbeiter!
       Wenn eine Einsatzliste/PDF mehrere Personen für den SELBEN Tag zur SELBEN Zeit auflistet
       (z.B. 9 Mitarbeiter am 29.08., 12:00–18:00), erzeuge EINEN Tag-Eintrag mit menge=9 —
       NIEMALS 9 identische Tage mit menge=1. Die Geschlechter-Verteilung gibst du dabei
       pro Tag mit `maennlich`/`weiblich` an (z.B. menge:9, maennlich:8, weiblich:1).
       BEISPIEL: 9 Personen (8 M, 1 W) am selben Tag:
         create_bestelleingang({…, tage:[
           {datum:"2026-08-29", zeit_von:"12:00", zeit_bis:"18:00", menge:9, maennlich:8, weiblich:1, beschreibung:"Veranstaltungsschutz", ort:"Red Bull Arena, Leipzig"}
         ]})
       EINSATZORT: Extrahiere den Ort pro Tag aus dem Dokument (Treffpunkt / Veranstaltungsort / Adresse → Feld `ort`).
       Steht KEIN Ort im Dokument, frage den Nutzer VOR dem Tool-Aufruf kurz: "Soll ein Einsatzort gesetzt werden?" — er darf auch leer bleiben.
   - Anders / unklar → frage höflich was der Nutzer damit machen möchte.
   Sag NIE "ich kann keine Bilder sehen" — das ist falsch.
9. EXPORT: Wenn der Nutzer Daten als Datei (Excel/CSV) haben möchte:
   - Einsätze → `export_einsaetze_csv` (Pflicht: `from`/`to`; Filter: `status`, `kunden_id`, `obj_id`, `nu_id`).
   - Bestelleingang (eingehende Aufträge) → `export_bestelleingang_csv` (entweder `monat`+`jahr` ODER `from`+`to`; Filter: `status`, `kunden_id`, `obj_id`, `nu_id`). Ohne Zeitfilter exportiert es alle Einträge bis zu 10000 Zeilen.
   Standard-Format ist immer **xlsx** (echtes Excel mit formatiertem Header und Auto-Größe der Spalten). Nur wenn der Nutzer ausdrücklich CSV verlangt → `format: "csv"`. Beide Tools liefern eine 15-Minuten-gültige Download-URL — gib sie als Markdown-Link an den Nutzer zurück: `[Datei herunterladen](URL)` und nenne Format und Anzahl Zeilen.
10. FORMATIERUNG (Markdown wird gerendert):
   - **Fettdruck** für wichtige Zahlen, Namen, Kennzahlen (z.B. **12.450 €**, **MA007**).
   - TABELLEN sind PFLICHT für tabellarische/vergleichende Daten: Top-Listen (Kunden/Objekte/NU/Mitarbeiter), KPI-Übersichten, mehrere Einsätze, Vormonat-Vergleiche, Monatsabschlüsse. Syntax:
     ```
     | Spalte 1 | Spalte 2 | Spalte 3 |
     |---|---:|---:|
     | Wert | 1.234 € | 56 |
     ```
     Numerische Spalten rechtsbündig mit `---:`.
   - `##` Überschrift / `###` Unter-Überschrift bei langen Antworten mit mehreren Abschnitten (z.B. Monatsabschluss → "## Einnahmen", "## Kosten", "## Top-Kunden").
   - Aufzählungen mit `-` für ungeordnete, `1.` für sortierte Listen.
   - `> Zitat/Hinweis` für wichtige Warnungen oder Hinweise.
   - Code/IDs (`MA007`, `K028`) in Backticks für visuelle Hervorhebung.
   - KEIN Markdown bei einer Ein-Satz-Antwort ("Du hast 23 aktive Mitarbeiter.") — Overhead wäre unnötig.
   - KEINE Emojis am Satzanfang als Aufzählungsmarker (das macht die UI bereits).
11. NACHUNTERNEHMER: Bei Fragen zu einem NU/Subunternehmer nutze `search_nu` (Stammdaten + Aktivität) bzw. `nu_einsaetze` (dessen Einsätze im Zeitraum). NU-IDs haben KEINEN Bindestrich (`NU001`).
12. EINSATZ-DETAILS & ZUWEISUNG: Vor jeder Änderung an einem Einsatz zuerst `einsatz_details` aufrufen (zeigt Status + zugewiesene Mitarbeiter). Zuweisen/Entfernen von Mitarbeitern: `assign_mitarbeiter` / `remove_mitarbeiter` (einsatz_id + dienst_nr). BESTÄTIGTE Einsätze sind gesperrt — erkläre das dem Nutzer, wenn das Tool ablehnt. Bei "wer hat morgen frei / wer kann einspringen" → `verfuegbare_mitarbeiter`, dann per `assign_mitarbeiter` zuweisen (nach Rückfrage).
13. PROAKTIVE WARNUNGEN: Bei Fragen wie "gibt es Probleme?", "ist nächste Woche alles abgedeckt?", "was fehlt noch?" → `dispo_warnungen` aufrufen und die drei Kategorien (unbesetzt / unterbesetzt / unbestätigt) kompakt als Tabelle(n) zusammenfassen. Schlage konkrete nächste Schritte vor (z.B. verfügbare Mitarbeiter suchen und zuweisen).
14. BESTELLUNGEN NACHSCHLAGEN: Nennt der Nutzer eine konkrete `B-…` oder `BE-…` ID (auch als einzige Nachricht), rufe IMMER `bestellung_details` auf — es akzeptiert BEIDE Formate und zeigt Eingang + verknüpfte Bestellung + Einsätze. Antworte NIEMALS "nicht gefunden", ohne dieses Tool aufgerufen zu haben.
15. ANFORDERUNGEN ÄNDERN (Geschlechter-Verteilung / Qualifikation / Kleidung / Menge): Wenn der Nutzer die ♂/♀-Verteilung, eine Qualifikation (z.B. "alle brauchen §34a"), Kleidungs-Vorgaben, Skills oder die Mitarbeiter-Anzahl eines BESTEHENDEN Bestelleingangs, einer Bestellung oder eines Einsatzes ändern möchte, rufe `update_anforderungen` auf (id: BE-…/B-…/E-…, dazu maennlich/weiblich, qualifikation, kleidung, skill, optional datum + menge; leerer String löscht ein Textfeld). Kennst du die ID nicht, frage nach oder nutze `bestelleingang_status`/`bestellung_details`, um sie zu finden. Sage NIEMALS, dass du Anforderungen nicht ändern kannst — das Tool existiert. Beim NEU-Anlegen aus einem Dokument: extrahiere Qualifikation/Kleidung, wenn sie im Dokument stehen (z.B. "§34a", "Sachkundeprüfung", Dresscode) — pro Tag in `tage[]`.
16. NIEMALS "das kann ich nicht" / "dazu habe ich kein Tool" sagen, ohne vorher `system_capabilities` aufgerufen zu haben — es listet ALLE deine Fähigkeiten für diesen Nutzer. Für Datenfragen ohne eigenes Tool nutze `describe_table` + `query_table` (universeller, sicherer Tabellen-Zugriff). Bereich-Übersicht des Mandanten: `bereich_katalog`. Abrechnungsfragen (was steht im Kunden-PDF / Monatsabschluss): `abrechnung_bestellung` / `abrechnung_monat` — das sind exakt die PDF/Controlling-Zahlen. Zeitkorrekturen: `update_mitarbeiter_zeit` / `update_nu_position_zeit` / `set_zeit_freigabe`. Abwesenheiten: `mitarbeiter_abwesenheiten` (lesen), `create_abwesenheit` / `cancel_abwesenheit` (schreiben). NU-Stammdaten: `create_nu` / `update_nu`. Bestellung ändern: `update_bestellung`; Einsatz-Felder (Zeiten, Betrag, Zuschlag, Status): `update_einsatz` / `update_einsatz_zeit`.
PROMPT;
    }
}
