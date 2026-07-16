<?php

declare(strict_types=1);

namespace App\Exceptions\Xlsform;

use App\Exceptions\Forms\PublishValidationException;
use App\Services\Xlsform\XlsformImportParser;
use RuntimeException;

/**
 * A fatal XLSForm import failure (Increment G7b / docs/xlsform-interop-spec.md §6). Thrown by
 * {@see XlsformImportParser} entirely UPFRONT — before the importer's destructive
 * draft-replace runs — so a malformed workbook never partially replaces a draft.
 *
 * Unlike {@see PublishValidationException} (message-only), this carries a stable
 * machine `code` and optional `details` so the /api/v1 envelope can emit
 * `{ code: "xlsform_unsupported_field_type", details: { row, type } }` (api-specification.md §2.3); the
 * render closure in bootstrap/app.php reads them via {@see self::code()} / {@see self::details()}.
 */
final class XlsformImportException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    /** The workbook has no `survey` sheet, so there is nothing to import. */
    public static function missingSurveySheet(): self
    {
        return new self(
            'The workbook has no “survey” sheet, so it is not a valid XLSForm.',
            'xlsform_missing_survey_sheet',
        );
    }

    /** A `survey` row uses a `type` this product cannot import (no §3 mapping). */
    public static function unsupportedFieldType(int $row, string $type): self
    {
        return new self(
            "Row {$row}: the field type “{$type}” is not a supported XLSForm type.",
            'xlsform_unsupported_field_type',
            ['row' => $row, 'type' => $type],
        );
    }

    /** The stable, machine-readable error code (never the HTTP status text, never translated). */
    public function code(): string
    {
        return $this->errorCode;
    }

    /**
     * Optional endpoint-specific context (e.g. `{row, type}`), or null.
     *
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        return $this->details;
    }
}
