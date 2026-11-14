<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Shared .xlsx workbook builder for table exports.
 *
 * Real workbooks beat CSV here: no delimiter guessing, no BOM roulette,
 * and numbers land as typed cells (7.99 stays 7.99 — a German-locale
 * Excel would read "7.99" in CSV as 799). Used by the generic
 * /api/export/xlsx endpoint and the Controlling export.
 */
class XlsxTable
{
    private const HEAD_FILL  = 'FF1F2937';   // dark slate header, white text
    private const GROUP_FILL = 'FFE8EEF5';   // group header rows (e.g. one worker / one object)
    private const SUM_FILL   = 'FFF3F4F6';   // subtotal rows
    private const TITLE_SIZE = 14;

    /**
     * @param string      $title   Sheet title (Excel-safe-truncated to 31 chars)
     * @param string[]    $headers Header row labels
     * @param array[]     $rows    Data rows (scalars — numbers stay numeric)
     * @param array[]     $extraSheets Optional further sheets:
     *                    [['title' => …, 'headers' => […], 'rows' => [[…]], 'bold_rows' => [0-based row indices], 'layout' => […]]]
     * @param array       $layout  Optional layout of the first sheet (see fill())
     * @return string              xlsx binary
     */
    public static function build(string $title, array $headers, array $rows, array $extraSheets = [], array $layout = []): string
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        self::fill($book->getActiveSheet(), $title, $headers, $rows, [], $layout);
        foreach ($extraSheets as $s) {
            self::fill($book->createSheet(), (string) ($s['title'] ?? 'Blatt'), (array) ($s['headers'] ?? []),
                (array) ($s['rows'] ?? []), (array) ($s['bold_rows'] ?? []), (array) ($s['layout'] ?? []));
        }
        $book->setActiveSheetIndex(0);

        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        try {
            $writer->save($tmp);
            $bin = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }
        return $bin;
    }

    /**
     * Optional $layout keys (all row indices are 0-based into $rows):
     *   title_rows  string[][]  lines above the header (first one = big title)
     *   group_rows  int[]       section header rows (merged across, tinted, bold)
     *   sum_rows    int[]       subtotal rows (bold, top border, light fill)
     *   head_rows   int[]       rows styled like the header (a second table on the sheet)
     *   num_cols    int[]       0-based columns shown as "#,##0.00"
     *   int_cols    int[]       0-based columns shown as "0"
     *   widths      float[]     column widths (chars); missing → auto size
     *   autofilter  bool        filter buttons on the header row
     *   landscape   bool        print landscape, fit to page width
     */
    private static function fill(Worksheet $sheet, string $title, array $headers, array $rows, array $boldRows, array $layout = []): void
    {
        // Excel limits sheet titles to 31 chars and forbids /\?*[]:
        $sheet->setTitle(mb_substr(preg_replace('#[\\/\?\*\[\]:]+#', '-', $title), 0, 31) ?: 'Blatt');

        $styled   = !empty($layout);
        $colCount = max(1, count($headers));
        $lastCol  = Coordinate::stringFromColumnIndex($colCount);

        // Optional title lines above the table.
        $titleRows = array_values(array_filter((array) ($layout['title_rows'] ?? []), 'is_array'));
        foreach ($titleRows as $i => $line) {
            $r = $i + 1;
            $sheet->fromArray(array_values($line), null, "A{$r}");
            if ($i === 0) $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(self::TITLE_SIZE);
            else $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        }
        $headRow = count($titleRows) + ($titleRows ? 2 : 1);   // one blank line after the titles
        $first   = $headRow + 1;                                // first data row (1-based)

        // Header row: bold, filled, frozen pane.
        $sheet->fromArray($headers, null, "A{$headRow}");
        $head = $sheet->getStyle("A{$headRow}:{$lastCol}{$headRow}");
        $head->getFont()->setBold(true);
        if ($styled) {
            $head->getFont()->getColor()->setARGB('FFFFFFFF');
            $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEAD_FILL);
            $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->getRowDimension($headRow)->setRowHeight(30);
        } else {
            $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
        }
        $sheet->freezePane('A' . $first);

        if (!empty($rows)) {
            // DefaultValueBinder: PHP floats/ints land as real numeric cells,
            // everything else as text.
            // Styled sheets compare strictly, so a real 0 (e.g. Diff 0,00)
            // is written instead of being dropped like an empty cell.
            $sheet->fromArray($rows, null, 'A' . $first, $styled);
        }
        $n = count($rows);
        $last = $first + max(0, $n - 1);
        $rowRef = fn (int $i) => $i + $first;
        $valid = fn ($i) => is_int($i) && $i >= 0 && $i < $n;

        foreach ($boldRows as $i) {
            if ($valid($i)) $sheet->getStyle("A{$rowRef($i)}:{$lastCol}{$rowRef($i)}")->getFont()->setBold(true);
        }

        if ($styled && $n) {
            foreach ((array) ($layout['num_cols'] ?? []) as $c) {
                if (!is_int($c) || $c < 0 || $c >= $colCount) continue;
                $L = Coordinate::stringFromColumnIndex($c + 1);
                $sheet->getStyle("{$L}{$first}:{$L}{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            foreach ((array) ($layout['int_cols'] ?? []) as $c) {
                if (!is_int($c) || $c < 0 || $c >= $colCount) continue;
                $L = Coordinate::stringFromColumnIndex($c + 1);
                $sheet->getStyle("{$L}{$first}:{$L}{$last}")->getNumberFormat()->setFormatCode('0');
            }
            // Thin grid on the data block keeps rows apart when printed.
            $sheet->getStyle("A{$first}:{$lastCol}{$last}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setARGB('FFD1D5DB');

            foreach ((array) ($layout['group_rows'] ?? []) as $i) {
                if (!$valid($i)) continue;
                $r = $rowRef($i);
                $sheet->mergeCells("A{$r}:{$lastCol}{$r}");
                $st = $sheet->getStyle("A{$r}:{$lastCol}{$r}");
                $st->getFont()->setBold(true)->setSize(12);
                $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GROUP_FILL);
                $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $st->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF64748B');
                $sheet->getRowDimension($r)->setRowHeight(22);
            }
            foreach ((array) ($layout['sum_rows'] ?? []) as $i) {
                if (!$valid($i)) continue;
                $r = $rowRef($i);
                $st = $sheet->getStyle("A{$r}:{$lastCol}{$r}");
                $st->getFont()->setBold(true);
                $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::SUM_FILL);
                $st->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FF64748B');
            }
            foreach ((array) ($layout['head_rows'] ?? []) as $i) {
                if (!$valid($i)) continue;
                $r = $rowRef($i);
                $st = $sheet->getStyle("A{$r}:{$lastCol}{$r}");
                $st->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEAD_FILL);
                $st->getAlignment()->setWrapText(true);
            }
        }

        if ($styled && !empty($layout['autofilter']) && $n) {
            $sheet->setAutoFilter("A{$headRow}:{$lastCol}{$last}");
        }

        $widths = array_values((array) ($layout['widths'] ?? []));
        for ($i = 1; $i <= $colCount; $i++) {
            $dim = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i));
            $w = $widths[$i - 1] ?? null;
            if (is_numeric($w) && $w > 0) $dim->setWidth(min(80, (float) $w));
            else $dim->setAutoSize(true);
        }

        if ($styled) {
            $ps = $sheet->getPageSetup();
            $ps->setPaperSize(PageSetup::PAPERSIZE_A4);
            if (!empty($layout['landscape'])) $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $ps->setFitToWidth(1)->setFitToHeight(0);
            $ps->setRowsToRepeatAtTopByStartAndEnd($headRow, $headRow);
            $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        }
    }
}
