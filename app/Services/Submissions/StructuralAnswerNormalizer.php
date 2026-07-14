<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Models\FormSection;
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
 *
 * Repeat groups (Increment G1): a repeatable section's answers arrive under the SECTION key as a list of
 * per-instance field-key => value objects. Each instance's inner values are coerced with the SAME per-field
 * rules as a flat answer (the nesting is the only difference); malformed nesting is a structural fault
 * (`expected_instance_array`/`expected_instance_object`), an unknown inner key is `unknown_field` addressed
 * `section[i].field`, and a repeat-member field sent at the TOP level is `misplaced_repeat_field`. Empty
 * inner answers and fully-empty instances are dropped; an empty repeat group is dropped entirely.
 */
final class StructuralAnswerNormalizer
{
    /**
     * @param  Collection<int, FormField>  $fields  the version's fields
     * @param  Collection<int, FormSection>  $sections  the version's sections (repeat flags drive nesting)
     * @param  array<string, mixed>  $answers  raw field key => value (+ repeatable-section key => instance list)
     * @return array<string, mixed> the normalised, non-empty answers keyed by field/section key
     */
    public function normalize(Collection $fields, Collection $sections, array $answers): array
    {
        /** @var array<string, FormField> $byKey */
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }

        /** @var array<string, true> $repeatSectionIds */
        $repeatSectionIds = [];
        /** @var array<string, true> $repeatSectionKeys */
        $repeatSectionKeys = [];
        foreach ($sections as $section) {
            if ($section->is_repeatable === true) {
                $repeatSectionIds[$section->id] = true;
                $repeatSectionKeys[$section->key] = true;
            }
        }

