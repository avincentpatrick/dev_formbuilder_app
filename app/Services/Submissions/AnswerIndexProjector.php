<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\IndexedDataType;
use App\Models\FormField;
use App\Services\Expressions\Coercion;
use Illuminate\Support\Carbon;

/**
 * The typed-projection half of Stage 4 (technical-architecture.md §4.1 Risk R1; data-dictionary §9). Maps
 * one queryable field's answer to the single `submission_answer_index.value_*` column its author-set
 * {@see IndexedDataType} designates — the hot-path filter/sort/aggregate index written transactionally
 * alongside the JSONB document. Returns null when there is nothing to project: a non-queryable field, a
 * field with no declared index type (impossible on a published version — the publish gate enforces
 * `is_queryable ⇒ indexed_data_type`), an empty answer, a non-scalar answer (multi-select / repeat arrays
 * are never indexed — only scalar values, §9), or an unparseable date/datetime.
 *
 * Type selection is author-declared (the field's `indexed_data_type`), never derived from `field_type` —
 * so e.g. a text field can be indexed as a number if the author chose that.
 */
final class AnswerIndexProjector
{
    /**
     * @return array{column: string, value: mixed}|null
     */
    public function project(FormField $field, mixed $answer): ?array
    {
        if (! $field->is_queryable || $field->indexed_data_type === null) {
            return null;
        }

        if (Coercion::isEmpty($answer) || is_array($answer)) {
            return null; // scalar, non-repeated values only (§9); object-valued geo → {@see GeoIndexProjector}
        }

        return match ($field->indexed_data_type) {
            IndexedDataType::Text => ['column' => 'value_text', 'value' => Coercion::toStr($answer)],
            IndexedDataType::Number => Coercion::isNumericLike($answer)
                ? ['column' => 'value_number', 'value' => Coercion::toNumber($answer)]
                : null,
            IndexedDataType::Boolean => ['column' => 'value_boolean', 'value' => Coercion::toBool($answer)],
            IndexedDataType::Date => $this->parse($answer, 'value_date'),
            IndexedDataType::Datetime => $this->parse($answer, 'value_datetime'),
        };
    }

    /**
     * Parse a date/datetime answer into a Carbon, guarded — a malformed string projects nothing rather than
     * throwing out of the persist transaction. (There is no shared date-coercion primitive; this is the one
     * genuinely new bit of coercion Stage 4 needs.)
     *
     * @return array{column: string, value: Carbon}|null
     */
    private function parse(mixed $answer, string $column): ?array
    {
        $parsed = rescue(static fn (): Carbon => Carbon::parse(Coercion::toStr($answer)), null, false);

        return $parsed instanceof Carbon ? ['column' => $column, 'value' => $parsed] : null;
    }
}
