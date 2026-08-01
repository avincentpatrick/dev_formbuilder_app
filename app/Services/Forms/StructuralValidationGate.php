<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\PrefillSource;
use App\Enums\RequiredMode;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Services\Validation\SemanticValidator;
use Illuminate\Support\Collection;

/**
 * The pre-publish structural gate (form-versioning-schema-migration.md §4). A draft that fails any check
 * is not publishable; the SPECIFIC violation is thrown (naming the field) so the builder can point at it,
 * rather than a generic failure. The `expression XOR rule_type` rule is omitted here because it is a hard
 * DB CHECK (a violating row cannot exist).
 */
final class StructuralValidationGate
{
    public function assertPublishable(FormVersion $version): void
    {
        $fields = $version->fields()->get();
        $sections = $version->sections()->get();
        $sectionIds = $sections->pluck('id')->flip();
        $repeatableSectionIds = $sections->where('is_repeatable', true)->pluck('id')->flip();
        $validations = $version->validations()->get();

        $fieldIds = $fields->pluck('id')->flip();
        $fieldKeyById = $fields->pluck('key', 'id');

        // Increment H7 — which fields own at least one validation row, so the hidden-field check below is a
        // set lookup rather than a per-field query.
        $fieldIdsWithValidations = $validations->pluck('form_field_id')->flip();

        foreach ($fields as $field) {
            // Every field's section, when set, belongs to the same version.
            if ($field->form_section_id !== null && ! $sectionIds->has($field->form_section_id)) {
                throw PublishValidationException::sectionBelongsToForeignVersion($field->key);
            }
            // Every queryable field declares an indexed data type (the projection job needs it).
            if ($field->is_queryable && $field->indexed_data_type === null) {
                throw PublishValidationException::queryableFieldMissingType($field->key);
            }
            // Increment G4a: the new choice-family types must be publishably answerable.
            if ($field->field_type === FieldType::LikertScale) {
                $this->assertChoiceOptionsResolve($field);
            }
            if ($field->field_type === FieldType::CascadingSelect) {
                $this->assertCascadingResolves($field);
            }
            // Increment G4b: composite grid config must resolve, and a grid may not sit in a repeatable
            // section (its object value is never routed through the composite pass inside a repeat instance).
            if ($field->field_type === FieldType::Matrix || $field->field_type === FieldType::LikertMatrix) {
                if ($field->form_section_id !== null && $repeatableSectionIds->has($field->form_section_id)) {
                    throw PublishValidationException::compositeInRepeatableSection($field->key);
                }
                $this->assertMatrixConfigResolves($field);
            }
            // Increment G5b1: geo carries no config to validate, but its geometry projection is one row per
            // field per submission (top-level only), so a geo field may not sit in a repeatable section
            // (relaxing this later — projecting per-instance geo — is non-breaking).
            if ($field->field_type->isGeo()) {
                if ($field->form_section_id !== null && $repeatableSectionIds->has($field->form_section_id)) {
                    throw PublishValidationException::geoInRepeatableSection($field->key);
                }
            }
            // Increment G6: media config is wholly optional (so there is no required-config gate), but its
            // attachment ownership + attachment_refs are per-submission (top-level), so a media field may not
            // sit in a repeatable section (relaxing this later — instance-addressed media — is non-breaking);
            // and any configured count bounds must be coherent (min ≤ max).
            if ($field->field_type->isMedia()) {
                if ($field->form_section_id !== null && $repeatableSectionIds->has($field->form_section_id)) {
                    throw PublishValidationException::mediaInRepeatableSection($field->key);
                }
                $this->assertMediaConfigResolves($field);
            }
            // Increment H7: a hidden field is the one type the respondent can neither see nor repair, so it
            // must be incapable of producing an error; and neither prefill source can address one instance
            // of a repeat, so it may not sit in a repeatable section.
            if ($field->field_type === FieldType::Hidden) {
                if ($field->form_section_id !== null && $repeatableSectionIds->has($field->form_section_id)) {
                    throw PublishValidationException::hiddenInRepeatableSection($field->key);
                }
                $this->assertHiddenFieldAnswerable($field, $fieldIdsWithValidations);
                $this->assertPrefillConfigResolves($field);
            }
        }

        // Every validation's owning + comparison field belongs to the same version.
        foreach ($validations as $validation) {
            $ownerKey = $fieldKeyById->get($validation->form_field_id, '(unknown)');

            if (! $fieldIds->has($validation->form_field_id)) {
                throw PublishValidationException::validationReferencesForeignVersion($ownerKey);
            }
            if ($validation->related_form_field_id !== null && ! $fieldIds->has($validation->related_form_field_id)) {
                throw PublishValidationException::validationReferencesForeignVersion($ownerKey);
            }
        }
    }