        /** @var array<string, true> $repeatMemberKeys */
        $repeatMemberKeys = [];
        /** @var array<string, array<string, FormField>> $membersBySectionKey */
        $membersBySectionKey = [];
        foreach ($sections as $section) {
            if ($section->is_repeatable !== true) {
                continue;
            }
            $membersBySectionKey[$section->key] = [];
        }
        foreach ($fields as $field) {
            $sectionId = $field->form_section_id;
            if ($sectionId !== null && isset($repeatSectionIds[$sectionId])) {
                $repeatMemberKeys[$field->key] = true;
                $sectionKey = $sections->firstWhere('id', $sectionId)?->key;
                if ($sectionKey !== null) {
                    $membersBySectionKey[$sectionKey][$field->key] = $field;
                }
            }
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        /** @var list<array{field: string, rule: string, message: string}> $errors */
        $errors = [];

        foreach ($answers as $key => $value) {
            if (isset($repeatSectionKeys[$key])) {
                $instances = $this->normalizeRepeatSection($key, $value, $membersBySectionKey[$key] ?? [], $errors);
                if ($instances !== []) {
                    $normalized[$key] = $instances;
                }

                continue;
            }

            $field = $byKey[$key] ?? null;
            if ($field === null) {
                $errors[] = ['field' => $key, 'rule' => 'unknown_field', 'message' => "Unknown field: {$key}."];

                continue;
            }

            if (isset($repeatMemberKeys[$key])) {
                $errors[] = ['field' => $key, 'rule' => 'misplaced_repeat_field', 'message' => "Field {$key} must be submitted inside its repeat group."];

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
     * Normalise one repeatable section's submitted instances. Each element must be an instance object
     * (field key => value); its inner values are coerced with the identical per-field rules as a flat
     * answer. Empty inner answers and fully-empty instances are dropped (an unfilled row does not persist
     * and does not count toward min/max — Stage 3 counts what survives here). Faults are appended to
     * `$errors` (addressed with the instance path) rather than thrown, so every fault surfaces at once.
     *
     * @param  array<string, FormField>  $members  the repeatable section's fields, keyed by field key
     * @param  list<array{field: string, rule: string, message: string}>  $errors
     * @return list<array<string, mixed>> the non-empty normalised instances
     */
    private function normalizeRepeatSection(string $sectionKey, mixed $value, array $members, array &$errors): array
    {
        if (Coercion::isEmpty($value)) {
            return []; // an empty repeat group is "unanswered"
        }

        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = ['field' => $sectionKey, 'rule' => 'expected_instance_array', 'message' => "The {$sectionKey} group must be a list of instances."];

            return [];
        }

        /** @var list<array<string, mixed>> $instances */
        $instances = [];

        foreach ($value as $index => $instance) {
            if (Coercion::isEmpty($instance)) {
                continue; // a blank instance object ({} decodes to []) is dropped, not an error
            }

            if (! is_array($instance) || array_is_list($instance)) {
                $errors[] = ['field' => "{$sectionKey}[{$index}]", 'rule' => 'expected_instance_object', 'message' => "Instance {$index} of {$sectionKey} must be an object of field answers."];

                continue;
            }

            /** @var array<string, mixed> $normalizedInstance */
            $normalizedInstance = [];
            foreach ($instance as $innerKey => $innerValue) {
                $address = "{$sectionKey}[{$index}].{$innerKey}";
                $member = $members[$innerKey] ?? null;
                if ($member === null) {
                    $errors[] = ['field' => $address, 'rule' => 'unknown_field', 'message' => "Unknown field: {$address}."];

                    continue;
                }

                if (Coercion::isEmpty($innerValue)) {
                    continue;
                }

                [$store, $normalizedValue, $error] = $this->coerce($member, $innerValue);
                if ($error !== null) {
                    $errors[] = ['field' => $address, 'rule' => $error['rule'], 'message' => $error['message']];

                    continue;
                }
                if ($store) {
                    $normalizedInstance[$innerKey] = $normalizedValue;
                }
            }

            if ($normalizedInstance !== []) {
                $instances[] = $normalizedInstance;
            }
        }

        return $instances;
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

            // Cascading select (G4a): an ordered list<string> of one selected value per level. Trailing
            // empty levels (deeper levels left unselected) are trimmed; per-level membership + parent
            // consistency are Stage-3 (relevance-aware) checks, not a structural fault here.
            FieldType::CascadingSelect => $this->coerceCascade($value),

            // Object-valued grids (Increment G4b): matrix = {row:{col:cell}}, likert_matrix = {row:score}.
            // Only STRUCTURAL faults are raised here (an object is required; every row/column key must be
            // declared in config) — cell-value membership is the relevance-aware Stage-3 check, mirroring
            // cascading. The whole object is NEVER routed through scalar isEmpty/coercion (which diverges on
            // an empty object across the PHP/TS engines); each surviving leaf cell is coerced individually.
            FieldType::Matrix => $this->coerceMatrix($field, $value),
            FieldType::LikertMatrix => $this->coerceLikertMatrix($field, $value),

            // Geospatial (Increment G5b1 / ADR-0006): the object-valued GeoJSON envelope
            // {type, coordinates, accuracy?}. Only STRUCTURAL faults here (an object with a string `type`
            // and an array `coordinates`); coordinate range / ring closure / min-points / type-match are
            // the relevance-aware Stage-3 checks (so they also run in the client engine, golden lockstep).
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => $this->coerceGeo($field, $value),

            // Media capture (Increment G6): the answer is a list of attachment-reference envelopes
            // (`[{id, mime?, size?, …}]`) pointing at already-staged `attachments` rows. Only STRUCTURAL
            // faults here (a list of objects each carrying a non-empty string `id`); the count/required rules
            // are the relevance-aware Stage-3 checks (processMedia), and existence/ownership/scan are the
            // DB-backed Stage-3.5 check (AttachmentReferenceValidator). Routed through its own pass so scalar
            // coercion never mangles the objects — the same discipline as geo/composite.
            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature => $this->coerceMedia($field, $value),

            // Scalar-valued types: reject an array, otherwise store a canonical string. `likert_scale` (G4a)
            // is a single chosen scale point, so it coerces exactly like a single_select.
            FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale,
            FieldType::Date, FieldType::Time, FieldType::Datetime,
            FieldType::Hidden => is_array($value)
                ? [false, null, $this->mismatch($field->key, 'expected_scalar', 'This field must be a single value.')]
                : [true, Coercion::toStr($value), null],

            // Remaining advanced types (duration) have no manual-encode renderer yet; pass any value through
            // untouched so the pipeline stays channel-agnostic and a future channel's payload is not
            // rejected here.
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

    /**
     * Cascading select → an ordered `list<string>` of the chosen value per level. Trailing empty levels
     * (deeper levels the respondent left unselected) are dropped so the stored list is only as deep as it
     * was answered; an all-empty cascade normalises to nothing (not stored). Interior empties are kept and
     * surface as a Stage-3 `cascading_parent_mismatch`/`cascading_choice_invalid`.
     *
     * @return array{0: bool, 1: list<string>|null, 2: null}
     */
    private function coerceCascade(mixed $value): array
    {
        $list = $this->toStringList($value);

        while ($list !== [] && end($list) === '') {
            array_pop($list);
        }

        return $list === [] ? [false, null, null] : [true, $list, null];
    }

    /**
     * Geospatial (Increment G5b1 / ADR-0006) → the canonical GeoJSON envelope `{type, coordinates,
     * accuracy?}`. Structural faults only: the value must be an object (not a list/scalar →
     * `expected_object`) carrying a string `type` and an array `coordinates` (else `geo_malformed`).
     * Numeric-string ordinates are coerced to floats so the stored envelope feeds `ST_GeomFromGeoJSON`
     * cleanly; coordinate range, ring closure, min-points and type-match are the relevance-aware Stage-3
     * checks (processGeo), so they also run in the client TS engine and stay in golden lockstep. An empty
     * `{}` never reaches here — it is dropped as unanswered by the isEmpty guard in normalize().
     *
     * @return array{0: bool, 1: array<string, mixed>|null, 2: array{field: string, rule: string, message: string}|null}
     */
    private function coerceGeo(FormField $field, mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [false, null, $this->mismatch($field->key, 'expected_object', 'This field must be a location value.')];
        }

        $type = $value['type'] ?? null;
        $coordinates = $value['coordinates'] ?? null;
        if (! is_string($type) || $type === '' || ! is_array($coordinates)) {
            return [false, null, $this->mismatch($field->key, 'geo_malformed', 'This location value is malformed.')];
        }

        $envelope = [
            'type' => $type,
            'coordinates' => $this->coerceOrdinates($coordinates),
        ];

        $accuracy = $value['accuracy'] ?? null;
        if (Coercion::isNumericLike($accuracy)) {
            $envelope['accuracy'] = Coercion::toNumber($accuracy);
        }

        return [true, $envelope, null];
    }

    /**
     * Recursively coerce numeric-string ordinates to numbers, preserving the array nesting (Point =
     * flat `[lon,lat]`; LineString = `[[lon,lat],…]`; Polygon = `[[[lon,lat],…]]`). Non-numeric leaves
     * are left as-is for Stage-3 processGeo to flag (`geo_out_of_range`).
     *
     * @param  array<int|string, mixed>  $coordinates
     * @return array<int|string, mixed>
     */
    private function coerceOrdinates(array $coordinates): array
    {
        return array_map(function (mixed $element): mixed {
            if (is_array($element)) {
                return $this->coerceOrdinates($element);
            }

            return Coercion::isNumericLike($element) ? Coercion::toNumber($element) : $element;
        }, $coordinates);
    }

    /**
     * Media capture (Increment G6) → the canonical `list<AttachmentRef>` where each ref is
     * `{id, mime?, size?, name?, width?, height?, duration?}`. Structural faults only: the value must be a
     * LIST (not an object/scalar → `expected_list`); each element must be an object carrying a non-empty
     * string `id` (else `media_malformed`). `id` is authoritative; the rest is display metadata, canonicalised
     * (strings kept, numerics coerced to int, unknown keys dropped). A blank entry is dropped; an all-empty
     * list normalises to not-stored (mirrors the isEmpty guard). Count/required are Stage-3 (processMedia);
     * existence/ownership/scan are the DB-backed AttachmentReferenceValidator.
     *
     * @return array{0: bool, 1: list<array<string, mixed>>|null, 2: array{field: string, rule: string, message: string}|null}
     */
    private function coerceMedia(FormField $field, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [false, null, $this->mismatch($field->key, 'expected_list', 'This field must be a list of uploaded files.')];
        }

        /** @var list<array<string, mixed>> $refs */
        $refs = [];
        foreach ($value as $index => $ref) {
            if (Coercion::isEmpty($ref)) {
                continue; // a blank entry is dropped, not a fault
            }
            if (! is_array($ref) || array_is_list($ref)) {
                return [false, null, $this->mismatch("{$field->key}[{$index}]", 'media_malformed', 'This uploaded file reference is malformed.')];
            }
            $id = $ref['id'] ?? null;
            if (! is_string($id) || $id === '') {
                return [false, null, $this->mismatch("{$field->key}[{$index}]", 'media_malformed', 'This uploaded file reference is missing its id.')];
            }
            $refs[] = $this->canonicalizeMediaRef($ref, $id);
        }

        return $refs === [] ? [false, null, null] : [true, $refs, null];
    }

    /**
     * Canonicalise one attachment-reference envelope: the authoritative `id`, then the display members that
     * are present and well-typed (string `mime`/`name`; numeric-string `size`/`width`/`height`/`duration`
     * coerced to int). Unknown keys are dropped so the stored shape stays tight and mirrors the TS `MediaAnswer`.
     *
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function canonicalizeMediaRef(array $ref, string $id): array
    {
        $canonical = ['id' => $id];

        foreach (['mime', 'name'] as $stringKey) {
            if (isset($ref[$stringKey]) && is_string($ref[$stringKey])) {
                $canonical[$stringKey] = $ref[$stringKey];
            }
        }

        foreach (['size', 'width', 'height', 'duration'] as $numericKey) {
            if (isset($ref[$numericKey]) && Coercion::isNumericLike($ref[$numericKey])) {
                $canonical[$numericKey] = (int) Coercion::toNumber($ref[$numericKey]);
            }
        }

        return $canonical;
    }

    /**
     * Matrix (Increment G4b) → the object shape `{row:{col:cell}}`. Structural, relevance-unaware faults
     * only: the value must be an object (not a list/scalar → `expected_object`); every row key must be a
     * declared `config.rows` value (`unknown_row`); each row's value must itself be an object; every column
     * key must be a declared `config.columns` value (`unknown_column`). Each surviving leaf cell is coerced
     * to a canonical string; empty cells and empty rows are dropped; an all-empty grid normalises to
     * not-stored. Cell-value membership (`cell ∈ config.cells`) is the relevance-aware Stage-3 check.
     *
     * @return array{0: bool, 1: array<string, array<string, string>>|null, 2: array{field: string, rule: string, message: string}|null}
     */
    private function coerceMatrix(FormField $field, mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [false, null, $this->mismatch($field->key, 'expected_object', 'This field must be a grid of answers.')];
        }

        $rows = $this->configValueSet($field, 'rows');
        $columns = $this->configValueSet($field, 'columns');

        /** @var array<string, array<string, string>> $normalized */
        $normalized = [];
        foreach ($value as $rawRowKey => $rowValue) {
            $rowKey = (string) $rawRowKey;
            if (! isset($rows[$rowKey])) {
                return [false, null, $this->mismatch("{$field->key}.{$rowKey}", 'unknown_row', "Unknown row: {$rowKey}.")];
            }
            if (Coercion::isEmpty($rowValue)) {
                continue;
            }
            if (! is_array($rowValue) || array_is_list($rowValue)) {
                return [false, null, $this->mismatch("{$field->key}.{$rowKey}", 'expected_object', "Row {$rowKey} must be an object of column answers.")];
            }

            /** @var array<string, string> $normalizedRow */
            $normalizedRow = [];
            foreach ($rowValue as $rawColKey => $cell) {
                $colKey = (string) $rawColKey;
                if (! isset($columns[$colKey])) {
                    return [false, null, $this->mismatch("{$field->key}.{$rowKey}.{$colKey}", 'unknown_column', "Unknown column: {$colKey}.")];
                }
                if (Coercion::isEmpty($cell)) {
                    continue;
                }
                if (is_array($cell)) {
                    return [false, null, $this->mismatch("{$field->key}.{$rowKey}.{$colKey}", 'expected_scalar', 'A grid cell must be a single value.')];
                }
                $normalizedRow[$colKey] = Coercion::toStr($cell);
            }

            if ($normalizedRow !== []) {
                $normalized[$rowKey] = $normalizedRow;
            }
        }

        return $normalized === [] ? [false, null, null] : [true, $normalized, null];
    }

