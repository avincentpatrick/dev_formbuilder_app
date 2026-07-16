<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Services\Xlsform\Dto\RawWorkbook;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads an XLSForm `.xlsx` into a {@see RawWorkbook} (Increment G7b) — the read twin of
 * {@see XlsformWorkbookWriter}, over `OpenSpout\Reader\XLSX\Reader` (the pattern proven in
 * tests/Feature/Submissions/SubmissionExportTest.php). Deliberately tolerant, because real Kobo/ODK exports
 * carry many columns this product ignores: the first row of each sheet is the header (trimmed, BOM stripped
 * on the first cell); every later row becomes a header-keyed map; fully-empty rows are dropped. All domain
 * validation (a missing `survey` sheet, an unmapped `type`) is the parser's job, not the reader's.
 */
final class XlsformWorkbookReader
{
    public function read(string $path): RawWorkbook
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $name = strtolower(trim($sheet->getName()));

                $headers = [];
                $rows = [];
                $isHeaderRow = true;

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if ($isHeaderRow) {
                        $headers = $this->headerRow($cells);
                        $isHeaderRow = false;

                        continue;
                    }

                    $assoc = $this->associate($headers, $cells);
                    if ($assoc !== null) {
                        $rows[] = $assoc;
                    }
                }

                // A later sheet of the same (case-folded) name never clobbers an earlier one.
                $sheets[$name] ??= ['headers' => $headers, 'rows' => $rows];
            }
        } finally {
            $reader->close();
        }

        return new RawWorkbook($sheets);
    }

    /**
     * Normalize the header row: trim every cell, strip a leading UTF-8 BOM from the first, keep original
     * case (the `label::English` translation suffix is case-significant).
     *
     * @param  array<int, mixed>  $cells
     * @return list<string>
     */
    private function headerRow(array $cells): array
    {
        $headers = [];
        $first = true;
        foreach ($cells as $cell) {
            $value = trim($this->stringify($cell));
            if ($first) {
                $value = ltrim($value, "\u{FEFF}");
                $first = false;
            }
            $headers[] = $value;
        }

        return $headers;
    }

    /**
     * Project a data row onto the headers, dropping unnamed columns. Returns null for a row with no
     * non-empty cell (a blank spacer row).
     *
     * @param  list<string>  $headers
     * @param  array<int, mixed>  $cells
     * @return array<string, ?string>|null
     */
    private function associate(array $headers, array $cells): ?array
    {
        $assoc = [];
        $hasValue = false;

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $raw = $cells[$index] ?? null;
            $value = $raw === null ? null : $this->stringify($raw);
            if ($value !== null && $value !== '') {
                $hasValue = true;
            }
            $assoc[$header] = $value;
        }

        return $hasValue ? $assoc : null;
    }

    /** Coerce a spreadsheet cell value to a string (form definitions are text; numbers/dates are rare but tolerated). */
    private function stringify(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }
        if (is_int($value) || is_float($value)) {
            // Emit an integer-valued float without the ".0" tail (repeat_count = 5, not "5.0").
            return $value == (int) $value ? (string) (int) $value : (string) $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
