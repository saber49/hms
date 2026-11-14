<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\Contracts\AiClientInterface;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI PDF-template generator.
 *
 * Takes an uploaded company design (image / PDF) and asks the multimodal
 * model to reproduce it as a self-contained HTML fragment that uses a
 * FIXED set of placeholders ({{company_name}}, {{einsatz_rows}}, …). The
 * frontend later substitutes real order data into those placeholders and
 * renders the result.
 *
 * Security:
 *   • Same auth+tenant boundary as the rest of the /api group.
 *   • Gated by `permission:users.manage` at the route layer because the
 *     stored template is company-wide branding.
 *   • The model output is sanitised server-side (script/iframe/handlers
 *     stripped) AND again on the client via DOMPurify before it is ever
 *     injected into the DOM — defence in depth.
 */
class AiTemplateController extends Controller
{
    /** Whitelisted placeholders per document type. */
    private const PLACEHOLDERS = [
        'order' => [
            'company_name', 'company_info', 'logo', 'doc_title', 'recipient',
            'auftragsdatum', 'auftrags_nr', 'kunde', 'objekt', 'monat',
            'arbeitszeit', 'nu', 'einsatz_table', 'footer',
        ],
        'worker' => [
            'company_name', 'company_info', 'logo', 'doc_title',
            'mitarbeiter_name', 'dienst_nr', 'bereich', 'einsaetze', 'monat',
            'stunden_table', 'summe_std', 'footer',
        ],
    ];

    /** The auto-built table placeholder that MUST be present per type. */
    private const TABLE_PLACEHOLDER = [
        'order'  => 'einsatz_table',
        'worker' => 'stunden_table',
    ];

    /** Hard cap on the stored template size (bytes). */
    private const MAX_TEMPLATE_BYTES = 120 * 1024;

    private function normalizeType($raw): string
    {
        return $raw === 'worker' ? 'worker' : 'order';
    }

    /**
     * POST /api/ai/generate-template
     * Body: { type: 'order'|'worker', attachments: [{ name, mime, dataUrl }] }
     *
     * IMPORTANT: this only GENERATES and returns the template for preview.
     * It does NOT persist anything — the tenant must explicitly approve via
     * POST /api/ai/template (save) before it becomes company-wide.
     */
    public function generate(Request $request, AiClientInterface $client)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $tenant = TenantService::current();
        if (!$tenant) {
            return response()->json(['error' => 'tenant_not_resolved'], 400);
        }
        $type = $this->normalizeType($request->input('type'));

        // Light rate limit — generation is expensive (vision + long output).
        $rateKey = "ai:tpl:{$tenant->id}:{$user->id}:" . now()->format('YmdHi');
        $count = Cache::increment($rateKey);
        if ($count === 1) Cache::put($rateKey, 1, 65);
        if ($count > 5) {
            return response()->json([
                'error'   => 'rate_limited',
                'message' => 'Bitte warte einen Moment, bevor du ein weiteres Design generierst.',
            ], 429);
        }

        $attachments = $this->validateAttachments($request->input('attachments', []));
        if ($attachments instanceof \Illuminate\Http\JsonResponse) {
            return $attachments;
        }
        if (empty($attachments)) {
            return response()->json([
                'error'   => 'no_attachment',
                'message' => 'Bitte ein Bild oder PDF des Designs anhängen.',
            ], 422);
        }

