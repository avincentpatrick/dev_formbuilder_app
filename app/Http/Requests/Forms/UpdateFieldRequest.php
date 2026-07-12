<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A full field content edit from the config panel (Increment D4a). Validates SHAPE only — `key` format +
 * per-version uniqueness, the config jsonb is an object, enum members, and the validation-rows'
 * expression-XOR-rule_type invariant (a friendly mirror of the DB CHECK). Expressions are NOT semantically
 * validated (the expression engine is deferred ADR-0004 work). Authorization is `can:update,form`; the
 * optimistic-concurrency token (`version`) is checked in the service, not here.
 *
 * Per-type `config` shape (Increment G4a/G4b): the choice-editor types (`config.options` = `[{value,label}]`),
 * `cascading_select` (`config.levels`/`config.options` with `level`/`parent`), and the object-valued grids
 * `matrix` (`config.rows`/`columns`/`cells`) + `likert_matrix` (`config.rows`/`columns`) get type/shape
 * rules here — but only the SHAPE, kept lenient (every value nullable) so a mid-edit blur that transiently
 * clears an option value does not 422 the optimistic PATCH. Completeness, distinctness, and cascading /
 * grid integrity are enforced at PUBLISH (StructuralValidationGate), the same "persist unvalidated config,
 * validate at publish" posture as a calculated field's formula.
 */
final class UpdateFieldRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Form $form */
        $form = $this->route('form');
        /** @var FormField $field */
        $field = $this->route('field');

        return [
            ...$this->configRules($field->field_type),
            'key' => [
                'required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('form_fields', 'key')
                    ->where('form_version_id', $form->draft_version_id)
                    ->ignore($field->id),
            ],
            'label' => ['required', 'string', 'max:500'],
            'hint' => ['nullable', 'string', 'max:2000'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'is_required' => ['required', Rule::enum(RequiredMode::class)],
            'relevant_expression' => ['nullable', 'string', 'max:2000'],
            'appearance' => ['nullable', 'string', 'max:60'],
            'config' => ['present', 'array'],
            'default_value' => ['nullable', 'string', 'max:2000'],
            'is_pii' => ['boolean'],
            'is_sensitive' => ['boolean'],
            'is_queryable' => ['boolean'],
            'indexed_data_type' => ['nullable', Rule::enum(IndexedDataType::class)],
            'version' => ['nullable', 'string'],
            'validations' => ['present', 'array'],
            'validations.*.rule_type' => ['nullable', Rule::enum(ValidationRuleType::class)],
            'validations.*.operator' => ['nullable', Rule::enum(ComparisonOperator::class)],
            'validations.*.rule_value' => ['nullable', 'string', 'max:2000'],
            'validations.*.expression' => ['nullable', 'string', 'max:2000'],
            'validations.*.error_message' => ['nullable', 'string', 'max:500'],
            'validations.*.related_field_key' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * The per-type `config` shape rules (lenient — type/structure only; see the class docblock). A choice
     * type validates its `options` list; `cascading_select` validates its `levels` + parented `options`.
     * All values are `nullable` so a transient mid-edit state never rejects the optimistic PATCH.
     *
     * @return array<string, array<int, mixed>>
     */
    private function configRules(FieldType $type): array
    {
        if ($type === FieldType::CascadingSelect) {
            return [
                'config.levels' => ['sometimes', 'array'],
                'config.levels.*.key' => ['nullable', 'string', 'max:150'],
                'config.levels.*.label' => ['nullable', 'string', 'max:255'],
                'config.options' => ['sometimes', 'array'],
                'config.options.*.value' => ['nullable', 'string', 'max:255'],
                'config.options.*.label' => ['nullable', 'string', 'max:500'],
                'config.options.*.level' => ['nullable', 'string', 'max:150'],
                'config.options.*.parent' => ['nullable', 'string', 'max:255'],
            ];
        }

        if ($type === FieldType::Matrix) {
            return [
                'config.rows' => ['sometimes', 'array'],
                'config.rows.*.value' => ['nullable', 'string', 'max:255'],
                'config.rows.*.label' => ['nullable', 'string', 'max:500'],
                'config.columns' => ['sometimes', 'array'],
                'config.columns.*.value' => ['nullable', 'string', 'max:255'],
                'config.columns.*.label' => ['nullable', 'string', 'max:500'],
                'config.cells' => ['sometimes', 'array'],
                'config.cells.*.value' => ['nullable', 'string', 'max:255'],
                'config.cells.*.label' => ['nullable', 'string', 'max:500'],
            ];
        }

        if ($type === FieldType::LikertMatrix) {
            return [
                'config.rows' => ['sometimes', 'array'],
                'config.rows.*.value' => ['nullable', 'string', 'max:255'],
                'config.rows.*.label' => ['nullable', 'string', 'max:500'],
                'config.columns' => ['sometimes', 'array'],
                'config.columns.*.value' => ['nullable', 'string', 'max:255'],
                'config.columns.*.label' => ['nullable', 'string', 'max:500'],
            ];
        }

        if ($type->configEditor() === 'choices') {
            return [
                'config.options' => ['sometimes', 'array'],
                'config.options.*.value' => ['nullable', 'string', 'max:255'],
                'config.options.*.label' => ['nullable', 'string', 'max:500'],
            ];
        }

        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->input('validations', []);

            foreach ($rows as $i => $row) {
                $hasExpression = ($row['expression'] ?? '') !== '';
                $hasRule = ($row['rule_type'] ?? '') !== '';

                // Mirrors the form_field_validations XOR CHECK: exactly one of expression / rule_type.
                if ($hasExpression === $hasRule) {
                    $validator->errors()->add(
                        "validations.{$i}",
                        'A validation rule must be either a structured rule or an expression — not both, not neither.',
                    );
                }
            }
        });
    }
}
