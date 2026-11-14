<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiToolPermissions;
use App\Services\Ai\Contracts\AiClientInterface;
use App\Services\Ai\FileExtractorService;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\WriteTools;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Drop a PDF / image of a new customer or worker into the chat and the
 * AI will offer to register them" — that flow lives here.
 *
 * Two endpoints:
 *
 *   POST /api/ai/extract
 *      multipart upload + intent ("create_worker" | "create_kunde" | ...)
 *      → returns either a pending action_id (LLM was confident) or a
 *        partial preview with missing-fields hints (LLM needs more info)
 *
 *   POST /api/ai/extract/free
 *      multipart upload + free-text user message
 *      → routes the file's extracted text + the message back through
 *        the standard /ai/chat tool-loop so the LLM can decide what to
 *        do (create_worker, search, summarise, ...). Useful when the
 *        user pastes a PDF without specifying intent.
 *
 * Limits
 * ------
 *  - Max file size: 10 MB (config('ai.upload.max_mb'))
 *  - Allowed MIME: PDF, JPG, PNG, TXT, plain
 *  - Per-user upload throttle: 5/min, 50/day
 */
class AiUploadController extends Controller
{
    /** Allowed MIME prefixes — tightened to avoid weird payloads. */
    protected const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
    ];

    public function __construct(
        protected FileExtractorService $extractor,
        protected AiClientInterface $client,
        protected WriteTools $writer,
        protected PendingActionService $pending,
    ) {}

    /**
     * Extract → propose write action. The caller MUST specify the
     * intended write tool (`tool` field) so we know which JSON shape
     * to extract. We then call the LLM with a tight JSON-mode prompt,
     * validate the result, build a preview and register the pending
     * action — same downstream as /ai/chat's write path.
     */
    public function extract(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['error' => 'unauthenticated'], 401);
        $tenant = TenantService::current();
        if (!$tenant) return response()->json(['error' => 'tenant_not_resolved'], 400);

        // Rate limit (file uploads are expensive — tighter than chat)
        if (!$this->checkUploadRate($tenant->id, (int)$user->id)) {
            return response()->json([
                'error'   => 'rate_limited',
                'message' => 'Zu viele Uploads. Bitte etwas warten.',
            ], 429);
        }

        $tool = (string) $request->input('tool', '');
        if (!AiToolPermissions::isWrite($tool)) {
            return response()->json([
                'error'   => 'invalid_tool',
                'message' => 'Ungültiges Schreib-Tool.',
                'hint'    => 'Erlaubt: ' . implode(', ', array_keys(AiToolPermissions::WRITE_TOOLS)),
            ], 422);
        }

        $perm = AiToolPermissions::permissionFor($tool);
        if ($perm && (!method_exists($user, 'hasPermission') || !$user->hasPermission($perm))) {
            return response()->json([
                'error'    => 'forbidden',
                'required' => $perm,
            ], 403);
        }

        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['error' => 'no_file'], 422);
        }
        $mime = (string) $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return response()->json([
                'error'   => 'unsupported_type',
                'mime'    => $mime,
                'allowed' => self::ALLOWED_MIMES,
            ], 422);
        }
        $maxBytes = (int) (config('ai.upload.max_mb', 10) * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            return response()->json([
                'error'      => 'file_too_large',
                'max_mb'     => config('ai.upload.max_mb', 10),
                'actual_mb'  => round($file->getSize() / 1048576, 2),
            ], 413);
        }

        // 1. Store
        $stored = $this->extractor->store($file, $tenant->slug ?? 'unknown');

        try {
            // 2. Extract text
            $extracted = $this->extractor->extract($stored['disk_path'], $stored['disk'], $mime);

            // 3. Ask the LLM to convert text → structured args
            $args = $this->extractStructured($tool, $extracted['text'], $request->input('hint'));

            // 4. Validate + build preview + register pending action
            $preview = $this->writer->preview($tool, $args);

            $actionId = $this->pending->register(
                $tool,
                $args,
                $preview + ['_source_file' => $stored['original_name']],
                (int) $user->id,
                trim(($user->name ?? '') . ' ' . ($user->email ?? ''))
            );

            return response()->json([
                'requires_confirmation' => true,
                'action_id'             => $actionId,
                'tool_name'             => $tool,
                'preview'               => $preview,
                'expires_in_minutes'    => PendingActionService::PENDING_TTL_MINUTES,
                'extraction'            => [
                    'extractor' => $extracted['extractor'],
                    'pages'     => $extracted['pages'] ?? null,
                    'truncated' => $extracted['truncated'],
                    'chars'     => mb_strlen($extracted['text']),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai.extract.failed', [
                'tenant' => $tenant->slug,
                'user'   => $user->id,
                'tool'   => $tool,
                'mime'   => $mime,
                'error'  => $e->getMessage(),
            ]);
            return response()->json([
                'error'   => 'extraction_failed',
                'message' => $e->getMessage(),
            ], 422);
        } finally {
            // Whether success or failure: never keep raw uploads around
            // longer than this single request. The args + preview already
            // captured everything we need in ai_actions.
            $this->extractor->delete($stored['disk_path'], $stored['disk']);
        }
    }

    /**
     * Calls the LLM in JSON-mode with a tool-specific schema prompt.
     * Returns a validated args array ready to feed WriteTools::preview().
     */
    protected function extractStructured(string $tool, string $text, ?string $hint = null): array
    {
        $schema = $this->jsonSchemaFor($tool);

        $sys = <<<SYS
Du bist ein präziser Daten-Extraktor. Gegeben ist Text aus einem PDF / Bild
(z.B. Personalbogen, Visitenkarte, Anschreiben). Extrahiere die Felder
exakt nach dem JSON-Schema. Regeln:
- Antworte AUSSCHLIESSLICH mit gültigem JSON, ohne Markdown / Erklärung.
- Nicht erfindbare Felder weglassen (NICHT raten).
- Datumsformat YYYY-MM-DD. Telefonnummern wie im Original.
- Bei Mehrdeutigkeit das wahrscheinlichste, klar nicht erfundene wählen.
SYS;

        $userMsg = "ZIEL-TOOL: {$tool}\n";
        if ($hint) {
            $userMsg .= "ZUSÄTZLICHER HINWEIS: {$hint}\n";
        }
        $userMsg .= "\nERWARTETES JSON-SCHEMA:\n" . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $userMsg .= "\n\nDOKUMENT-TEXT:\n---\n" . mb_substr($text, 0, 50_000) . "\n---";

        $resp = $this->client->chat(
            [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $userMsg],
            ],
            [],
            [
                'temperature'    => 0.1,
                'max_tokens'     => 800,
                'response_format' => ['type' => 'json_object'],
            ]
        );

        $raw = (string) ($resp['message']['content'] ?? '');
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            // Sometimes the model wraps in a code fence even with json_object;
            // strip and retry parse.
            $stripped = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
            $json = json_decode($stripped, true);
        }
        if (!is_array($json)) {
            throw new \RuntimeException('Konnte keine strukturierten Daten extrahieren — bitte eine andere Datei versuchen.');
        }
        return $json;
    }

    /**
     * Returns the field shape we'd like the LLM to fill in for each
     * write tool. Mirrors AiTools::writeSchemas() but flatter so JSON
     * mode is easier for the LLM to satisfy.
     */
    protected function jsonSchemaFor(string $tool): array
    {
        return match ($tool) {
            'create_worker' => [
                'vorname'     => 'string — Vorname des Mitarbeiters',
                'name'        => 'string — Nachname',
                'anrede'      => 'string optional — "Herr"|"Frau"|"Divers"',
                'geschlecht'  => 'string optional — "m"|"w"|"d"',
                'geburtstag'  => 'string optional — YYYY-MM-DD',
                'telefon'     => 'string optional',
                'mobil'       => 'string optional',
                'email'       => 'string optional',
                'strasse'     => 'string optional',
                'plz'         => 'string optional',
                'ort'         => 'string optional',
                'land'        => 'string optional — z.B. "DE"',
                'bereich'     => 'string optional — z.B. "Sicherheit"',
                'eintritt'    => 'string optional — YYYY-MM-DD',
                'stundenlohn' => 'number optional',
            ],
            'create_kunde' => [
                'kundenname'    => 'string — Firmen- oder Personenname (Pflicht)',
                'anschrift_typ' => 'string optional — "Firma"|"Privat"',
                'strasse'       => 'string optional',
                'plz'           => 'string optional',
                'ort'           => 'string optional',
                'land'          => 'string optional',
                'telefon1'      => 'string optional',
                'mobil'         => 'string optional',
                'email'         => 'string optional',
                'website'       => 'string optional',
                'haupt_ap'      => 'string optional — Ansprechpartner',
                'bereich'       => 'string optional',
                'notiz'         => 'string optional',
            ],
            'create_objekt' => [
                'kunden_id'        => 'string — z.B. "K-042" (Pflicht)',
                'objektname'       => 'string — Name (Pflicht)',
                'adresse'          => 'string optional',
                'plz'              => 'string optional',
                'stadt'            => 'string optional',
                'bundesland'       => 'string — 2-stelliger Code, z.B. ST oder SN (Pflicht)',
                'ansprechpartner'  => 'string optional',
                'kontakt_telefon'  => 'string optional',
                'kontakt_email'    => 'string optional',
                'bereich'          => 'string optional',
                'modell'           => 'string optional — "Standard"|"Pro Einsatz"|"Pro Stunde"|"Monatspauschale"',
                'preis_kunde'      => 'number optional',
                'nu_preis'         => 'number optional',
            ],
            'create_artikel' => [
                'obj_id'        => 'string — z.B. "O-021" (Pflicht)',
                'artikel_name'  => 'string — (Pflicht)',
                'bereich'       => 'string optional',
                'modell'        => 'string optional',
                'preis_kunde'   => 'number optional',
                'nu_preis'      => 'number optional',
                'einheit'       => 'string optional',
            ],
            default => throw new \InvalidArgumentException("Schema-Mapping für '{$tool}' fehlt."),
        };
    }

    /** Per-user upload throttle. Cheap & atomic via cache. */
    protected function checkUploadRate(int $tenantId, int $userId): bool
    {
        $minKey = "ai:upload:rate:{$tenantId}:{$userId}:" . now()->format('YmdHi');
        $dayKey = "ai:upload:day:{$tenantId}:{$userId}:" . now()->format('Ymd');

        $perMin = (int) config('ai.upload.per_minute', 5);
        $perDay = (int) config('ai.upload.per_day',    50);

        $m = Cache::increment($minKey);
        if ($m === 1) Cache::put($minKey, 1, 65);
        if ($m > $perMin) return false;

        $d = Cache::increment($dayKey);
        if ($d === 1) Cache::put($dayKey, 1, 86_400 + 60);
        if ($d > $perDay) return false;

        return true;
    }
}