        // Build the multimodal user turn (only images / PDFs are accepted
        // here, so every attachment becomes an image_url vision part).
        $imageParts = [];
        foreach ($attachments as $a) {
            $imageParts[] = ['type' => 'image_url', 'image_url' => ['url' => $a['dataUrl']]];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => array_merge(
                [['type' => 'text', 'text' => $this->userPromptFor($type)]],
                $imageParts,
            )],
        ];

        try {
            $res = $client->chat($messages, [], [
                'max_tokens'  => 8192,
                'temperature' => 0.15,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai.template.generate_failed', ['error' => $e->getMessage()]);
            $msg = $e->getMessage() === 'AI_RATE_LIMITED'
                ? 'Die KI ist gerade ausgelastet. Bitte in einem Moment erneut versuchen.'
                : 'Die Vorlage konnte nicht generiert werden. Bitte erneut versuchen.';
            return response()->json(['error' => 'generation_failed', 'message' => $msg], 502);
        }

        $reply = (string) ($res['message']['content'] ?? '');
        $html  = $this->extractHtml($reply);
        $html  = $this->sanitize($html);

        if ($html === '' || stripos($html, '{{') === false) {
            return response()->json([
                'error'   => 'invalid_output',
                'message' => 'Die KI hat kein verwertbares Design erzeugt. Bitte mit einem klareren Bild/PDF erneut versuchen.',
            ], 422);
        }
        if (strlen($html) > self::MAX_TEMPLATE_BYTES) {
            $html = substr($html, 0, self::MAX_TEMPLATE_BYTES);
        }

        // Preview only — NOT persisted. Return for the approval step.
        return response()->json([
            'success' => true,
            'type'    => $type,
            'html'    => $html,
        ]);
    }

    /**
     * POST /api/ai/template
     * Body: { type: 'order'|'worker', html: string }
     * Persists an approved template company-wide under pdf_templates.{type}.
     */
    public function save(Request $request)
    {
        $tenant = TenantService::current();
        if (!$tenant) {
            return response()->json(['error' => 'tenant_not_resolved'], 400);
        }
        $type = $this->normalizeType($request->input('type'));
        $html = $this->sanitize((string) $request->input('html', ''));

        if (trim($html) === '') {
            return response()->json([
                'error'   => 'empty_template',
                'message' => 'Kein Template zum Speichern übergeben.',
            ], 422);
        }
        // Must still contain the auto-built table placeholder for this type.
        if (stripos($html, '{{' . self::TABLE_PLACEHOLDER[$type]) === false) {
            return response()->json([
                'error'   => 'invalid_template',
                'message' => 'Das Template ist unvollständig (Tabellen-Platzhalter fehlt). Bitte neu generieren.',
            ], 422);
        }
        if (strlen($html) > self::MAX_TEMPLATE_BYTES) {
            $html = substr($html, 0, self::MAX_TEMPLATE_BYTES);
        }

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $templates = is_array($settings['pdf_templates'] ?? null) ? $settings['pdf_templates'] : [];
        $templates[$type] = [
            'html'       => $html,
            'updated_at' => now()->toIso8601String(),
        ];
        $settings['pdf_templates'] = $templates;
        $tenant->settings = $settings;
        $tenant->save();

        return response()->json(['success' => true, 'type' => $type]);
    }

    /**
     * DELETE /api/ai/template?type=order|worker
     * Removes the stored custom template for the given type (revert to standard).
     */
    public function clear(Request $request)
    {
        $tenant = TenantService::current();
        if (!$tenant) {
            return response()->json(['error' => 'tenant_not_resolved'], 400);
        }
        $type = $this->normalizeType($request->input('type'));
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        if (isset($settings['pdf_templates'][$type])) {
            unset($settings['pdf_templates'][$type]);
            $tenant->settings = $settings;
            $tenant->save();
        }
        return response()->json(['success' => true, 'type' => $type]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Prompts
    // ─────────────────────────────────────────────────────────────────
    private function systemPrompt(): string
    {
        return implode("\n", [
            'Du bist ein Experte für HTML/CSS-Dokumentvorlagen.',
            'Du erhältst das Design eines Geschäftsdokuments als Bild oder PDF und',
            'reproduzierst es als eigenständiges HTML-Fragment, das exakt wie die Vorlage aussieht.',
            'Du antwortest ausschließlich mit HTML — keine Erklärungen, kein Markdown.',
        ]);
    }

    private function userPromptFor(string $type): string
    {
        $ph = '{{' . implode('}}, {{', self::PLACEHOLDERS[$type]) . '}}';

        $common = [
            'Erstelle ein HTML-Fragment, das das angehängte Dokument-Design optisch so genau wie möglich nachbildet:',
            'Farben, Schriftart, Schriftgrößen, Abstände, Linien, Rahmen und insbesondere den Stil der Abschnitts-Überschriften',
            '(z. B. dünne Linie vs. gefüllter Farbbalken) und der Tabellen-Kopfzeile.',
            '',
            'Verwende AUSSCHLIESSLICH diese Platzhalter an den passenden Stellen (keine anderen erfinden):',
            $ph,
            '',
            'Regeln:',
            '- {{logo}} wird durch ein <img>-Tag ersetzt (oder leer) — platziere es dort, wo das Logo gehört.',
        ];

        if ($type === 'worker') {
            $specific = [
                '- {{doc_title}} ist der Dokumenttitel (z. B. "STUNDENNACHWEIS / MONATSABSCHLUSS").',
                '- {{mitarbeiter_name}}, {{dienst_nr}}, {{bereich}}, {{einsaetze}} (Anzahl) und {{monat}} sind Infofelder im Kopfbereich.',
                '- Füge an der Stelle der Stunden-Tabelle GENAU den Platzhalter {{stunden_table}} in einer eigenen Zeile ein.',
                '  Baue die Tabelle NICHT selbst — sie wird automatisch als vollständige <table> mit den Spalten',
                '  Nr., Datum, Tag, Objekt, Von, Bis, Pause, Std eingefügt. Verwende KEINE <div>-Tabelle.',
                '- {{summe_std}} ist die Gesamtstundenzahl (Zusammenfassung am Ende).',
                '- {{footer}} steht für eine Fußzeile am Seitenende.',
            ];
        } else {
            $specific = [
                '- {{doc_title}} ist der Dokumenttitel (z. B. "AUFTRAGSBESTÄTIGUNG / EINSATZPLAN").',
                '- Füge an der Stelle der Einsatztage-Tabelle GENAU den Platzhalter {{einsatz_table}} in einer eigenen Zeile ein.',
                '  Baue die Tabelle NICHT selbst — sie wird automatisch als vollständige <table> mit den Spalten',
                '  Nr., Datum, Wochentag, Zeit von, Zeit bis, Soll-Std, Bemerkung eingefügt. Verwende KEINE <div>-Tabelle.',
                '- {{footer}} steht für eine rechtliche Fußzeile am Seitenende.',
            ];
        }

        $tail = [
            '- Nur Inline-CSS (style-Attribute) oder EIN einzelner <style>-Block. Keine externen Schriften, Bilder, Links, Skripte.',
            '- Breite passend für A4 (ca. 794px), weißer Hintergrund.',
            '- Gib NUR das HTML zurück, beginnend mit dem Wurzel-<div> oder <style>.',
        ];

        return implode("\n", array_merge($common, $specific, $tail));
    }

    // ─────────────────────────────────────────────────────────────────
    // Output handling
    // ─────────────────────────────────────────────────────────────────

    /** Strip an optional ```html fence and surrounding prose. */
    private function extractHtml(string $reply): string
    {
        $reply = trim($reply);
        if (preg_match('#```(?:html)?\s*([\s\S]*?)```#i', $reply, $m)) {
            return trim($m[1]);
        }
        return $reply;
    }

    /**
     * Best-effort server-side sanitiser. Removes dangerous elements and
     * inline event handlers. The client re-sanitises with DOMPurify, so
     * this is defence in depth rather than the sole guard.
     */
    private function sanitize(string $html): string
    {
        // Drop paired dangerous elements (with their content).
        $html = preg_replace(
            '#<\s*(script|iframe|object|embed|form|noscript)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            '', $html
        );
        // Drop standalone / void dangerous elements.
        $html = preg_replace(
            '#<\s*(script|iframe|object|embed|link|meta|base|form|input|button|noscript)\b[^>]*/?>#is',
            '', $html
        );
        // Remove inline event handlers (onclick=, onload=, …).
        $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html);
        $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html);
        $html = preg_replace('#\son\w+\s*=\s*[^\s>]+#i', '', $html);
        // Neutralise javascript: URLs.
        $html = preg_replace('#javascript\s*:#i', '', $html);

        return trim((string) $html);
    }

    // ─────────────────────────────────────────────────────────────────
    // Attachment validation (mirrors AiAssistantController, images+PDF only)
    // ─────────────────────────────────────────────────────────────────
    private function validateAttachments($raw)
    {
        if (!is_array($raw) || empty($raw)) return [];

        $maxItems = 2;
        $maxBytes = (int) (config('ai.upload.max_mb', 10) * 1024 * 1024);
        $allowed  = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'application/pdf'];

        if (count($raw) > $maxItems) {
            return response()->json([
                'error'   => 'too_many_attachments',
                'message' => "Maximal {$maxItems} Datei(en).",
            ], 422);
        }

        $clean = [];
        foreach ($raw as $i => $a) {
            $name = isset($a['name']) ? (string) $a['name'] : "design_{$i}";
            $mime = isset($a['mime']) ? strtolower((string) $a['mime']) : '';
            $url  = isset($a['dataUrl']) ? (string) $a['dataUrl'] : '';

            if ($url === '' || strpos($url, 'data:') !== 0
                || !preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $m)) {
                return response()->json([
                    'error'   => 'attachment_invalid',
                    'message' => "Anhang #{$i}: ungültiges Format (nur base64 data URLs).",
                ], 422);
            }
            $declaredMime = strtolower($m[1]);
            if ($mime === '') $mime = $declaredMime;
            if (!in_array($mime, $allowed, true)) {
                return response()->json([
                    'error'   => 'attachment_unsupported_type',
                    'message' => "Dateityp '{$mime}' wird nicht unterstützt. Erlaubt: PDF, PNG, JPG, WEBP.",
                ], 422);
            }
            $size = (int) (strlen($m[2]) * 3 / 4);
            if ($size > $maxBytes) {
                return response()->json([
                    'error'   => 'attachment_too_large',
                    'message' => "Datei '{$name}' ist zu groß (max " . config('ai.upload.max_mb', 10) . " MB).",
                ], 422);
            }
            $clean[] = ['name' => $name, 'mime' => $mime, 'dataUrl' => $url, 'size' => $size];
        }
        return $clean;
    }
}
