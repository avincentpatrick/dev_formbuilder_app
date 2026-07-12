<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Models\FormField;

/**
 * Turns a stored answer (from `submission_answers.answers`, keyed by `form_fields.key`) into a single
 * human-readable string, and normalizes a choice field's author-defined option list. Shared by the read
 * side of F7 — the inbox detail view (walks live {@see FormField} rows) and the streamed
 * {@see SubmissionExporter} (walks a version's immutable `schema_snapshot`) — so choice-value → label
 * resolution and multi-select/boolean/date rendering are defined exactly once for both surfaces.
 *
 * Structural `note`/`page_break` types carry no answer and are excluded upstream (see {@see isDataField()});
 * every other type resolves here. Empty answers return '' so an export cell is blank and the detail view can
 * substitute its own em-dash.
 */
final class SchemaValueFormatter
{
    /** Field types that never hold a respondent answer — excluded from both the detail view and the export. */
    private const NON_DATA = [FieldType::Note, FieldType::PageBreak];

    /** Whether a field type contributes an answer column/row (everything but pure structural labels). */
    public function isDataField(FieldType $type): bool
    {
        return ! in_array($type, self::NON_DATA, true);
    }

    /**
     * Format one answer for display/export. Choice values resolve to their option labels; multi-select /
     * repeat arrays join with "; "; yes/no renders Yes/No; everything else stringifies. Empty → ''.
     *
     * @param  array<string, mixed>  $config  the field's `config` jsonb (holds `options` for choice types)
     */
    public function displayValue(FieldType $type, mixed $answer, array $config): string
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return '';
        }

        if ($type === FieldType::YesNo) {
            return $this->boolLabel($answer);
        }

        // Choice fields resolve values to labels; cascading select does too (its `config.options` carry
        // value+label alongside level/parent, which optionLabels ignores) so an exported cascade reads as
        // "NCR; Manila", not "ncr; manila" (Increment G4a).
        $labels = ($type->hasOptions() || $type === FieldType::CascadingSelect) ? $this->optionLabels($config) : [];

        if (is_array($answer)) {
            $parts = array_map(
                fn (mixed $v): string => $labels[(string) $this->scalar($v)] ?? (string) $this->scalar($v),
                $answer,
            );

            return implode('; ', $parts);
        }

        $scalar = (string) $this->scalar($answer);

        return $labels[$scalar] ?? $scalar;
    }

    /**
     * The author-defined option list for choice fields (stored in `config.options`), normalised to the
     * `{value,label}` pairs a select/checkbox binds to. Empty for non-choice types or a malformed list.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{value: string, label: string}>
     */
    public function options(array $config): array
    {
        $options = $config['options'] ?? [];
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }
            $value = (string) $option['value'];
            $normalized[] = ['value' => $value, 'label' => (string) ($option['label'] ?? $value)];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string> value ⇒ label
     */
    private function optionLabels(array $config): array
    {
        $map = [];
        foreach ($this->options($config) as $option) {
            $map[$option['value']] = $option['label'];
        }

        return $map;
    }

    private function boolLabel(mixed $answer): string
    {
        $truthy = $answer === true || $answer === 1 || in_array($answer, ['1', 'true', 'yes', 'on'], true);

        return $truthy ? 'Yes' : 'No';
    }

    /** Reduce a JSON scalar to a string-castable value (guards against nested arrays/objects in answers). */
    private function scalar(mixed $value): string|int|float|bool
    {
        return is_scalar($value) ? $value : (string) json_encode($value);
    }
}
