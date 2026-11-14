<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PdfHtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Company-wide PDF layout templates (Dokument-Design).
 *
 * One template per document type — order (Leistungsnachweis), ab
 * (Auftragsbestätigung), worker (Stundennachweis). A template holds
 * everything the editor lets the user change that is NOT per-document
 * data: edited static texts (+ their inline formatting), hidden sections,
 * the extra note, inserted free-form elements, moved header blocks and the
 * signature/stamp placement. Every renderer (editor, Auftragsbestätigung
 * list, Monatsabschluss send/print) applies it, so all PDFs of a type look
 * the same.
 *
 * Stored as JSON files on the private local disk (not in tenants.settings)
 * because inserted images can make a template large and tenants.settings
 * is loaded on every request.
 *
 * Write gate: Dokument-Design permission OR the edit permission of the
 * document type (the user asked for "every save updates the template").
 */
class PdfLayoutController extends Controller
{
    private const TYPES = ['order', 'ab', 'worker'];

    private const TYPE_EDIT_PERMISSION = [
        'order'  => 'auftraege.bestellungen.edit',
        'ab'     => 'auftraege.bestaetigung.edit',
        'worker' => 'auftraege.monatsabschluss.edit',
    ];

    private const MAX_BYTES        = 4 * 1024 * 1024;
    private const MAX_IMAGE_BYTES  = 1536 * 1024;
    private const KEY_RE           = '/^[A-Za-z][A-Za-z0-9_.-]{0,60}$/';

    // Read access per type: whoever may open documents of that type (or
    // design / edit them). Other types come back as null (= built-in look).
    private const TYPE_VIEW_PERMISSION = [
        'order'  => 'auftraege.bestellungen.view',
        'ab'     => 'auftraege.bestaetigung.view',
        'worker' => 'auftraege.monatsabschluss.view',
    ];

    // Version history (Verlauf): newest state after every save, coalesced
    // per user within HISTORY_MERGE_SECONDS (autosave ticks every 1.2 s).
    private const HISTORY_MAX           = 50;
    private const HISTORY_MERGE_SECONDS = 600;
    private const HISTORY_ID_RE         = '/^[0-9]{14}_[a-z0-9]{6}$/';

