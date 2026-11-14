<?php

namespace App\Services\Ai\Tools\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for all AI tool providers.
 *
 * Anything the LLM needs across more than one domain lives here:
 * schema building, date/time normalisation, fuzzy ID resolution,
 * small input accessors. Domain-private helpers stay in their own
 * provider class.
 */
trait ToolHelpers
{
    /** Build an OpenAI-format function-tool schema.
     *
     * Note on the empty-properties cast: JSON Schema requires `properties`
     * to be a JSON object. PHP's json_encode() serialises an empty PHP
     * array as `[]` (JSON array) rather than `{}` (JSON object), which
     * makes Groq's schema validator reject the request with
     * "expected object, but got array". Casting to stdClass forces
     * json_encode() to emit `{}` for the empty case.
     */
    private function fn(string $name, string $desc, array $props, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $name,
                'description' => $desc,
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => empty($props) ? (object) [] : $props,
                    'required'   => $required,
                ],
            ],
        ];
    }

    /**
     * Resolves and validates a {from,to} date range. Falls back to the
     * current month if either is missing or malformed. Clamps the range
     * to max 2 years so a hallucinated "from=1900" can't trigger a
     * full-table scan.
     */
    private function dateRange(array $a): array
    {
        $from = $a['from'] ?? null;
        $to   = $a['to']   ?? null;
        if (!$from || !strtotime($from)) $from = date('Y-m-01');
        if (!$to   || !strtotime($to))   $to   = date('Y-m-d');
        if (strtotime($from) > strtotime($to)) {
            // Swap if reversed
            [$from, $to] = [$to, $from];
        }
        // Clamp window to 2 years
        if ((strtotime($to) - strtotime($from)) > (2 * 365 * 86400)) {
            $from = date('Y-m-d', strtotime($to . ' -2 years'));
        }
        return [date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to))];
    }

    /**
     * Normalises a free-form time string ("7", "7:00", "7 Uhr", "07:00:00",
     * "07.00") into the canonical "HH:MM" used by MySQL TIME comparisons.
     * Returns null when the input is empty or unparseable so the caller can
     * treat the missing-time case explicitly.
     */
    private function normalizeTime(?string $t): ?string
    {
        if ($t === null) return null;
        $t = trim($t);
        if ($t === '') return null;

        // Strip "Uhr"/"AM"/"PM" suffixes the LLM might pass through.
        $t = preg_replace('/\s*(Uhr|uhr|UHR|AM|PM|am|pm)\s*/u', '', $t) ?? $t;
        // Allow "7.30" → "7:30".
        $t = str_replace('.', ':', $t);

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?$/', $t, $m)) {
            $h = max(0, min(23, (int) $m[1]));
            $i = isset($m[2]) ? max(0, min(59, (int) $m[2])) : 0;
            return sprintf('%02d:%02d', $h, $i);
        }
        return null;
    }

    /**
     * Maps common Bereich aliases (Arabic / English / shorthand) onto the
     * canonical German terms typically used in the `mitarbeiter.bereich`
     * column. Returns the list of search tokens to OR-LIKE against — so a
     * user asking "حماية" still finds rows with bereich="Sicherheit" or
     * "Schutz". Empty input → empty list (no filter).
     */
    private function bereichSynonyms(string $term): array
    {
        $term = trim($term);
        if ($term === '') return [];

        $lower = mb_strtolower($term, 'UTF-8');
        // Each entry: any one keyword on the LHS triggers all the RHS
        // tokens being searched for. The user's raw term is always
        // included so explicit values like "Verwaltung" pass through.
        $groups = [
            ['keywords' => ['حماي', 'حراس', 'security', 'guard', 'wach',  'schutz', 'sicher'],
             'tokens'   => ['Sicherheit', 'Schutz', 'Wach']],
            ['keywords' => ['نظاف', 'تنظيف', 'clean', 'reinig', 'putz'],
             'tokens'   => ['Reinigung', 'Reiniger']],
            ['keywords' => ['اداري', 'إداري', 'admin', 'office', 'verwaltung', 'büro', 'buero'],
             'tokens'   => ['Verwaltung', 'Büro']],
            ['keywords' => ['logistik', 'لوجيست', 'warehouse', 'lager'],
             'tokens'   => ['Logistik', 'Lager']],
            ['keywords' => ['fahrer', 'driver', 'سائق'],
             'tokens'   => ['Fahrer']],
        ];

        $out = [$term]; // user's literal term always included
        foreach ($groups as $g) {
            foreach ($g['keywords'] as $kw) {
                if (mb_strpos($lower, $kw) !== false) {
                    $out = array_merge($out, $g['tokens']);
                    break;
                }
            }
        }
        // De-duplicate (case-insensitive)
        $seen = [];
        $unique = [];
        foreach ($out as $t) {
            $k = mb_strtolower($t, 'UTF-8');
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $unique[] = $t;
            }
        }
        return $unique;
    }

    /**
     * Token-aware LIKE search.
     *
     * Splits the query on whitespace and requires EVERY token to appear in
     * the provided SQL haystack expression. Makes "Sahil Ahmadi" match a
     * row stored as (vorname='Sahil', name='Ahmadi').
     *
     * @return array{0:string,1:array}
     */
    private function tokenizedLike(string $haystackSql, string $query): array
    {
        $tokens = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [$query];
        $conds  = [];
        $params = [];
        foreach ($tokens as $tok) {
            $conds[]  = "{$haystackSql} LIKE ?";
            $params[] = '%' . $tok . '%';
        }
        return [implode(' AND ', $conds), $params];
    }

    /**
     * Resolve a foreign-key style ID (kunde/objekt/mitarbeiter/nu) the
     * LLM proposed back to a row that actually exists in this tenant's
     * DB.
     *
     * Why: the model frequently mangles ID formats — adds a hyphen
     * where there isn't one (sends `K-028` when the real key is
     * `K028`), strips one that should be there, or invents an ID
     * outright from a customer name. Failing such writes with a
     * useful error message (rather than a bare "Kunde not found")
     * is the difference between the assistant gracefully
     * recovering ("Did you mean PSK Sicherheit GmbH (K028)?") and
     * looping.
     *
     * Strategy:
     *   1. Try the input verbatim.
     *   2. Try the input with hyphens stripped (`K-028` → `K028`).
     *   3. Try the input with a hyphen reinserted after the alpha
     *      prefix if missing (`A001` → `A-001`).
     *   4. Give up and throw with up to 3 search suggestions.
     *
     * @param  string  $table       e.g. 'kunden'
     * @param  string  $idColumn    e.g. 'kunde_id'
     * @param  string  $nameColumn  e.g. 'kundenname' — used for the
     *                              "did you mean" suggestion list.
     * @param  string  $input       What the LLM sent.
     * @param  string  $label       Human label for error messages.
     * @return object               The matched DB row.
     */
    private function resolveExistingId(
        string $table,
        string $idColumn,
        string $nameColumn,
        string $input,
        string $label
    ): object {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException("{$label}-ID ist Pflicht.");
        }

        // Build candidate ID variants — most likely first.
        $candidates = [$input];
        if (str_contains($input, '-')) {
            $candidates[] = str_replace('-', '', $input);
        } else if (preg_match('/^([A-Za-z]+)(\d+)$/', $input, $m)) {
            $candidates[] = $m[1] . '-' . $m[2];
        }
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            $row = DB::connection('tenant')->table($table)
                ->where($idColumn, $candidate)->first();
            if ($row) return $row;
        }

        // Nothing matched — fetch up to 3 suggestions by name LIKE so
        // the AI can ask the user to disambiguate. We deliberately
        // strip the alpha prefix from the input before searching, so
        // "K-10001" won't drag the search astray.
        $needle = preg_replace('/^[A-Za-z]+-?/', '', $input);
        $suggestions = [];
        if ($needle !== '' && $needle !== $input) {
            $rows = DB::connection('tenant')->table($table)
                ->where($nameColumn, 'LIKE', '%' . $needle . '%')
                ->orderBy($nameColumn)->limit(3)->get([$idColumn, $nameColumn]);
            foreach ($rows as $r) {
                $suggestions[] = $r->{$idColumn} . ' (' . $r->{$nameColumn} . ')';
            }
        }

        $msg = "{$label} '{$input}' wurde nicht gefunden.";
        if (!empty($suggestions)) {
            $msg .= ' Meinst du: ' . implode(', ', $suggestions) . '?';
        } else {
            $msg .= " Bitte rufe zuerst search_{$label} mit dem Namen auf, um die korrekte ID zu finden.";
        }
        throw new \InvalidArgumentException($msg);
    }

    private function str(array $a, string $k): ?string
    {
        $v = $a[$k] ?? null;
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    private function date(array $a, string $k): ?string
    {
        $v = $this->str($a, $k);
        if (!$v) return null;
        // Accept YYYY-MM-DD or DD.MM.YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v)) return $v;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $v, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
        throw new \InvalidArgumentException("Ungültiges Datumsformat für '{$k}': {$v} (erwartet YYYY-MM-DD)");
    }

    private function joinAddress(array $a): string
    {
        $parts = [
            $this->str($a, 'strasse'),
            trim(($this->str($a, 'plz') ?? '') . ' ' . ($this->str($a, 'ort') ?? '')),
        ];
        $parts = array_filter($parts, fn($p) => $p !== null && trim((string)$p) !== '');
        return $parts ? implode(', ', $parts) : '—';
    }

    private function generatePassword(int $len = 12): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    private function normaliseTime($v): ?string
    {
        if ($v === null || $v === '') return null;
        $v = trim((string)$v);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
            return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $v, $m)) {
            return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        throw new \InvalidArgumentException("Ungültiges Zeitformat: {$v} (erwartet HH:MM)");
    }
}
