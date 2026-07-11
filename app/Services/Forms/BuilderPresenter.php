<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use Illuminate\Support\Collection;

/**
 * Read model for the interactive builder (Increment D4a). Hydrates the form's current draft version into
 * the flat, id-stable shape the Vue builder edits — plus the palette catalog (all 31 field types grouped
 * by category, from the {@see FieldType} enum, the single source) and the enum option lists the config
 * panels bind to. `version` on each row is the optimistic-concurrency token ({@see FormBuilderService::rowVersion}).
 */
final class BuilderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Form $form): array
    {
        $draft = $form->draft_version_id !== null
            ? FormVersion::query()->whereKey($form->draft_version_id)->first()
            : null;

        $sections = $draft
            ? $draft->sections()->orderBy('sequence')->get()->map(fn (FormSection $s) => $this->section($s))->all()
            : [];

        $validationsByField = $draft
            ? $draft->validations()->orderBy('sequence')->get()->groupBy('form_field_id')
            : collect();
        $fieldKeyById = $draft ? $draft->fields()->pluck('key', 'id') : collect();

        $fields = $draft
            ? $draft->fields()->orderBy('sequence')->get()
                ->map(fn (FormField $f) => $this->field($f, $validationsByField->get($f->id), $fieldKeyById))
                ->all()
            : [];

        return [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'status' => $form->status->value,
            ],
            'draft' => $draft ? [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
            ] : null,
            'sections' => $sections,
            'fields' => $fields,
            'palette' => $this->palette(),
            'enums' => $this->enums(),
        ];
    }

    /**
     * @param  Collection<int, FormFieldValidation>|null  $validations
     * @param  Collection<int|string, string>  $fieldKeyById
     * @return array<string, mixed>
     */
    public function field(FormField $field, $validations = null, $fieldKeyById = null): array
    {
        $validations ??= $field->validations()->orderBy('sequence')->get();
        $fieldKeyById ??= FormField::query()->where('form_version_id', $field->form_version_id)->pluck('key', 'id');

        return [
            'id' => $field->id,
            'form_section_id' => $field->form_section_id,
            'key' => $field->key,
            'field_type' => $field->field_type->value,
            'label' => $field->label,
            'hint' => $field->hint,
            'placeholder' => $field->placeholder,
            'is_required' => $field->is_required->value,
            'relevant_expression' => $field->relevant_expression,
            'appearance' => $field->appearance,
            'config' => (object) ($field->config ?? []),
            'default_value' => $field->default_value,
            'is_pii' => $field->is_pii,
            'is_sensitive' => $field->is_sensitive,
            'is_queryable' => $field->is_queryable,
            'indexed_data_type' => $field->indexed_data_type?->value,
            'sequence' => $field->sequence,
            'section_sequence' => $field->section_sequence,
            'version' => FormBuilderService::rowVersion($field),
            'validations' => $validations->map(fn (FormFieldValidation $v) => [
                'rule_type' => $v->rule_type?->value,
                'operator' => $v->operator?->value,
                'rule_value' => $v->rule_value,
                'expression' => $v->expression,
                'error_message' => $v->error_message,
                'related_field_key' => $v->related_form_field_id !== null
                    ? $fieldKeyById->get($v->related_form_field_id)
                    : null,
                'sequence' => $v->sequence,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(FormSection $section): array
    {
        return [
            'id' => $section->id,
            'key' => $section->key,
            'label' => $section->label,
            'description' => $section->description,
            'is_repeatable' => $section->is_repeatable,
            'min_instances' => $section->min_instances,
            'max_instances' => $section->max_instances,
            'relevant_expression' => $section->relevant_expression,
            'sequence' => $section->sequence,
            'version' => FormBuilderService::rowVersion($section),
        ];
    }

    /**
     * The palette: every FieldType grouped by category, with labels/icons + the advanced flag. Built from
     * the enum so the frontend never re-lists the 31 types.
     *
     * @return list<array<string, mixed>>
     */
    private function palette(): array
    {
        $groups = [];

        foreach (FieldType::cases() as $type) {
            $category = $type->category();
            $groups[$category->value]['category'] = $category->value;
            $groups[$category->value]['label'] = $category->label();
            $groups[$category->value]['icon'] = $category->icon();
            $groups[$category->value]['types'][] = [
                'value' => $type->value,
                'label' => $type->label(),
                'advanced' => $type->isAdvanced(),
                'has_options' => $type->hasOptions(),
                'config_editor' => $type->configEditor(),
            ];
        }

        return array_values($groups);
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function enums(): array
    {
        return [
            'required_modes' => [
                ['value' => RequiredMode::Optional->value, 'label' => 'Optional'],
                ['value' => RequiredMode::Required->value, 'label' => 'Required'],
                ['value' => RequiredMode::Conditional->value, 'label' => 'Conditional'],
            ],
            'indexed_data_types' => array_map(
                fn (IndexedDataType $t) => ['value' => $t->value, 'label' => ucfirst($t->value)],
                IndexedDataType::cases(),
            ),
            'validation_rule_types' => array_map(
                fn (ValidationRuleType $t) => ['value' => $t->value, 'label' => $this->humanize($t->value)],
                ValidationRuleType::cases(),
            ),
            'comparison_operators' => array_map(
                fn (ComparisonOperator $t) => ['value' => $t->value, 'label' => $this->humanize($t->value)],
                ComparisonOperator::cases(),
            ),
        ];
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
