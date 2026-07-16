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
     * @param  array<string, array{headers: list<string>, rows: list<array<string, ?string>>}>  $sheets  keyed by lowercased sheet name
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
     * The sheet's column headers (original case, trimmed), or `[]` when the sheet is absent.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        return $this->sheets[strtolower($name)]['headers'] ?? [];
    }
}