    /** GET /api/pdf/layouts — all templates of the tenant (+ wizard flag). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $out = [];
        foreach (self::TYPES as $t) $out[$t] = $this->canRead($user, $t) ? $this->read($tenantId, $t) : null;
        return response()->json([
            'layouts'     => $out,
            'wizard_done' => (bool) data_get($user->tenant?->settings, 'pdf_wizard_done', false),
        ]);
    }

    /** PUT /api/pdf/layout — { type, layout } replaces the type's template. */
    public function update(Request $request): JsonResponse
    {
        $type = (string) $request->input('type');
        if (!in_array($type, self::TYPES, true)) return response()->json(['error' => 'Ungültiger Dokumenttyp'], 422);
        if ($err = $this->denyWrite($request, [$type])) return $err;

        $raw = $request->input('layout');
        if (!is_array($raw)) return response()->json(['error' => 'Layout fehlt'], 422);

        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        return $this->locked($tenantId, $type, function () use ($raw, $tenantId, $type, $user) {
            $stored = $this->read($tenantId, $type);
            foreach (['design', 'bausteine', 'email'] as $k) {
                if (!array_key_exists($k, $raw) && is_array($stored) && array_key_exists($k, $stored)) $raw[$k] = $stored[$k];
            }

            $layout = $this->sanitizeLayout($raw);
            $layout['updated_at'] = now()->toIso8601String();
            $layout['updated_by'] = (int) $user->id;

            $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > self::MAX_BYTES) {
                return response()->json(['error' => 'Vorlage zu groß (max. 4 MB) — bitte eingefügte Bilder verkleinern.'], 422);
            }
            $this->seedHistory($tenantId, $type, $stored);
            $this->writeAtomic($this->path($tenantId, $type), $json);
            $this->pushHistory($tenantId, $type, $layout, $user, 'edit');

            return response()->json(['success' => true, 'layout' => $layout]);
        });
    }

    /** DELETE /api/pdf/layout?type= — back to the built-in standard layout. */
    public function destroy(Request $request): JsonResponse
    {
        $type = (string) $request->query('type');
        if (!in_array($type, self::TYPES, true)) return response()->json(['error' => 'Ungültiger Dokumenttyp'], 422);
        if ($err = $this->denyWrite($request, [$type])) return $err;
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        return $this->locked($tenantId, $type, function () use ($tenantId, $type, $user) {
            $this->seedHistory($tenantId, $type, $this->read($tenantId, $type));
            Storage::disk('local')->delete($this->path($tenantId, $type));
            $this->pushHistory($tenantId, $type, null, $user, 'reset');
            return response()->json(['success' => true]);
        });
    }

    /** GET /api/pdf/layout/history?type= — Verlauf of a template (newest first, meta only). */
    public function history(Request $request): JsonResponse
    {
        $type = (string) $request->query('type');
        if (!in_array($type, self::TYPES, true)) return response()->json(['error' => 'Ungültiger Dokumenttyp'], 422);
        if ($err = $this->denyWrite($request, [$type])) return $err;
        $tenantId = (int) $request->user()->tenant_id;
        $entries = array_reverse($this->historyIndex($tenantId, $type));
        $names = $this->userNames($tenantId, array_column($entries, 'by'));
        foreach ($entries as &$e) $e['by_name'] = $names[$e['by'] ?? 0] ?? null;
        unset($e);
        return response()->json(['entries' => $entries, 'current' => $entries[0]['id'] ?? null]);
    }

    /** GET /api/pdf/layout/history/{id}?type= — one stored version (for the preview). */
    public function historyShow(Request $request, string $id): JsonResponse
    {
        $type = (string) $request->query('type');
        if (!in_array($type, self::TYPES, true) || !preg_match(self::HISTORY_ID_RE, $id)) return response()->json(['error' => 'Version nicht gefunden'], 404);
        if ($err = $this->denyWrite($request, [$type])) return $err;
        $snap = $this->readSnapshot((int) $request->user()->tenant_id, $type, $id);
        if ($snap === false) return response()->json(['error' => 'Version nicht gefunden'], 404);
        return response()->json(['id' => $id, 'layout' => $snap]);
    }

    /** POST /api/pdf/layout/restore — { type, id } makes a stored version the current template. */
    public function restore(Request $request): JsonResponse
    {
        $type = (string) $request->input('type');
        $id = (string) $request->input('id');
        if (!in_array($type, self::TYPES, true) || !preg_match(self::HISTORY_ID_RE, $id)) return response()->json(['error' => 'Version nicht gefunden'], 404);
        if ($err = $this->denyWrite($request, [$type])) return $err;
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        return $this->locked($tenantId, $type, function () use ($tenantId, $type, $id, $user) {
            $snap = $this->readSnapshot($tenantId, $type, $id);
            if ($snap === false) return response()->json(['error' => 'Version nicht gefunden'], 404);
            $this->seedHistory($tenantId, $type, $this->read($tenantId, $type));
            if ($snap === null) {
                Storage::disk('local')->delete($this->path($tenantId, $type));
                $layout = null;
            } else {
                $layout = $this->sanitizeLayout($snap);
                $layout['updated_at'] = now()->toIso8601String();
                $layout['updated_by'] = (int) $user->id;
                $this->writeAtomic($this->path($tenantId, $type), json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $this->pushHistory($tenantId, $type, $layout, $user, 'restore', $id);
            return response()->json(['success' => true, 'layout' => $layout]);
        });
    }

    /**
     * PUT /api/pdf/branding — company colours/font/logo position. Same
     * merge semantics as PUT /dashboard/settings, but gated like the
     * layout so Dokument-Design users (without users.manage) can save.
     */
    public function updateBranding(Request $request): JsonResponse
    {
        if ($err = $this->denyWrite($request, self::TYPES)) return $err;
        $request->validate([
            'pdf_branding'               => 'required|array',
            'pdf_branding.primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pdf_branding.accent_color'  => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pdf_branding.font_family'   => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9 ,\'"-]+$/'],
            'pdf_branding.font_size'     => 'nullable|string|in:small,medium,large',
            'pdf_branding.logo_position' => 'nullable|string|in:left,center,right',
        ]);

        $tenant = $request->user()->tenant;
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $existing = is_array($settings['pdf_branding'] ?? null) ? $settings['pdf_branding'] : [];
        foreach (['primary_color', 'accent_color', 'font_family', 'font_size', 'logo_position'] as $k) {
            $v = $request->input('pdf_branding.' . $k);
            if ($v !== null && $v !== '') $existing[$k] = $v;
        }
        $settings['pdf_branding'] = $existing;
        $tenant->settings = $settings;
        $tenant->save();

        return response()->json(['success' => true, 'pdf_branding' => $existing]);
    }

    /** POST /api/pdf/wizard-done — first-run design wizard completed (company-wide). */
    public function wizardDone(Request $request): JsonResponse
    {
        if ($err = $this->denyWrite($request, self::TYPES)) return $err;
        $tenant = $request->user()->tenant;
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['pdf_wizard_done'] = true;
        $tenant->settings = $settings;
        $tenant->save();
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────

    private function denyWrite(Request $request, array $types): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasPermission('system.dokument_design.manage')) return null;
        foreach ($types as $t) {
            if ($user->hasPermission(self::TYPE_EDIT_PERMISSION[$t])) return null;
        }
        return response()->json([
            'error'    => 'Sie haben keine Berechtigung, die Dokumentvorlage zu ändern.',
            'required' => 'system.dokument_design.manage',
        ], 403);
    }

    private function path(int $tenantId, string $type): string
    {
        return 'pdf_layouts/' . $tenantId . '/' . $type . '.json';
    }

    private function read(int $tenantId, string $type): ?array
    {
        $p = $this->path($tenantId, $type);
        if (!Storage::disk('local')->exists($p)) return null;
        $d = json_decode((string) Storage::disk('local')->get($p), true);
        return is_array($d) ? $d : null;
    }

    private function canRead($user, string $type): bool
    {
        return $user->hasPermission('system.dokument_design.manage')
            || $user->hasPermission(self::TYPE_EDIT_PERMISSION[$type])
            || $user->hasPermission(self::TYPE_VIEW_PERMISSION[$type]);
    }

    /** Serialises every write of one tenant/type (autosave, restore, reset). */
    private function locked(int $tenantId, string $type, callable $fn)
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('pdf_layouts/' . $tenantId);
        $fh = fopen($disk->path('pdf_layouts/' . $tenantId . '/.' . $type . '.lock'), 'c');
        if ($fh) flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
        }
    }

    /** Write-then-rename: a crash or parallel read never sees a half file. */
    private function writeAtomic(string $relPath, string $content): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($relPath));
        $full = $disk->path($relPath);
        $tmp = $full . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $full)) {
            @unlink($tmp);
            throw new \RuntimeException('Vorlage konnte nicht gespeichert werden');
        }
    }

    private function historyDir(int $tenantId, string $type): string
    {
        return 'pdf_layouts/' . $tenantId . '/history/' . $type;
    }

    /** [{id, at, by, reason, from?, size}] oldest first. */
    private function historyIndex(int $tenantId, string $type): array
    {
        $p = $this->historyDir($tenantId, $type) . '/index.json';
        if (!Storage::disk('local')->exists($p)) return [];
        $d = json_decode((string) Storage::disk('local')->get($p), true);
        return is_array($d) ? array_values(array_filter($d, fn ($e) => is_array($e) && preg_match(self::HISTORY_ID_RE, (string) ($e['id'] ?? '')))) : [];
    }

    /** Stored layout of a version: array, null (= built-in standard) or false (missing). */
    private function readSnapshot(int $tenantId, string $type, string $id)
    {
        if (!in_array($id, array_column($this->historyIndex($tenantId, $type), 'id'), true)) return false;
        $p = $this->historyDir($tenantId, $type) . '/' . $id . '.json';
        if (!Storage::disk('local')->exists($p)) return false;
        $d = json_decode((string) Storage::disk('local')->get($p), true);
        return is_array($d) && array_key_exists('layout', $d) && (is_array($d['layout']) || $d['layout'] === null) ? $d['layout'] : false;
    }

    /** Templates saved before the Verlauf existed: keep that state as the first entry. */
    private function seedHistory(int $tenantId, string $type, ?array $stored): void
    {
        if ($stored === null || $this->historyIndex($tenantId, $type)) return;
        $at = isset($stored['updated_at']) ? strtotime((string) $stored['updated_at']) : false;
        $this->addHistoryEntry($tenantId, $type, $stored, (int) ($stored['updated_by'] ?? 0) ?: null, 'initial', null, $at ?: time());
    }

    private function pushHistory(int $tenantId, string $type, ?array $layout, $user, string $reason, ?string $from = null): void
    {
        try {
            $idx = $this->historyIndex($tenantId, $type);
            $last = end($idx) ?: null;
            if ($reason === 'edit' && $last && ($last['reason'] ?? '') === 'edit' && (int) ($last['by'] ?? 0) === (int) $user->id
                && time() - (int) ($last['at'] ?? 0) < self::HISTORY_MERGE_SECONDS) {
                $this->addHistoryEntry($tenantId, $type, $layout, (int) $user->id, $reason, null, time(), $last['id']);
                return;
            }
            $this->addHistoryEntry($tenantId, $type, $layout, (int) $user->id, $reason, $from, time());
        } catch (\Throwable $e) {
            // The template itself is saved — a failing Verlauf must not break the editor.
            report($e);
        }
    }

    private function addHistoryEntry(int $tenantId, string $type, ?array $layout, ?int $by, string $reason, ?string $from, int $at, ?string $replaceId = null): void
    {
        $dir = $this->historyDir($tenantId, $type);
        $id = $replaceId ?? (date('YmdHis', $at) . '_' . strtolower(\Illuminate\Support\Str::random(6)));
        $json = json_encode(['layout' => $layout], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->writeAtomic($dir . '/' . $id . '.json', $json);
        $entry = ['id' => $id, 'at' => $at, 'by' => $by, 'reason' => $reason, 'size' => strlen($json)];
        if ($from) $entry['from'] = $from;
        $idx = $this->historyIndex($tenantId, $type);
        if ($replaceId) {
            foreach ($idx as $i => $e) if ($e['id'] === $replaceId) $idx[$i] = $entry;
        } else {
            $idx[] = $entry;
        }
        while (count($idx) > self::HISTORY_MAX) {
            $old = array_shift($idx);
            Storage::disk('local')->delete($dir . '/' . $old['id'] . '.json');
        }
        $this->writeAtomic($dir . '/index.json', json_encode(array_values($idx), JSON_UNESCAPED_SLASHES));
    }

    private function userNames(int $tenantId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        return \Illuminate\Support\Facades\DB::connection('mysql')->table('central_users')
            ->where('tenant_id', $tenantId)->whereIn('id', $ids)->pluck('full_name', 'id')->all();
    }

    private function sanitizeLayout(array $in): array
    {
        $out = ['v' => 1];

        $texts = [];
        foreach (array_slice((array) ($in['texts'] ?? []), 0, 300, true) as $key => $t) {
            if (!is_string($key) || !preg_match(self::KEY_RE, $key) || !is_array($t)) continue;
            $entry = [];
            if (array_key_exists('h', $t) && is_string($t['h'])) $entry['h'] = PdfHtmlSanitizer::html($t['h']);
            if (!empty($t['s']) && is_string($t['s'])) {
                $css = PdfHtmlSanitizer::css($t['s']);
                if ($css !== '') $entry['s'] = $css;
            }
            if ($entry) $texts[$key] = $entry;
        }
        $out['texts'] = (object) $texts;

        $out['hidden'] = array_values(array_unique(array_filter(
            array_slice((array) ($in['hidden'] ?? []), 0, 40),
            fn ($id) => is_string($id) && preg_match(self::KEY_RE, $id)
        )));
        // Colours/font/logo position of THIS document type (isolated from
        // the other types; tenant pdf_branding is only the fallback).
        $b = is_array($in['branding'] ?? null) ? $in['branding'] : [];
        $branding = [];
        foreach (['primary_color', 'accent_color'] as $k) {
            if (is_string($b[$k] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $b[$k])) $branding[$k] = $b[$k];
        }
        if (is_string($b['font_family'] ?? null) && strlen($b['font_family']) <= 120 && preg_match('/^[A-Za-z0-9 ,\'"-]+$/', $b['font_family'])) $branding['font_family'] = $b['font_family'];
        if (in_array($b['font_size'] ?? null, ['small', 'medium', 'large'], true)) $branding['font_size'] = $b['font_size'];
        if (in_array($b['logo_position'] ?? null, ['left', 'center', 'right'], true)) $branding['logo_position'] = $b['logo_position'];
        if ($branding) $out['branding'] = $branding;
        $out['show_zuschlaege'] = !array_key_exists('show_zuschlaege', $in) || (bool) $in['show_zuschlaege'];
        $out['hide_logo']       = !empty($in['hide_logo']);
        $out['extra_note']      = mb_substr(strip_tags((string) ($in['extra_note'] ?? '')), 0, 2000);
        // Standard template (Dokument-Design → Vorlage).
        $out['design'] = in_array($in['design'] ?? null, ['klassisch', 'modern', 'din5008'], true) ? $in['design'] : 'klassisch';
        // Textbausteine: plain-text slots printed on the PDF + the e-mail
        // template used when the document is sent. {{placeholders}} stay raw.
        $bs = [];
        $inBs = is_array($in['bausteine'] ?? null) ? $in['bausteine'] : [];
        foreach (['intro', 'closing'] as $slot) {
            $s = $inBs[$slot] ?? null;
            if (!is_array($s)) continue;
            $bs[$slot] = ['on' => !empty($s['on']), 'text' => mb_substr(strip_tags((string) ($s['text'] ?? '')), 0, 2000)];
        }
        $out['bausteine'] = (object) $bs;
        if (is_array($in['email'] ?? null)) {
            $m = $in['email'];
            $out['email'] = [
                'betreff'  => mb_substr(trim(strip_tags((string) ($m['betreff'] ?? ''))), 0, 200),
                'text'     => mb_substr(strip_tags((string) ($m['text'] ?? '')), 0, 5000),
                'signatur' => !array_key_exists('signatur', $m) || (bool) $m['signatur'],
            ];
        }

        $out['inserted'] = [];
        foreach (array_slice((array) ($in['inserted'] ?? []), 0, 60) as $el) {
            if (is_array($el) && ($clean = $this->sanitizeElement($el))) $out['inserted'][] = $clean;
        }

        $out['moved'] = [];
        foreach (array_slice((array) ($in['moved'] ?? []), 0, 40) as $m) {
            if (!is_array($m) || !is_string($m['key'] ?? null) || !preg_match(self::KEY_RE, $m['key'])) continue;
            $out['moved'][] = [
                'key'    => $m['key'],
                'x'      => $this->num($m['x'] ?? 0, -2000, 4000),
                'y'      => $this->num($m['y'] ?? 0, -2000, 20000),
                'width'  => $this->num($m['width'] ?? 0, 0, 4000),
                'zIndex' => (int) $this->num($m['zIndex'] ?? 100, 1, 100000),
            ] + $this->anchor($m);
        }

        $a = is_array($in['assets'] ?? null) ? $in['assets'] : [];
        $pos = fn ($p) => is_array($p) ? [
            'x' => $this->num($p['x'] ?? 0, -2000, 4000),
            'y' => $this->num($p['y'] ?? 0, -2000, 20000),
            'w' => $this->num($p['w'] ?? 120, 20, 2000),
            'r' => $this->num($p['r'] ?? 0, 0, 360),
        ] + $this->anchor($p) : null;
        $out['assets'] = [
            'show_signature' => !empty($a['show_signature']),
            'show_stamp'     => !empty($a['show_stamp']),
            'sig_pos'        => $pos($a['sig_pos'] ?? null),
            'stamp_pos'      => $pos($a['stamp_pos'] ?? null),
        ];

        return $out;
    }

    private function sanitizeElement(array $el): ?array
    {
        $type = $el['type'] ?? null;
        if (!in_array($type, ['line', 'rect', 'textbox', 'table', 'image'], true)) return null;

        $style = [];
        foreach ((array) ($el['style'] ?? []) as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z]{1,30}$/', $k)) continue;
            if (is_bool($v) || is_int($v) || is_float($v)) { $style[$k] = $v; continue; }
            if (is_string($v) && strlen($v) <= 120 && !preg_match('/url\s*\(|expression|javascript:|[<>{};\\\\]/i', $v)) $style[$k] = $v;
        }

        $content = null;
        $raw = $el['content'] ?? null;
        if ($type === 'textbox') {
            $content = PdfHtmlSanitizer::html(is_string($raw) ? $raw : '', 20000);
        } elseif ($type === 'table' && is_array($raw)) {
            $content = [];
            foreach (array_slice($raw, 0, 30) as $row) {
                $content[] = array_map(fn ($c) => mb_substr(strip_tags((string) $c), 0, 500), array_slice((array) $row, 0, 12));
            }
        } elseif ($type === 'image') {
            if (!is_string($raw) || !preg_match('#^data:image/(png|jpeg|jpg|webp|gif);base64,[A-Za-z0-9+/=]+$#', $raw)) return null;
            if (strlen($raw) > self::MAX_IMAGE_BYTES * 1.37) return null;
            $content = $raw;
        }

        return [
            'id'      => preg_match('/^fe_[a-z0-9]{1,20}$/', (string) ($el['id'] ?? '')) ? $el['id'] : 'fe_' . substr(md5(uniqid('', true)), 0, 8),
            'type'    => $type,
            'x'       => $this->num($el['x'] ?? 0, -2000, 4000),
            'y'       => $this->num($el['y'] ?? 0, -2000, 20000),
            'width'   => $this->num($el['width'] ?? 100, 1, 4000),
            'height'  => $this->num($el['height'] ?? 20, 1, 20000),
            'zIndex'  => (int) $this->num($el['zIndex'] ?? 100, 1, 100000),
            'style'   => (object) $style,
            'content' => $content,
            'anchor'  => is_array($el['anchor'] ?? null) && ($anc = $this->anchor($el['anchor'])) ? $anc : null,
        ];
    }

    /**
     * Flow anchor of a placed element: block key ("signatureSection#0",
     * "meta-row#1") + vertical offset to it — see FE.anchorFor in
     * order_form_elements.js. Returns [] when absent/invalid.
     */
    private function anchor(array $p): array
    {
        $key = $p['a'] ?? null;
        if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,60}#[0-9]{1,3}$/', $key) || !is_numeric($p['dy'] ?? null)) return [];
        return ['a' => $key, 'dy' => $this->num($p['dy'], -20000, 20000)];
    }

    private function num($v, float $min, float $max): float
    {
        $n = is_numeric($v) ? (float) $v : 0.0;
        return round(max($min, min($max, $n)), 2);
    }
}