    /**
     * Likert-matrix (Increment G4b) → the flat object `{row:score}`. Structural faults only (an object is
     * required → `expected_object`; every row key must be a declared `config.rows` value → `unknown_row`);
     * the row's score-membership (`score ∈ config.columns`) is the relevance-aware Stage-3 check. Surviving
     * scores are coerced to strings; empty rows dropped; an all-empty grid normalises to not-stored.
     *
     * @return array{0: bool, 1: array<string, string>|null, 2: array{field: string, rule: string, message: string}|null}
     */
    private function coerceLikertMatrix(FormField $field, mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            return [false, null, $this->mismatch($field->key, 'expected_object', 'This field must be a grid of answers.')];
        }

        $rows = $this->configValueSet($field, 'rows');

        /** @var array<string, string> $normalized */
        $normalized = [];
        foreach ($value as $rawRowKey => $score) {
            $rowKey = (string) $rawRowKey;
            if (! isset($rows[$rowKey])) {
                return [false, null, $this->mismatch("{$field->key}.{$rowKey}", 'unknown_row', "Unknown row: {$rowKey}.")];
            }
            if (Coercion::isEmpty($score)) {
                continue;
            }
            if (is_array($score)) {
                return [false, null, $this->mismatch("{$field->key}.{$rowKey}", 'expected_scalar', 'A grid row answer must be a single value.')];
            }
            $normalized[$rowKey] = Coercion::toStr($score);
        }

        return $normalized === [] ? [false, null, null] : [true, $normalized, null];
    }

    /**
     * The declared `config.<key>` (rows/columns/cells) value set for O(1) membership, keyed by option value.
     *
     * @return array<string, true>
     */
    private function configValueSet(FormField $field, string $configKey): array
    {
        $set = [];
        foreach ((array) data_get($field->config, $configKey, []) as $option) {
            if (is_array($option) && array_key_exists('value', $option) && $option['value'] !== null && $option['value'] !== '') {
                $set[Coercion::toStr($option['value'])] = true;
            }
        }

        return $set;
    }
}
