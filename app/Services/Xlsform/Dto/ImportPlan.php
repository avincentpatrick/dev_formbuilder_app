<?php

declare(strict_types=1);

namespace App\Services\Xlsform\Dto;

use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformImportParser;

/**
 * The fully-resolved, DB-free result of parsing an XLSForm workbook (Increment G7b) — everything the
 * {@see XlsformImporter} needs to repopulate a draft, with explicit keys. Produced by
 * {@see XlsformImportParser::parse()} entirely upfront: if the workbook is invalid the
 * parser throws BEFORE returning a plan, so the importer's destructive draft-replace never runs on a bad
 * file (§6). `warnings` are non-fatal lossy-coercion notes the author reviews before publishing.
 */
final class ImportPlan
{
    /**
     * @param  list<SectionSpec>  $sections
     * @param  list<FieldSpec>  $fields
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $fields,
        public readonly SettingsSpec $settings,
        public readonly array $warnings = [],
    ) {}
}
