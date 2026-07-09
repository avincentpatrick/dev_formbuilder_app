<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Services\Expressions\Coercion;
use App\Services\Validation\SemanticValidator;
use Illuminate\Support\Collection;

/**
 * Stage 1 of the Submission Pipeline (technical-architecture.md §4.1) — structural normalisation. Coerces
 * each answer to its field's canonical runtime shape and rejects the two hard structural faults: an
 * unknown key (no field owns it) and a type-mismatch (an array where a scalar is required, a non-numeric
 * value in a numeric field). All faults are aggregated into one
 * {@see SubmissionValidationException::structural()} rather than throwing on the first, so the caller can
 * surface every field error at once.
 *
 * It does NOT check required-ness or constraints — those are Stage 3 ({@see SemanticValidator}),
 * which is relevance-aware (a field hidden by skip-logic is not required) and therefore strictly more
 * correct than a naive Stage-1 presence check. Empty answers are dropped here (treated as unanswered);
 * display-only (`note`/`page_break`) and server-computed (`calculated`) keys are dropped too. Coercion is
 * value-driven via the shared {@see Coercion} primitives, so a normalised answer evaluates identically in
 * the expression engine and projects cleanly into the typed answer index.
 */
final class StructuralAnswerNormalizer
{
    /**
     * @param  Collection<int, FormField>  $fields  the version's fields
     * @param  array<string, mixed>  $answers  raw field key => value
     * @return array<string, mixed> the normalised, non-empty answers keyed by field key
     */
    public function normalize(Collection $fields, array $answers): array
    {
        /** @var array<string, FormField> $byKey */
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        /** @var list<array{field: string, rule: string, message: string}> $errors */
        $errors = [];

        foreach ($answers as $key => $value) {
            $field = $byKey[$key] ?? null;
            if ($field === null) {
                $errors[] = ['field' => $key, 'rule' => 'unknown_field', 'message' => "Unknown field: {$key}."];

                continue;
            }

            if (Coercion::isEmpty($value)) {
                continue; // an empty answer is "unanswered" — Stage 3 decides whether that is an error
            }

            [$store, $normalizedValue, $error] = $this->coerce($field, $value);
            if ($error !== null) {
                $errors[] = $error;

                continue;
            }
            if ($store) {
                $normalized[$key] = $normalizedValue;
            }
        }

        if ($errors !== []) {
            throw SubmissionValidationException::structural($errors);
        }

        return $normalized;
    }

    /**
     * Coerce one non-empty answer to its field's canonical shape.
     *
     * @return array{0: bool, 1: mixed, 2: array{field: string, rule: string, message: string}|null}
     *                                                                                               [store?, value, error?]
     */
    private function coerce(FormField $field, mixed $value): array
    {
        return match ($field->field_type) {
            // Display-only / server-computed — never a stored respondent answer.
            FieldType::Note, FieldType::PageBreak, FieldType::Calculated => [false, null, null],

            FieldType::Integer, FieldType::Decimal => Coercion::isNumericLike($value)
                ? [true, $value, null]
                : [false, null, $this->mismatch($field->key, 'not_a_number', 'This field must be a number.')],

            FieldType::YesNo => is_array($value)
                ? [false, null, $this->mismatch($field->key, 'expected_scalar', 'This field must be a single value.')]
                : [true, $this->toYesNo($value), null],

            FieldType::MultiSelect => [true, $this->toStringList($value), null],

            // Scalar-valued types: reject an array, otherwise store a canonical string.
            FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::SingleSelect, FieldType::Dropdown,
            FieldType::Date, FieldType::Time, FieldType::Datetime,
            FieldType::Hidden => is_array($value)
                ? [false, null, $this->mismatch($field->key, 'expected_scalar', 'This field must be a single value.')]
                : [true, Coercion::toStr($value), null],

            // Advanced types (geo/media/matrix/likert/cascading/duration) have no manual-encode renderer in
            // Phase 1 (marked unsupported in F4b); pass any value through untouched so the pipeline stays
            // channel-agnostic and a future channel's payload is not rejected here.
            default => [true, $value, null],
        };
    }

    /**
     * @return array{field: string, rule: string, message: string}
     */
    private function mismatch(string $key, string $rule, string $message): array
    {
        return ['field' => $key, 'rule' => $rule, 'message' => $message];
    }

    /**
     * Yes/No → a real bool. A superset of {@see Coercion::toBool} that also reads the string `"no"` as false
     * (the common serialised form of a No answer).
     */
    private function toYesNo(mixed $value): bool
    {
        if (is_string($value)) {
            $lower = strtolower(trim($value));

            return ! in_array($lower, ['', '0', 'false', 'no'], true);
        }

        return Coercion::toBool($value);
    }

    /**
     * Multi-select → the frozen `list<string>` shape the expression engine and answer index expect. A lone
     * scalar (one selection sent un-wrapped) becomes a single-element list.
     *
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $v): string => Coercion::toStr($v), $value));
        }

        return [Coercion::toStr($value)];
    }
}
