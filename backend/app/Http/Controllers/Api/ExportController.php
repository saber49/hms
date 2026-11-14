<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\XlsxTable;
use Illuminate\Http\Request;

/**
 * Generic table export — the client POSTs the exact rows it already
 * rendered on screen (filtered/sorted view = WYSIWYG export) and gets
 * back a real .xlsx workbook.
 *
 * Why not CSV? CSV exports lived client-side and broke visually for
 * anyone whose Excel/Numbers locale is not German (delimiter guesswork,
 * decimal "7.99" read as 799 under de-DE, umlauts without BOM handling).
 * A real workbook has typed cells — no delimiter, no encoding roulette,
 * and numbers sort/sum correctly on every viewer.
 */
class ExportController extends Controller
{
    private const MAX_ROWS = 30000;
    private const MAX_COLS = 60;
    private const MAX_SHEETS = 4;

    public function xlsxTable(Request $request)
    {
        $payload = $request->validate([
            'title'     => 'nullable|string|max:120',
            'filename'  => 'nullable|string|max:120',
            'headers'   => 'required|array|min:1|max:' . self::MAX_COLS,
            'headers.*' => 'nullable|string|max:200',
            'rows'      => 'required|array|max:' . self::MAX_ROWS,
            'rows.*'    => 'array|max:' . self::MAX_COLS,
            // Optional further sheets (e.g. Monatsabschluss per-worker totals).
            'sheets'               => 'nullable|array|max:' . self::MAX_SHEETS,
            'sheets.*.title'       => 'nullable|string|max:120',
            'sheets.*.headers'     => 'required|array|min:1|max:' . self::MAX_COLS,
            'sheets.*.headers.*'   => 'nullable|string|max:200',
            'sheets.*.rows'        => 'nullable|array|max:' . self::MAX_ROWS,
            'sheets.*.rows.*'      => 'array|max:' . self::MAX_COLS,
            'sheets.*.bold_rows'   => 'nullable|array|max:' . self::MAX_ROWS,
            'sheets.*.bold_rows.*' => 'integer|min:0',
            // Optional presentation (see XlsxTable::fill): first sheet + per sheet.
            'layout'               => 'nullable|array',
            'sheets.*.layout'      => 'nullable|array',
        ]);

        $cells = fn(array $rows) => array_map(
            fn($r) => array_map(fn($c) => is_scalar($c) || $c === null ? $c : (string) json_encode($c), $r),
            $rows
        );
        $headers = array_map(fn($h) => (string) ($h ?? ''), $payload['headers']);
        $rows    = $cells($payload['rows']);
        $title    = trim((string) ($payload['title'] ?? '')) ?: 'Export';
        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($payload['filename'] ?? 'export'));
        $filename = trim(preg_replace('/\.xlsx$/i', '', $filename), '._') ?: 'export';
        $sheets = array_map(fn($s) => [
            'title'     => trim((string) ($s['title'] ?? '')) ?: 'Blatt',
            'headers'   => array_map(fn($h) => (string) ($h ?? ''), $s['headers']),
            'rows'      => $cells($s['rows'] ?? []),
            'bold_rows' => array_map('intval', $s['bold_rows'] ?? []),
            'layout'    => self::layout($s['layout'] ?? null),
        ], $payload['sheets'] ?? []);

        $bin = XlsxTable::build($title, $headers, $rows, $sheets, self::layout($payload['layout'] ?? null));

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.xlsx"',
            'Cache-Control'       => 'no-store, no-cache, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Whitelisted, size-capped layout options — anything else is dropped. */
    private static function layout($l): array
    {
        if (!is_array($l)) return [];
        $ints = fn ($v, int $max) => array_values(array_slice(array_filter(array_map(
            fn ($x) => is_numeric($x) ? (int) $x : -1, is_array($v) ? $v : []), fn ($x) => $x >= 0), 0, $max));
        $out = [
            'group_rows' => $ints($l['group_rows'] ?? [], self::MAX_ROWS),
            'sum_rows'   => $ints($l['sum_rows'] ?? [], self::MAX_ROWS),
            'head_rows'  => $ints($l['head_rows'] ?? [], self::MAX_ROWS),
            'num_cols'   => $ints($l['num_cols'] ?? [], self::MAX_COLS),
            'int_cols'   => $ints($l['int_cols'] ?? [], self::MAX_COLS),
            'widths'     => array_map(fn ($w) => is_numeric($w) ? max(0, min(80, (float) $w)) : 0,
                array_slice(is_array($l['widths'] ?? null) ? array_values($l['widths']) : [], 0, self::MAX_COLS)),
            'autofilter' => !empty($l['autofilter']),
            'landscape'  => !empty($l['landscape']),
            'title_rows' => array_map(
                fn ($r) => array_map(fn ($c) => mb_substr(is_scalar($c) ? (string) $c : '', 0, 300), array_slice(array_values($r), 0, self::MAX_COLS)),
                array_slice(array_values(array_filter(is_array($l['title_rows'] ?? null) ? $l['title_rows'] : [], 'is_array')), 0, 10)),
        ];
        // An empty array would switch the styled mode on for nothing.
        return array_filter($out, fn ($v) => $v !== [] && $v !== false) ? $out : [];
    }
}
