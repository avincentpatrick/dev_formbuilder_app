<?php

declare(strict_types=1);

namespace App\Services\Explorer\Agreement;

/**
 * One line of an agreement list (M250, ADR-0025 §D10): a choice question, or one row of a question table's choice
 * column, or one row of a Likert grid.
 *
 * `field`, `row` and `column` are where its answer sits in the stored document — `answers[field]`,
 * `answers[field][row]` or `answers[field][row][column]` — and reach SQL only as bound data. `position` is its place
 * in the form (sections, then questions, then a table's rows), which breaks every tie in the list's order.
 */
final readonly class AgreementItem
{
    /**
     * @param  array<string, string>  $options  the answers it offers, value ⇒ label, in the author's order
     */
    public function __construct(
        public string $pick,
        public string $key,
        public string $field,
        public ?string $row,
        public ?string $column,
        public string $label,
        public string $question,
        public ?string $columnLabel,
        public string $section,
        public int $position,
        public array $options,
        public bool $multiple,
    ) {}
}