    /**
     * A `hidden` field (Increment H7) must not be able to generate a respondent-facing error.
     *
     * Nobody can see, focus, or answer a hidden field, so a `required`/`conditional` mark or a validation
     * rule on one produces a submit that fails forever with no repair available — and in the SPA, an
     * error-summary entry pointing at a row that does not render. The runtime already declines to evaluate
     * these ({@see SemanticValidator::collectFieldErrors()} and its TS twin), so
     * shipping such a form would not break anything; it would silently do nothing, which is worse for the
     * author. Refusing it at publish is how they find out.
     *
     * An author who needs a guaranteed value uses a `fixed` prefill source, which is always present because
     * the server writes it.
     *
     * @param  Collection<string, int>  $fieldIdsWithValidations
     */
    private function assertHiddenFieldAnswerable(FormField $field, Collection $fieldIdsWithValidations): void
    {
        if ($field->is_required !== RequiredMode::Optional) {
            throw PublishValidationException::hiddenFieldNotAnswerable($field->key, 'hidden_field_required');
        }
        if ($fieldIdsWithValidations->has($field->id)) {
            throw PublishValidationException::hiddenFieldNotAnswerable($field->key, 'hidden_field_has_validations');
        }
    }

    /**
     * A `hidden` field sourced from the link (Increment H7) must declare a usable query-parameter name.
     *
     * The name is what an author puts in a shared URL, so it is constrained to the characters that survive
     * one without encoding, and length-capped so it cannot become a payload in its own right. A blank/absent
     * name is legal and falls back to the field's own key ({@see PrefillSource::urlParam()}) — the author
     * already opted in via `prefill_source`, so the fallback widens nothing.
     */
    private function assertPrefillConfigResolves(FormField $field): void
    {
        if (PrefillSource::for($field->field_type, $field->config) !== PrefillSource::Url) {
            return;
        }

        $declared = data_get($field->config, 'url_param');
        if ($declared === null || $declared === '') {
            return; // falls back to the field key
        }

        if (! is_string($declared) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $declared) !== 1) {
            throw PublishValidationException::prefillConfigInvalid($field->key, 'prefill_param_invalid');
        }
    }

    /**
     * A `likert_scale` (Increment G4a) must offer at least one option and no duplicate option values, or it
     * is unanswerable / ambiguous at submit time (and Stage-3 membership would silently accept nothing).
     */
    private function assertChoiceOptionsResolve(FormField $field): void
    {
        $values = [];
        foreach ((array) data_get($field->config, 'options', []) as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option) || $option['value'] === null || $option['value'] === '') {
                throw PublishValidationException::choiceOptionsInvalid($field->key, 'an option is missing its value');
            }
            $values[] = (string) $option['value'];
        }

        if ($values === []) {
            throw PublishValidationException::choiceOptionsInvalid($field->key, 'no options defined');
        }
        if (count($values) !== count(array_unique($values))) {
            throw PublishValidationException::choiceOptionsInvalid($field->key, 'duplicate option values');
        }
    }

    /**
     * A `cascading_select` (Increment G4a) hierarchy must resolve: at least one level and one option, every
     * option pinned to a declared level, every declared level populated, and every non-root option's
     * `parent` pointing at a value that exists at the immediately-shallower level (no dangling parent).
     */
    private function assertCascadingResolves(FormField $field): void
    {
        $levels = [];
        foreach ((array) data_get($field->config, 'levels', []) as $level) {
            $key = is_array($level) ? (string) ($level['key'] ?? '') : (string) $level;
            if ($key === '') {
                throw PublishValidationException::cascadingConfigInvalid($field->key, 'a level is missing its key');
            }
            $levels[] = $key;
        }
        if ($levels === []) {
            throw PublishValidationException::cascadingConfigInvalid($field->key, 'no levels defined');
        }

        $levelIndex = array_flip($levels);
        /** @var array<int, array<string, true>> $valuesByLevelIndex */
        $valuesByLevelIndex = [];
        /** @var list<array{level: int, value: string, parent: ?string}> $options */
        $options = [];

        foreach ((array) data_get($field->config, 'options', []) as $option) {
            if (! is_array($option)) {
                continue;
            }
            $levelKey = (string) ($option['level'] ?? '');
            $value = (string) ($option['value'] ?? '');
            if ($value === '') {
                throw PublishValidationException::cascadingConfigInvalid($field->key, 'an option is missing its value');
            }
            if (! array_key_exists($levelKey, $levelIndex)) {
                throw PublishValidationException::cascadingConfigInvalid($field->key, "option “{$value}” references an unknown level");
            }
            $index = $levelIndex[$levelKey];
            $parent = array_key_exists('parent', $option) && $option['parent'] !== null ? (string) $option['parent'] : null;
            $valuesByLevelIndex[$index][$value] = true;
            $options[] = ['level' => $index, 'value' => $value, 'parent' => $parent];
        }

        if ($options === []) {
            throw PublishValidationException::cascadingConfigInvalid($field->key, 'no options defined');
        }

        foreach (array_keys($levels) as $index) {
            if (! isset($valuesByLevelIndex[$index])) {
                throw PublishValidationException::cascadingConfigInvalid($field->key, "level “{$levels[$index]}” has no options");
            }
        }

        foreach ($options as $option) {
            if ($option['level'] === 0) {
                continue; // root options need no parent
            }
            $parent = $option['parent'];
            if ($parent === null || ! isset($valuesByLevelIndex[$option['level'] - 1][$parent])) {
                throw PublishValidationException::cascadingConfigInvalid($field->key, "option “{$option['value']}” has no valid parent");
            }
        }
    }

    /**
     * A media field's (Increment G6) count bounds, when both are set, must satisfy `min_count ≤ max_count`
     * (otherwise no submission could ever satisfy the field). Every media config value is optional, so an
     * unset bound is never a publish error.
     */
    private function assertMediaConfigResolves(FormField $field): void
    {
        $min = data_get($field->config, 'min_count');
        $max = data_get($field->config, 'max_count');

        if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
            throw PublishValidationException::mediaConfigInvalid($field->key, 'the minimum count exceeds the maximum count');
        }
    }

    /**
     * A composite grid (Increment G4b) must be publishably answerable: `rows` + `columns` non-empty with
     * distinct, present values, and — for `matrix`, whose cells are single-selects — a non-empty `cells`
     * choice pool. (`likert_matrix` reuses `columns` as the score scale and has no `cells`.)
     */
    private function assertMatrixConfigResolves(FormField $field): void
    {
        $this->assertOptionListResolves($field, 'rows', 'rows');
        $this->assertOptionListResolves($field, 'columns', 'columns');

        if ($field->field_type === FieldType::Matrix) {
            $this->assertOptionListResolves($field, 'cells', 'cells');
        }
    }

    /**
     * Assert a `config.<key>` option list ({value,label}[]) is non-empty, every option carries a non-empty
     * `value`, and the values are distinct — the shared shape check behind the grid rows/columns/cells.
     */
    private function assertOptionListResolves(FormField $field, string $configKey, string $label): void
    {
        $values = [];
        foreach ((array) data_get($field->config, $configKey, []) as $option) {
            if (! is_array($option) || ! array_key_exists('value', $option) || $option['value'] === null || $option['value'] === '') {
                throw PublishValidationException::matrixConfigInvalid($field->key, "a {$label} entry is missing its value");
            }
            $values[] = (string) $option['value'];
        }

        if ($values === []) {
            throw PublishValidationException::matrixConfigInvalid($field->key, "no {$label} defined");
        }
        if (count($values) !== count(array_unique($values))) {
            throw PublishValidationException::matrixConfigInvalid($field->key, "duplicate {$label} values");
        }
    }
}
