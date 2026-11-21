<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Exceptions\Xlsform\XlsformImportException;

/**
 * The longest text each imported cell may hold, and the collector that turns every cell over its limit into ONE
 * refusal (M244, `R-24ec82a6`).
 *
 * ⛔ AN OVER-LONG CELL USED TO REACH THE DATABASE. `form_fields.label` is `varchar(500)` and nothing checked it, so a
 * Kobo note kept in one cell failed the INSERT with `22001` and the author saw a 500 page with no reason. The import
 * now measures every cell it would store before the draft is touched (docs/xlsform-interop-spec.md §6: validation is
 * fully upfront), and names each one by sheet, row and column, so the author can fix them all in one pass.
 *
 * The limits are the narrower of the column and the builder's own save rule, so an imported form is one the builder
 * can save again: a choice label has no column limit (it lives in `config`), but `UpdateFieldRequest` refuses one over
 * 500, so a longer one would import and then make its question unsaveable. `XlsformImportHardeningTest` reads the
 * varchar widths from the database, as `XlsformImportAppearanceTest` does, so these and the schema cannot drift apart
 * unseen.
 *
 * A note's label is not measured here: a long note becomes note content ({@see XlsformMarkdown}), and only the text
 * that cannot become content is measured, by the parser, against {@see self::FIELD_LABEL}.
 */
final class XlsformColumnLimits
{
    /** `form_fields.label` (varchar 500), every language. */
    public const FIELD_LABEL = 500;

    /** `form_sections.label` (varchar 255), every language. */
    public const SECTION_LABEL = 255;

    /** `form_fields.hint` is text; the builder saves at most 2,000. */
    public const HINT = 2000;

    /** `form_field_validations.error_message` (varchar 500): a constraint or required message. */
    public const MESSAGE = 500;

    /** A choice label, which the builder saves at most 500 of. */
    public const CHOICE_LABEL = 500;

    /** A choice's value, which the builder saves at most 255 of. */
    public const CHOICE_NAME = 255;

    /** `forms.title` and `form_versions.title` (varchar 255). */
    public const FORM_TITLE = 255;

    /** A formula column; the builder saves at most 2,000, and the lexer refuses more at publish. */
    public const EXPRESSION = 2000;

    /** `form_fields.key` and `form_sections.key` (varchar 150). A long name is shortened, never refused. */
    public const KEY = 150;

    /** @var list<array{sheet: string, row: int, column: string, length: int, limit: int, unit: string}> */
    private array $tooLong = [];

    /**
     * Note `$value` if it is longer than `$limit` characters.
     */
    public function measure(string $sheet, int $row, string $column, ?string $value, int $limit): void
    {
        if ($value === null) {
            return;
        }

        $this->measureCount($sheet, $row, $column, mb_strlen($value), $limit, 'characters');
    }

    /**
     * Note a cell whose `$count` of `$unit` (characters, or a note's content blocks) is over `$limit`.
     */
    public function measureCount(string $sheet, int $row, string $column, int $count, int $limit, string $unit): void
    {
        if ($count > $limit) {
            $this->tooLong[] = ['sheet' => $sheet, 'row' => $row, 'column' => $column, 'length' => $count, 'limit' => $limit, 'unit' => $unit];
        }
    }

    /**
     * Note every `{$prefix}::{lang}` cell of `$row` (and the bare one) that is longer than `$limit`.
     *
     * @param  array<string, ?string>  $row
     */
    public function measureColumns(string $sheet, int $rowNumber, array $row, string $prefix, int $limit): void
    {
        $needle = strtolower($prefix);
        foreach ($row as $header => $value) {
            $lower = strtolower((string) $header);
            if ($lower === $needle || str_starts_with($lower, $needle.'::')) {
                $this->measure($sheet, $rowNumber, (string) $header, $value, $limit);
            }
        }
    }

    /** Refuse the file when any cell was too long — once, naming every one. */
    public function assertNoneTooLong(): void
    {
        if ($this->tooLong !== []) {
            throw XlsformImportException::cellsTooLong($this->tooLong);
        }
    }

    /** Forget what was measured, for the next parse. */
    public function reset(): void
    {
        $this->tooLong = [];
    }
}
