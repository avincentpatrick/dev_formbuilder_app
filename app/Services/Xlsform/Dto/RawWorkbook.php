<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Services\Xlsform\XlsformImportParser;
use App\Services\Xlsform\XlsformWorkbookReader;

/**
 * A parsed `.xlsx` workbook (Increment G7b) — the DB-free hand-off from {@see XlsformWorkbookReader}
 * to {@see XlsformImportParser}. Sheets are keyed by their LOWERCASED name (so
 * `Survey`/`survey`/`SURVEY` all resolve), but each sheet keeps its column HEADERS in original case — the
 * `label::English` translation-column suffix is case-significant, so the parser normalizes known columns
 * case-insensitively itself rather than the reader flattening the header case here.
 */
final class RawWorkbook
{
    /**
     * `row_numbers` (M244) holds each data row's row number in the sheet, where the header is wherever it was. A
     * workbook built in memory (the unit tests) may leave it out, and then a row's number is its index plus two.
     *
     * @param  array<string, array{headers: list<string>, rows: list<array<string, ?string>>, row_numbers?: list<int>}>  $sheets  keyed by lowercased sheet name
     */
    public function __construct(private readonly array $sheets) {}

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[strtolower($name)]);
    }

    /**
     * The sheet's data rows (header-keyed maps, original header case), or `[]` when the sheet is absent.
     *
     * @return list<array<string, ?string>>
     */
    public function rows(string $name): array
    {
        return $this->sheets[strtolower($name)]['rows'] ?? [];
    }

    /**
     * The row number the author sees for the sheet's `$index`-th data row (M244, `R-24ec82a6`): the real one when the
     * reader recorded it, else `$index + 2` (a header in row 1 and no blank rows).
     */
    public function rowNumber(string $name, int $index): int
    {
        return $this->sheets[strtolower($name)]['row_numbers'][$index] ?? $index + 2;
    }

    /**
     * The sheet's column headers (original case, trimmed), or `[]` when the sheet is absent.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        return $this->sheets[strtolower($name)]['headers'] ?? [];
    }
}
