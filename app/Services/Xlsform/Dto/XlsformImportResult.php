<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Services\Xlsform\XlsformImporter;

/**
 * The outcome of a completed XLSForm import (Increment G7b) — the row counts written into the draft plus the
 * non-fatal `warnings` surfaced to the author (as an import-result summary on the API, a builder banner on
 * the web). Returned by {@see XlsformImporter::import()}.
 */
final class XlsformImportResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly int $sectionCount,
        public readonly int $fieldCount,
        public readonly int $validationCount,
        public readonly array $warnings = [],
    ) {}
}
