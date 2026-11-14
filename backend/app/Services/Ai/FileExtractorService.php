<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiClientInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls plain text out of an uploaded file so the LLM has something to
 * read. Routes per MIME:
 *
 *   PDF  → pdftotext (poppler-utils, /usr/bin/pdftotext, locally on VPS)
 *   IMG  → LightOnOCR via IONOS AI Model Hub (HTTP)
 *   TXT  → stream straight through
 *
 * The output is intentionally raw — formatting, layout and OCR noise
 * are preserved so the downstream LLM call (structured-extraction
 * prompt) can decide which bits are signal.
 *
 * Limits & safety
 * ---------------
 *  - Files are validated for MIME and max size BEFORE we run any
 *    extractor (the controller does this). This service just trusts
 *    its inputs and focuses on extraction.
 *  - PDF extraction runs in a 20s subprocess — a malicious PDF that
 *    causes pdftotext to spin will be killed.
 *  - We cap returned text at 60_000 characters to keep LLM cost
 *    bounded; longer files get truncated with a clear marker.
 */
class FileExtractorService
{
    /** Hard ceiling on extracted text length (characters). */
    public const MAX_CHARS = 60_000;

    /** Maximum seconds pdftotext is allowed to run. */
    public const PDF_TIMEOUT = 20;

    /**
     * Extract text from a stored file.
     *
     * @param string $diskPath  Path on the configured disk (e.g.
     *                          'ai-uploads/{tenant}/{uuid}.pdf')
     * @param string $disk      Storage disk name (default: 'local')
     * @param string $mime      Detected MIME type
     * @return array {
     *     text: string,
     *     extractor: 'pdftotext'|'ocr'|'plain',
     *     truncated: bool,
     *     pages: ?int,           // PDF only
     *     model_used: ?string,   // OCR only
     * }
     */
    public function extract(string $diskPath, string $disk, string $mime): array
    {
        // Resolve to an absolute filesystem path; pdftotext + OCR both
        // need a real path / file contents, not a stream.
        $absolute = Storage::disk($disk)->path($diskPath);

        if (!is_file($absolute)) {
            throw new \RuntimeException("File not found on disk: {$diskPath}");
        }

        if (str_contains($mime, 'pdf')) {
            return $this->extractPdf($absolute);
        }

        if (str_starts_with($mime, 'image/')) {
            return $this->extractImage($absolute, $mime);
        }

        // CSV — read raw, but also produce a normalised markdown
        // table so the LLM consistently sees columns/values regardless
        // of which separator (`,` or `;`) Excel produced.
        $lower = strtolower($absolute);
        if ($mime === 'text/csv'
            || $mime === 'application/csv'
            || ($mime === 'application/vnd.ms-excel' && str_ends_with($lower, '.csv'))
            || str_ends_with($lower, '.csv')) {
            return $this->extractCsv($absolute);
        }

        // XLSX / XLS — parsed via PhpSpreadsheet, then converted to a
        // markdown table just like CSV so the LLM sees a uniform shape
        // regardless of the user's source format.
        if ($mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || $mime === 'application/vnd.ms-excel'
            || str_ends_with($lower, '.xlsx')
            || str_ends_with($lower, '.xls')) {
            return $this->extractSpreadsheet($absolute);
        }

        if (str_starts_with($mime, 'text/') || $mime === 'application/json') {
            $raw = (string) file_get_contents($absolute);
            return $this->packResult($raw, 'plain');
        }

        throw new \RuntimeException("Unsupported file type for extraction: {$mime}");
    }

