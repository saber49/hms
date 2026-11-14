<?php

namespace App\Services\Ai\Tools\Read;

use App\Services\Ai\Tools\Concerns\ToolHelpers;
use App\Services\Ai\Tools\Contracts\ReadToolsProvider;
use App\Support\BereichScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ExportTools implements ReadToolsProvider
{
    use ToolHelpers;

    public function handles(string $name): bool
    {
        return match ($name) {
            'export_einsaetze_csv' => true,
            'export_bestelleingang_csv' => true,
            default => false,
        };
    }

    public function schemas(): array
    {
        return [
            $this->fn('export_einsaetze_csv',
                'Exportiert Einsätze als Datei zum Download. Standard ist Excel (.xlsx) mit '
                . 'formatierten Spalten und eingefrorener Kopfzeile; mit format="csv" gibt es '
                . 'stattdessen eine UTF-8 CSV mit Semikolon-Trennung. Zeitraum mit "from"/"to". '
                . 'Optional Filter nach Status, Kunde, Objekt, Nachunternehmer. Liefert eine '
                . 'Download-URL und die Anzahl der Zeilen zurück.',
                [
                    'from'      => ['type' => 'string', 'description' => 'Pflicht — YYYY-MM-DD'],
                    'to'        => ['type' => 'string', 'description' => 'Pflicht — YYYY-MM-DD'],
                    'format'    => ['type' => 'string', 'enum' => ['xlsx', 'csv'], 'description' => 'optional, default xlsx'],
                    'status'    => ['type' => 'string', 'description' => 'optional — "ja" / "nein" / "ausstehend"'],
                    'kunden_id' => ['type' => 'string', 'description' => 'optional'],
                    'obj_id'    => ['type' => 'string', 'description' => 'optional'],
                    'nu_id'     => ['type' => 'string', 'description' => 'optional'],
                ], ['from', 'to']),

            $this->fn('export_bestelleingang_csv',
                'Exportiert eingehende Aufträge (Bestelleingang) als Datei zum Download. '
                . 'Standard ist Excel (.xlsx); mit format="csv" UTF-8 mit Semikolon. '
                . 'Filter: monat/jahr ODER from/to (Erstellungsdatum), Status, Kunde, Objekt, NU. '
                . 'Status-Werte sind typischerweise "unbearbeitet" / "in_bearbeitung" / "abgeschlossen". '
                . 'Liefert Download-URL + Anzahl Zeilen.',
                [
                    'monat'     => ['type' => 'integer', 'description' => 'optional — 1-12'],
                    'jahr'      => ['type' => 'integer', 'description' => 'optional — z.B. 2026'],
                    'from'      => ['type' => 'string',  'description' => 'optional — YYYY-MM-DD (created_at)'],
                    'to'        => ['type' => 'string',  'description' => 'optional — YYYY-MM-DD (created_at)'],
                    'format'    => ['type' => 'string',  'enum' => ['xlsx', 'csv'], 'description' => 'optional, default xlsx'],
                    'status'    => ['type' => 'string',  'description' => 'optional'],
                    'kunden_id' => ['type' => 'string',  'description' => 'optional'],
                    'obj_id'    => ['type' => 'string',  'description' => 'optional'],
                    'nu_id'     => ['type' => 'string',  'description' => 'optional'],
                ], []),
        ];
    }

    public function execute(string $name, array $args): array
    {
        return match ($name) {
                'export_einsaetze_csv' => $this->exportEinsaetzeCsv($args),
                'export_bestelleingang_csv' => $this->exportBestelleingangCsv($args),
            default => ['error' => "Unknown tool: {$name}"],
        };
    }

    /**
     * Export einsätze in the given range to either an XLSX (default —
     * formatted, frozen header, auto-sized columns) or a CSV file
     * (UTF-8 + BOM, ;-separated). XLSX is preferred because Excel opens
     * it cleanly on every locale — CSV's regional quirks (decimal
     * separator, encoding) are notorious for corrupting non-ASCII
     * names.
     *
     * The file is written to the tenant-scoped ai-exports folder and
     * a signed URL valid for 15 minutes is returned. The download
     * route (AiExportController) verifies the path lives inside the
     * caller's tenant directory, so a leaked URL cannot be redeemed
     * by another tenant.
     *
     * Read-only tool: it does NOT create new DB rows.
     */
    private function exportEinsaetzeCsv(array $a): array
    {
        [$from, $to] = $this->dateRange($a);
        $format = strtolower(trim((string)($a['format'] ?? 'xlsx')));
        if (!in_array($format, ['xlsx', 'csv'], true)) {
            $format = 'xlsx';
        }
        // If the user explicitly asked for xlsx but the library is not
        // installed (e.g. a freshly-cloned dev box without composer
        // install), fall back to CSV instead of failing.
        if ($format === 'xlsx' && !class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $format = 'csv';
        }

        $rows = $this->loadEinsaetzeForExport($a, $from, $to);
        $workerMap = $this->loadEinsatzWorkers($rows->pluck('einsatz_id')->all());

        // Column layout mirrors what the disposition UI shows on each
        // card: customer/object/article on the left, plan-vs-ist times
        // and worker counts (soll/ist) in the middle, status on the
        // right. The trailing "Mitarbeiter" column lists every assigned
        // worker (dienst_nr + name).
        $header = [
            'Einsatz-ID', 'Datum', 'Bestell-ID',
            'Kunde-ID', 'Kunde',
            'Objekt-ID', 'Objekt',
            'Artikel-ID', 'Artikel',
            'NU-ID', 'NU-Firma',
            'Plan-Start', 'Plan-Ende', 'Soll-Std',
            'Ist-Start',  'Ist-Ende',  'Ist-Std',
            'Soll MA',  'Ist MA',
            'Schichtart', 'Status',
            'Beschreibung', 'Mitarbeiter',
        ];

        // Build a uniform array of arrays — both writers consume it.
        $exportRows = [];
        foreach ($rows as $r) {
            $assigned = $workerMap[$r->einsatz_id] ?? [];
            // Combined description: planning text first, einsatz override
            // appended if it differs. The disposition card shows whatever
            // is on the einsatz when present, falling back to the plan.
            $descPlan = trim((string)($r->plan_beschreibung ?? ''));
            $descIst  = trim((string)($r->beschreibung ?? ''));
            $desc = $descIst !== '' ? $descIst : $descPlan;

            $exportRows[] = [
                $r->einsatz_id,
                $r->datum,
                $r->bestell_id,
                $r->kunden_id,
                $r->kundenname,
                $r->obj_id,
                $r->objektname,
                $r->artikel_id ?? '',
                $r->artikel_name ?? '',
                $r->nu_id ?? '',
                $r->nu_name ?? '',
                $r->plan_start ?? '',
                $r->plan_ende  ?? '',
                $r->soll_std ?? '',
                $r->ist_start ?? '',
                $r->ist_ende ?? '',
                $r->ist_std_menge ?? '',
                // Soll vs. ist worker count — mirrors "0/13 Mitarbeiter".
                $r->soll_menge !== null ? (string)(int)round((float)$r->soll_menge) : '',
                (string) count($assigned),
                $r->schichtart ?? '',
                $r->hms_bestaetigt ?? '',
                $desc,
                implode(', ', $assigned),
            ];
        }

        $tenantSlug = preg_replace('/[^a-z0-9_-]/i', '_',
            (string) (\App\Services\TenantService::current()->slug ?? 'unknown'));
        $uuid     = (string) Str::uuid();
        $filename = "einsaetze_{$from}_to_{$to}_{$uuid}.{$format}";
        $path     = "ai-exports/{$tenantSlug}/{$filename}";

        if ($format === 'xlsx') {
            $bin = $this->buildExportXlsx($header, $exportRows, "Einsätze {$from}–{$to}");
            Storage::disk('local')->put($path, $bin);
        } else {
            $bin = $this->buildExportCsv($header, $exportRows);
            Storage::disk('local')->put($path, $bin);
        }

        $url = URL::temporarySignedRoute(
            'ai.export.download',
            now()->addMinutes(15),
            ['path' => base64_encode($path)]
        );

        return [
            'success'      => true,
            'format'       => $format,
            'rows'         => $rows->count(),
            'from'         => $from,
            'to'           => $to,
            'download_url' => $url,
            'filename'     => $filename,
            'expires_in'   => '15 Minuten',
            'hint'         => 'Klicke auf den Link, um die Datei herunterzuladen. Der Link ist 15 Minuten gültig.',
        ];
    }

    /**
     * Export Bestelleingang (incoming order requests) — wide overview
     * including the customer, object, article, NU, time-window and
     * status fields. The same xlsx/csv pipeline as the einsätze
     * export, just a different SELECT and column set.
     *
     * Filtering precedence (mirrors bestelleingang_status):
     *   - monat + jahr   → that month's bestelleingang
     *   - else from + to → date range on created_at
     *   - else           → all rows (still capped at 10k)
     */
    private function exportBestelleingangCsv(array $a): array
    {
        $format = strtolower(trim((string)($a['format'] ?? 'xlsx')));
        if (!in_array($format, ['xlsx', 'csv'], true)) {
            $format = 'xlsx';
        }
        if ($format === 'xlsx' && !class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $format = 'csv';
        }

        $q = DB::connection('tenant')->table('bestelleingang as b')
            ->leftJoin('kunden as k',          'b.kunden_id', '=', 'k.kunde_id')
            ->leftJoin('objekte as o',         'b.obj_id',    '=', 'o.obj_id')
            ->leftJoin('nachunternehmer as n', 'b.nu_id',     '=', 'n.nu_id')
            ->leftJoin('artikel as ar',        'b.artikel_id','=', 'ar.artikel_id')
            ->orderBy('b.created_at', 'desc')
            ->orderBy('b.eingang_id', 'desc');

        // Filters
        $monat = (int)($a['monat'] ?? 0);
        $jahr  = (int)($a['jahr']  ?? 0);
        $rangeLabel = 'alle';

        if ($monat >= 1 && $monat <= 12 && $jahr >= 2000) {
            $q->where('b.monat', $monat)->where('b.jahr', $jahr);
            $rangeLabel = sprintf('%04d-%02d', $jahr, $monat);
        } elseif (!empty($a['from']) && !empty($a['to'])) {
            $q->whereBetween('b.created_at', [$a['from'] . ' 00:00:00', $a['to'] . ' 23:59:59']);
            $rangeLabel = $a['from'] . '_to_' . $a['to'];
        }
        if (!empty($a['status']))    $q->where('b.status',     $a['status']);
        if (!empty($a['kunden_id'])) $q->where('b.kunden_id',  $a['kunden_id']);
        if (!empty($a['obj_id']))    $q->where('b.obj_id',     $a['obj_id']);
        if (!empty($a['nu_id']))     $q->where('b.nu_id',      $a['nu_id']);

        // Bereich scope — an export must never leak out-of-scope rows.
        [$scopeSql, $scopeParams] = BereichScope::inList(
            BereichScope::for(request()->user()),
            BereichScope::derivedExprSub('b')
        );
        $q->whereRaw($scopeSql, $scopeParams);

        $rows = $q->select([
            'b.eingang_id', 'b.created_at',
            'b.kunden_id', 'k.kundenname',
            'b.obj_id', 'o.objektname',
            'b.artikel_id', 'ar.artikel_name',
            'b.monat', 'b.jahr',
            'b.zeit_von', 'b.zeit_bis', 'b.modell',
            'b.kundenpreis',
            'b.nu_id', 'n.firmenname as nu_name', 'b.nu_preis',
            'b.status', 'b.bemerkung',
        ])->limit(10_000)->get();

        $header = [
            'Eingang-ID', 'Erstellt am',
            'Kunde-ID', 'Kunde',
            'Objekt-ID', 'Objekt',
            'Artikel-ID', 'Artikel',
            'Monat', 'Jahr',
            'Zeit von', 'Zeit bis', 'Modell',
            'Kundenpreis',
            'NU-ID', 'NU-Firma', 'NU-Preis',
            'Status', 'Bemerkung',
        ];

        $exportRows = [];
        foreach ($rows as $r) {
            $exportRows[] = [
                $r->eingang_id,
                $r->created_at,
                $r->kunden_id,
                $r->kundenname,
                $r->obj_id,
                $r->objektname,
                $r->artikel_id ?? '',
                $r->artikel_name ?? '',
                $r->monat,
                $r->jahr,
                $r->zeit_von ?? '',
                $r->zeit_bis ?? '',
                $r->modell ?? '',
                $r->kundenpreis ?? '',
                $r->nu_id ?? '',
                $r->nu_name ?? '',
                $r->nu_preis ?? '',
                $r->status,
                $r->bemerkung ?? '',
            ];
        }

        $tenantSlug = preg_replace('/[^a-z0-9_-]/i', '_',
            (string) (\App\Services\TenantService::current()->slug ?? 'unknown'));
        $uuid     = (string) Str::uuid();
        $filename = "bestelleingang_{$rangeLabel}_{$uuid}.{$format}";
        $path     = "ai-exports/{$tenantSlug}/{$filename}";

        if ($format === 'xlsx') {
            $bin = $this->buildExportXlsx($header, $exportRows, "Bestelleingang {$rangeLabel}");
        } else {
            $bin = $this->buildExportCsv($header, $exportRows);
        }
        Storage::disk('local')->put($path, $bin);

        $url = URL::temporarySignedRoute(
            'ai.export.download',
            now()->addMinutes(15),
            ['path' => base64_encode($path)]
        );

        return [
            'success'      => true,
            'format'       => $format,
            'rows'         => $rows->count(),
            'range'        => $rangeLabel,
            'download_url' => $url,
            'filename'     => $filename,
            'expires_in'   => '15 Minuten',
            'hint'         => 'Klicke auf den Link, um die Datei herunterzuladen. Der Link ist 15 Minuten gültig.',
        ];
    }

    /**
     * Filtered einsaetze-with-joins query, capped at 10k rows.
     *
     * We additionally join `bestelleingang_tage` (via the bestellung's
     * `eingang_id`) to surface the original planning data the
     * disposition UI shows in each card: required worker count
     * (`menge` → soll), planned hours (`soll_std`), planned time
     * window (`zeit_von` / `zeit_bis`) and the day-specific
     * description. Matching is by `eingang_id + datum` (plus `tag_nr`
     * when the einsatz pins one) — same logic as DispositionController.
     *
     * NB: einsaetze.kunden_id is plural but the kunden table's PK
     * column is `kunde_id` (singular) — see migration
     * 2024_01_01_100002_create_kunden_table.php.
     */
    private function loadEinsaetzeForExport(array $a, string $from, string $to)
    {
        $q = DB::connection('tenant')->table('einsaetze as e')
            ->leftJoin('kunden as k',          'e.kunden_id',  '=', 'k.kunde_id')
            ->leftJoin('objekte as o',         'e.obj_id',     '=', 'o.obj_id')
            ->leftJoin('artikel as ar',        'e.artikel_id', '=', 'ar.artikel_id')
            ->leftJoin('nachunternehmer as n', 'e.nu_id',      '=', 'n.nu_id')
            ->leftJoin('bestellungen as b',    'e.bestell_id', '=', 'b.bestell_id')
            ->leftJoin('bestelleingang_tage as d', function ($j) {
                $j->on('d.eingang_id', '=', 'b.eingang_id')
                  ->on('d.datum',      '=', 'e.datum');
            })
            ->whereBetween('e.datum', [$from, $to])
            ->orderBy('e.datum')
            ->orderBy('e.einsatz_id');

        if (!empty($a['status']))    $q->where('e.hms_bestaetigt', $a['status']);
        if (!empty($a['kunden_id'])) $q->where('e.kunden_id', $a['kunden_id']);
        if (!empty($a['obj_id']))    $q->where('e.obj_id',    $a['obj_id']);
        if (!empty($a['nu_id']))     $q->where('e.nu_id',     $a['nu_id']);

        // Bereich scope — the CSV/XLSX is a bulk leak vector, so the
        // predicate goes BEFORE the limit just like every other list.
        [$scopeSql, $scopeParams] = BereichScope::inList(
            BereichScope::for(request()->user()),
            BereichScope::derivedExprSub('e')
        );
        $q->whereRaw($scopeSql, $scopeParams);

        return $q->select([
            'e.einsatz_id', 'e.datum', 'e.bestell_id', 'e.kunden_id', 'k.kundenname',
            'e.obj_id', 'o.objektname', 'e.artikel_id', 'ar.artikel_name',
            'e.nu_id', 'n.firmenname as nu_name',
            'e.ist_start', 'e.ist_ende', 'e.ist_std_menge',
            'e.schichtart', 'e.hms_bestaetigt',
            'e.beschreibung',
            // Planning fields (soll) from bestelleingang_tage
            'd.menge as soll_menge', 'd.soll_std',
            'd.zeit_von as plan_start', 'd.zeit_bis as plan_ende',
            'd.beschreibung as plan_beschreibung',
        ])->limit(10_000)->get();
    }

    /** Map einsatz_id → ["MA001 (Hans Müller)", …] for the export. */
    private function loadEinsatzWorkers(array $einsatzIds): array
    {
        if (empty($einsatzIds)) return [];
        $rows = DB::connection('tenant')->table('einsatz_mitarbeiter as em')
            ->leftJoin('mitarbeiter as m', 'em.dienst_nr', '=', 'm.dienst_nr')
            ->whereIn('em.einsatz_id', $einsatzIds)
            ->select(['em.einsatz_id', 'em.dienst_nr', 'm.vorname', 'm.name'])
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $name = trim(($r->vorname ?? '') . ' ' . ($r->name ?? ''));
            $out[$r->einsatz_id][] = $r->dienst_nr . ($name !== '' ? " ({$name})" : '');
        }
        return $out;
    }

    /** UTF-8 BOM CSV; semicolon separator → opens cleanly in German Excel. */
    private function buildExportCsv(array $header, array $rows): string
    {
        $fh = fopen('php://temp/maxmemory:5242880', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ';', '"', '\\');
        }
        rewind($fh);
        $bin = (string) stream_get_contents($fh);
        fclose($fh);
        return $bin;
    }

    /**
     * Real Excel workbook — bold header row, frozen header, auto-sized
     * columns. Returns the binary blob (caller is responsible for
     * persisting it). PhpSpreadsheet must be installed.
     */
    private function buildExportXlsx(array $header, array $rows, string $title): string
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        // Excel limits sheet titles to 31 chars and forbids /\?*[]:
        $sheet->setTitle(mb_substr(preg_replace('#[\\/\?\*\[\]:]+#', '-', $title), 0, 31));

        // Header row.
        $sheet->fromArray($header, null, 'A1');
        $colCount = count($header);
        $lastCol  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE5E7EB');
        $sheet->freezePane('A2');

        // Data rows.
        if (!empty($rows)) {
            $sheet->fromArray($rows, null, 'A2');
        }

        // Auto-size columns. Capped at 60 to keep the workbook compact.
        for ($i = 1; $i <= $colCount; $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
        $writer->setPreCalculateFormulas(false);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        try {
            $writer->save($tmp);
            $bin = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
            unset($book, $sheet);
        }
        return $bin;
    }
}
