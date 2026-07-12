<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormField;
use App\Models\FormVersion;

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