    /**
     * CSV → markdown table.
     *
     * We read the file with `fgetcsv()` so quoted fields containing
     * commas/semicolons are handled correctly, and auto-detect the
     * delimiter from the first non-empty line. Excel on German Windows
     * defaults to `;`, US Excel uses `,`, so we accept both.
     *
     * The output is a markdown table, which the downstream LLM can
     * parse far more reliably than raw CSV with quoting and escapes.
     */
    protected function extractCsv(string $absolute): array
    {
        $raw = file_get_contents($absolute);
        if ($raw === false) {
            throw new \RuntimeException('CSV-Datei konnte nicht gelesen werden.');
        }

        // Strip UTF-8 BOM if present (Excel writes one).
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }
        // Normalise newlines.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Auto-detect delimiter from the first non-empty line.
        $firstLine = '';
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) !== '') { $firstLine = $line; break; }
        }
        $delimiter = ',';
        $semis = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        $tabs   = substr_count($firstLine, "\t");
        if ($tabs > $semis && $tabs > $commas) {
            $delimiter = "\t";
        } elseif ($semis > $commas) {
            $delimiter = ';';
        }

        // Parse all rows.
        $tmp = fopen('php://memory', 'r+');
        if ($tmp === false) throw new \RuntimeException('CSV-Parser konnte nicht initialisiert werden.');
        fwrite($tmp, $raw);
        rewind($tmp);

        $rows = [];
        while (($r = fgetcsv($tmp, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv returns a one-element array of NULL for blank lines
            if ($r === [null] || $r === false) continue;
            $rows[] = $r;
        }
        fclose($tmp);

        if (count($rows) === 0) {
            throw new \RuntimeException('CSV-Datei ist leer.');
        }

        // Build a markdown table — header = first row, rest = data.
        $header = array_map(fn($c) => trim((string)$c), $rows[0]);
        $colCount = count($header);
        $md  = '| ' . implode(' | ', array_map(fn($h) => $h !== '' ? $h : '—', $header)) . " |\n";
        $md .= '|' . str_repeat(' --- |', $colCount) . "\n";

        $dataRows = array_slice($rows, 1);
        foreach ($dataRows as $r) {
            // Pad / truncate to header length so the table stays valid.
            $r = array_pad(array_slice($r, 0, $colCount), $colCount, '');
            $cells = array_map(function ($v) {
                $v = trim((string)$v);
                // Escape pipe and newline so they don't break the table.
                $v = str_replace(['|', "\n"], ['\\|', ' '], $v);
                return $v === '' ? '—' : $v;
            }, $r);
            $md .= '| ' . implode(' | ', $cells) . " |\n";
        }

        return $this->packResult($md, 'csv', [
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'rows'      => count($dataRows),
            'columns'   => $colCount,
        ]);
    }

    /**
     * XLSX / XLS → markdown table via PhpSpreadsheet.
     *
     * We load only the first worksheet — Excel workbooks with multiple
     * sheets are uncommon for the bulk-import flow, and even when they
     * exist the user normally puts the data on the first one. Cells
     * are read with `getCalculatedValue()` so formula cells produce
     * their result rather than the raw `=A1+B1` literal.
     *
     * Memory cap: PhpSpreadsheet loads the whole workbook into memory.
     * We rely on the upstream upload limit (10 MB) to keep this
     * bounded; a 10 MB XLSX is far beyond the 200-row import cap.
     */
    protected function extractSpreadsheet(string $absolute): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException(
                'XLSX-Unterstützung ist nicht installiert. Bitte CSV verwenden oder den Administrator kontaktieren.'
            );
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($absolute);
            $reader->setReadDataOnly(true);   // skip styles → faster + less memory
            $spreadsheet = $reader->load($absolute);
        } catch (\Throwable $e) {
            Log::warning('ai.extractor.xlsx_failed', [
                'path'  => $absolute,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                'Excel-Datei konnte nicht gelesen werden: ' . $e->getMessage()
            );
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, false, false);

        // Drop trailing entirely-empty rows that Excel keeps around.
        while (!empty($rows) && $this->rowIsEmpty(end($rows))) {
            array_pop($rows);
        }
        if (empty($rows)) {
            throw new \RuntimeException('Excel-Datei ist leer.');
        }

        $header   = array_map(fn($c) => trim((string)($c ?? '')), $rows[0]);
        $colCount = count($header);
        $md  = '| ' . implode(' | ', array_map(fn($h) => $h !== '' ? $h : '—', $header)) . " |\n";
        $md .= '|' . str_repeat(' --- |', $colCount) . "\n";

        $dataRows = array_slice($rows, 1);
        foreach ($dataRows as $r) {
            $r = array_pad(array_slice($r, 0, $colCount), $colCount, '');
            $cells = array_map(function ($v) {
                $v = trim((string)($v ?? ''));
                $v = str_replace(['|', "\n"], ['\\|', ' '], $v);
                return $v === '' ? '—' : $v;
            }, $r);
            $md .= '| ' . implode(' | ', $cells) . " |\n";
        }

        // Free the spreadsheet handles ASAP — under Octane the worker
        // is long-lived and these objects can hold tens of MB.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->packResult($md, 'xlsx', [
            'rows'    => count($dataRows),
            'columns' => $colCount,
            'sheet'   => $sheet->getTitle(),
        ]);
    }

    /** True when every cell of the row is null/empty/whitespace. */
    private function rowIsEmpty(array $r): bool
    {
        foreach ($r as $c) {
            if ($c !== null && trim((string)$c) !== '') return false;
        }
        return true;
    }

    /** PDF → text via poppler's pdftotext.
     *
     * We render the document with TWO different pdftotext strategies and
     * concatenate them, because each one solves a different failure mode
     * we've hit in production:
     *
     *   • `-layout` keeps the visual table grid (good for the LLM to
     *     understand what is a column header vs a value), BUT cells that
     *     wrap across 2-3 lines blur into the next row, so the LLM often
     *     misses repeated date values (Fr 29.05.26 appearing on row 5
     *     and row 6 looks like ONE date to it).
     *
     *   • `-raw` keeps every text fragment in content-stream order, one
     *     per line. The table structure is gone, but every date /
     *     time / artikel string is on its own line, which is much
     *     easier for the LLM to tokenise unambiguously.
     *
     * Sending both gives the model a "cross-check" — when the layout
     * view is ambiguous it can fall back to the raw view (and the
     * system prompt explicitly tells it to do so).
     */
    protected function extractPdf(string $absolute): array
    {
        $layout = Process::timeout(self::PDF_TIMEOUT)
            ->run(['/usr/bin/pdftotext', '-layout', '-enc', 'UTF-8', $absolute, '-']);
        if ($layout->failed()) {
            Log::warning('ai.extractor.pdf_failed', [
                'path'  => $absolute,
                'code'  => $layout->exitCode(),
                'error' => $layout->errorOutput(),
            ]);
            throw new \RuntimeException(
                'PDF konnte nicht gelesen werden. Möglicherweise ist die Datei beschädigt oder geschützt.'
            );
        }
        $layoutText = $layout->output();

        // -raw is best-effort. If it fails (it shouldn't on a PDF that
        // -layout just succeeded on), we silently keep only the layout
        // view rather than blowing up the whole upload.
        $rawText = '';
        try {
            $raw = Process::timeout(self::PDF_TIMEOUT)
                ->run(['/usr/bin/pdftotext', '-raw', '-enc', 'UTF-8', $absolute, '-']);
            if ($raw->successful()) $rawText = $raw->output();
        } catch (\Throwable $e) {
            Log::info('ai.extractor.pdf_raw_skipped', ['error' => $e->getMessage()]);
        }

        $combined = "=== TABELLEN-LAYOUT (Spalten visuell ausgerichtet) ===\n"
                  . $layoutText;
        if ($rawText !== '' && trim($rawText) !== trim($layoutText)) {
            $combined .= "\n\n=== REIHENFOLGE-ANSICHT (jede Zelle in einer Zeile, "
                       . "zur Gegenprüfung) ===\n" . $rawText;
        }

        $pages = $this->countPdfPages($absolute);
        return $this->packResult($combined, 'pdftotext', ['pages' => $pages]);
    }

    /**
     * Image → text via a multimodal LLM call.
     *
     * Per-provider behaviour
     * ----------------------
     *  - `provider=gemini`: Gemini 2.5 Flash is natively multimodal, so
     *    we just hand the image to its default chat model. No special
     *    model override needed.
     *  - `provider=groq`  : IONOS Llama is text-only, so we route to
     *    the dedicated OCR model named by `AI_OCR_MODEL` (typically
     *    `lightonai/LightOnOCR-2-1B`). If neither the chat model nor
     *    the OCR model is multimodal the API returns a clear error
     *    which we surface to the user.
     */
    protected function extractImage(string $absolute, string $mime): array
    {
        $bytes = file_get_contents($absolute);
        if ($bytes === false || strlen($bytes) === 0) {
            throw new \RuntimeException('Bilddatei ist leer.');
        }

        $b64 = base64_encode($bytes);
        $dataUrl = "data:{$mime};base64,{$b64}";

        // Choose the model. Gemini doesn't need an override (its default
        // is multimodal); only Groq/IONOS needs the OCR detour.
        $provider  = (string) config('ai.provider', 'groq');
        $modelOpt  = $provider === 'gemini'
            ? []                                                  // use Vertex default (gemini-2.5-flash)
            : ['model' => config('ai.ocr_model') ?: config('ai.model')];

        $instruction = 'Extrahiere den gesamten sichtbaren Text aus dem Bild. Antworte NUR mit dem Text, keine Erklärungen.';
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $instruction],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ]];

        $client = app(AiClientInterface::class);

        try {
            $resp = $client->chat($messages, [], $modelOpt + [
                'max_tokens' => 2000, // OCR can need more tokens for dense docs
            ]);
            $text = (string) ($resp['message']['content'] ?? '');

            // LightOnOCR sometimes echoes the instruction at the start of
            // its response — strip that so downstream extraction sees only
            // the actual OCR'd text. Harmless no-op for Gemini.
            $text = preg_replace(
                '/^.*?extrahiere.*?erklärungen\.\s*/iu',
                '',
                trim($text)
            ) ?? $text;
            $text = trim((string) $text);

            if ($text === '') {
                throw new \RuntimeException('Das Bild enthält keinen lesbaren Text oder die Schrift ist zu unscharf.');
            }

            return $this->packResult($text, 'ocr', [
                'model_used' => $modelOpt['model'] ?? config('ai.gemini.model'),
                'provider'   => $provider,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai.extractor.image_failed', [
                'mime'     => $mime,
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);
            // Detect the "model is not multimodal" error (IONOS Llama)
            // and give a friendlier hint.
            if (str_contains($e->getMessage(), 'not a multimodal')) {
                throw new \RuntimeException(
                    'Bild kann mit dem aktuellen Modell nicht verarbeitet werden. '
                    . 'Bitte AI_OCR_MODEL=lightonai/LightOnOCR-2-1B setzen oder '
                    . 'AI_PROVIDER=gemini wechseln.'
                );
            }
            throw new \RuntimeException(
                'Bild konnte nicht gelesen werden: ' . $e->getMessage()
            );
        }
    }

    /** Best-effort PDF page count via pdfinfo (also from poppler-utils). */
    protected function countPdfPages(string $absolute): ?int
    {
        try {
            $r = Process::timeout(5)->run(['/usr/bin/pdfinfo', $absolute]);
            if ($r->successful() && preg_match('/^Pages:\s+(\d+)/m', $r->output(), $m)) {
                return (int) $m[1];
            }
        } catch (\Throwable) {
            // pdfinfo missing is non-fatal — page count is just metadata.
        }
        return null;
    }

    /** Truncate to MAX_CHARS, return the standard envelope. */
    protected function packResult(string $text, string $extractor, array $extra = []): array
    {
        $trimmed = trim($text);
        $truncated = false;
        if (mb_strlen($trimmed) > self::MAX_CHARS) {
            $trimmed = mb_substr($trimmed, 0, self::MAX_CHARS)
                . "\n\n[…Text bei " . self::MAX_CHARS . " Zeichen abgeschnitten…]";
            $truncated = true;
        }
        return array_merge([
            'text'      => $trimmed,
            'extractor' => $extractor,
            'truncated' => $truncated,
        ], $extra);
    }

    /**
     * Stash an UploadedFile into a per-tenant ai-uploads folder using a
     * UUID filename. The original filename is preserved alongside in a
     * `.meta` JSON sidecar for audit purposes.
     *
     * @return array { disk_path, disk, original_name, mime, size }
     */
    public function store(UploadedFile $file, string $tenantSlug): array
    {
        $disk     = config('filesystems.default', 'local');
        $ext      = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $safeSlug = preg_replace('/[^a-z0-9_-]/i', '_', $tenantSlug);
        $uuid     = (string) Str::uuid();
        $path     = "ai-uploads/{$safeSlug}/{$uuid}.{$ext}";

        Storage::disk($disk)->putFileAs(
            "ai-uploads/{$safeSlug}",
            $file,
            "{$uuid}.{$ext}"
        );

        // Sidecar metadata for the cleanup job + audit log
        Storage::disk($disk)->put("{$path}.meta", json_encode([
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getMimeType(),
            'size'          => $file->getSize(),
            'uploaded_at'   => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));

        return [
            'disk_path'     => $path,
            'disk'          => $disk,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => (string) $file->getMimeType(),
            'size'          => (int) $file->getSize(),
        ];
    }

    /** Delete an uploaded file + its sidecar after we're done with it. */
    public function delete(string $diskPath, string $disk = 'local'): void
    {
        Storage::disk($disk)->delete([$diskPath, "{$diskPath}.meta"]);
    }
}
