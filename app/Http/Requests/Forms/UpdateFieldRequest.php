<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\ComparisonOperator;
use App\Enums\FieldAppearance;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\LogicOperator;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\Form;
use App\Models\FormField;
use App\Rules\ContentBlocks;
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
 * Per-type `config` shape (Increment G4a/G4b/G5b2b): the choice-editor types (`config.options` = `[{value,label}]`),
 * `cascading_select` (`config.levels`/`config.options` with `level`/`parent`), the object-valued grids
 * `matrix` (`config.rows`/`columns`/`cells`) + `likert_matrix` (`config.rows`/`columns`), and the geospatial
 * types (`config.capture_altitude`/`accuracy_threshold`/`default_center {lat,lon}`/`default_zoom`) get
 * type/shape rules here — but only the SHAPE, kept lenient (every value nullable) so a mid-edit blur that
 * transiently clears a value does not 422 the optimistic PATCH. Completeness, distinctness, and cascading /
 * grid integrity are enforced at PUBLISH (StructuralValidationGate), the same "persist unvalidated config,
 * validate at publish" posture as a calculated field's formula (geo config is wholly optional, so it has no
 * publish-completeness gate). Media types (Increment G6: `config.accepted_types`/`max_file_size_bytes`/
 * `max_count`/`min_count`/`capture_source`) are likewise wholly optional; only min ≤ max is checked at publish.
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
            'appearance' => ['nullable', 'string', 'max:60', Rule::in($this->allowedAppearances($field))],
            'config' => ['present', 'array'],
            'default_value' => ['nullable', 'string', 'max:2000'],
            // M134 (`R-6d7b9ff7`) — optional: absent keeps the stored flag, so an older client cannot clear it by omission.
            'default_value_is_expression' => ['sometimes', 'boolean'],
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
            // M131 (`R-799d60f5`) — a rule's group: an existing group's uuid, or a token the builder minted for a
            // new one (`FormBuilderService::replaceValidations()` maps it). Ruled, or `validated()` strips them.
            'validations.*.logic_group' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'validations.*.logic_operator' => ['nullable', Rule::enum(LogicOperator::class)],
        ];
    }

    /**
     * What the service writes — `validated()`, with `config` put back together.
     *
     * ⛔ WHY THIS EXISTS AND WHY `validated()` IS NOT ENOUGH (M111, `R-d5d6db11`). `config` is declared
     * `present, array` ALONGSIDE the nested `config.*` rules above, and `Illuminate\Validation\Factory`
     * sets `$excludeUnvalidatedArrayKeys = true` on every validator it builds. `Validator::validated()`
     * therefore SKIPS the top-level key and rebuilds `config` from the enumerated paths only — so every
     * key no `configRules()` arm lists was silently dropped, on a 200 OK, by
     * `FormBuilderService::writeField()`'s whole-column replace. Option `label_translations` — written
     * by XLSForm import, read by both renderers, re-exported, and faithfully sent back by the builder —
     * was the reachable case: import a multilingual form, edit any choice field, lose every translation.
     *
     * ⛔ THE MERGE BASE IS THE REQUEST, NEVER THE STORED ROW, AND THAT IS THE WHOLE SAFETY ARGUMENT.
     * The builder sends the complete config on every save, so overlaying the validated tree onto the
     * RAW input preserves what the author sent while leaving a removal a removal. Merging the existing
     * column over the payload — the shape the word *merge* first suggests — would make it impossible to
     * clear a key: deleting the last choice row would leave the old `options` in place.
     * `FieldConfigRetentionTest` pins that direction explicitly, because it is the plausible wrong fix.
     *
     * ⚠️ REJECTED: setting `$excludeUnvalidatedArrayKeys = false` on the validator. The property IS
     * public, so the poke works — but it is instance-WIDE, so it would also stop pruning `validations`,
     * a behaviour change outside this defect's blast radius; and `withValidator()` receives the
     * CONTRACT, which does not declare the property. An accessor scoped to `config` is narrower on both
     * counts.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        /** @var array<string, mixed> $raw */
        $raw = $this->input('config', []);

        /** @var array<string, mixed> $validatedConfig */
        $validatedConfig = $data['config'] ?? [];

        $data['config'] = array_replace_recursive($raw, $validatedConfig);

        return $data;
    }

    /**
     * The per-type `config` shape rules (lenient — type/structure only; see the class docblock). A choice
     * type validates its `options` list; `cascading_select` validates its `levels` + parented `options`; a
     * geospatial type (Increment G5b2b) validates its map-capture options (all optional). All values are
     * `nullable`/`sometimes` so a transient mid-edit state never rejects the optimistic PATCH.
     *
     * @return array<string, array<int, mixed>>
     */
    private function configRules(FieldType $type): array
    {
        if ($type->isMedia()) {
            return [
                'config.accepted_types' => ['sometimes', 'array'],
                'config.accepted_types.*' => ['string', 'max:150'],
                'config.max_file_size_bytes' => ['nullable', 'integer', 'min:1'],
                'config.max_count' => ['nullable', 'integer', 'min:1'],
                'config.min_count' => ['nullable', 'integer', 'min:0'],
                'config.capture_source' => ['nullable', Rule::in(['camera', 'library', 'both'])],
            ];
        }

        if ($type->isGeo()) {
            return [
                'config.capture_altitude' => ['sometimes', 'boolean'],
                'config.accuracy_threshold' => ['nullable', 'numeric', 'min:0'],
                'config.default_center' => ['sometimes', 'nullable', 'array'],
                'config.default_center.lat' => ['nullable', 'numeric', 'between:-90,90'],
                'config.default_center.lon' => ['nullable', 'numeric', 'between:-180,180'],
                'config.default_zoom' => ['nullable', 'integer', 'between:0,22'],
            ];
        }

        if ($type === FieldType::CascadingSelect) {
            return [
                'config.levels' => ['sometimes', 'array'],
                'config.levels.*.key' => ['nullable', 'string', 'max:150'],
                'config.levels.*.label' => ['nullable', 'string', 'max:255'],
                'config.levels.*.label_translations' => ['sometimes', 'array'],
                'config.levels.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                'config.options' => ['sometimes', 'array'],
                'config.options.*.value' => ['nullable', 'string', 'max:255'],
                'config.options.*.label' => ['nullable', 'string', 'max:500'],
                'config.options.*.level' => ['nullable', 'string', 'max:150'],
                'config.options.*.parent' => ['nullable', 'string', 'max:255'],
                'config.options.*.label_translations' => ['sometimes', 'array'],
                'config.options.*.label_translations.*' => ['nullable', 'string', 'max:500'],
            ];
        }

        if ($type === FieldType::Matrix) {
            return [
                'config.rows' => ['sometimes', 'array'],
                'config.rows.*.value' => ['nullable', 'string', 'max:255'],
                'config.rows.*.label' => ['nullable', 'string', 'max:500'],
                'config.rows.*.label_translations' => ['sometimes', 'array'],
                'config.rows.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                'config.columns' => ['sometimes', 'array'],
                'config.columns.*.value' => ['nullable', 'string', 'max:255'],
                'config.columns.*.label' => ['nullable', 'string', 'max:500'],
                'config.columns.*.label_translations' => ['sometimes', 'array'],
                'config.columns.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                'config.cells' => ['sometimes', 'array'],
                'config.cells.*.value' => ['nullable', 'string', 'max:255'],
                'config.cells.*.label' => ['nullable', 'string', 'max:500'],
                'config.cells.*.label_translations' => ['sometimes', 'array'],
                'config.cells.*.label_translations.*' => ['nullable', 'string', 'max:500'],
            ];
        }

        if ($type === FieldType::LikertMatrix) {
            return [
                'config.rows' => ['sometimes', 'array'],
                'config.rows.*.value' => ['nullable', 'string', 'max:255'],
                'config.rows.*.label' => ['nullable', 'string', 'max:500'],
                'config.rows.*.label_translations' => ['sometimes', 'array'],
                'config.rows.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                'config.columns' => ['sometimes', 'array'],
                'config.columns.*.value' => ['nullable', 'string', 'max:255'],
                'config.columns.*.label' => ['nullable', 'string', 'max:500'],
                'config.columns.*.label_translations' => ['sometimes', 'array'],
                'config.columns.*.label_translations.*' => ['nullable', 'string', 'max:500'],
            ];
        }

        // Increment H7: where a hidden field's value comes from. Shape only, like every arm above —
        // `url_param`'s character set and the "a hidden field cannot require an answer" rule are the publish
        // gate's (StructuralValidationGate), so a mid-edit blur never 422s the optimistic PATCH.
        if ($type === FieldType::Hidden) {
            return [
                'config.prefill_source' => ['nullable', Rule::in(['fixed', 'url'])],
                'config.url_param' => ['nullable', 'string', 'max:64'],
            ];
        }

        if ($type->configEditor() === 'choices') {
            return [
                'config.options' => ['sometimes', 'array'],
                'config.options.*.value' => ['nullable', 'string', 'max:255'],
                'config.options.*.label' => ['nullable', 'string', 'max:500'],
                'config.options.*.label_translations' => ['sometimes', 'array'],
                'config.options.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                // M133: choices taken from another form's answers — shape only; whether the link can serve is publish's.
                'config.options_source' => ['sometimes', 'nullable', 'array:form_id,field_key'],
                'config.options_source.form_id' => ['nullable', 'uuid'],
                'config.options_source.field_key' => ['nullable', 'string', 'max:255'],
            ];
        }

        // Increment M125: a note's content blocks. ONE rule over the whole list and no wildcard sub-rules, because
        // payload() puts back whatever a sub-rule prunes — so an unknown key must refuse, never be dropped.
        if ($type === FieldType::Note) {
            return ['config.content' => ['sometimes', 'nullable', new ContentBlocks]];
        }

        return [];
    }

    /**
     * The appearances this save may write (M130, `R-6c76bed2`): a layout the stored type offers
     * (`FieldAppearance::for()`), or the value the field already holds, unchanged.
     *
     * ⛔ THE SECOND HALF IS WHAT KEEPS AN IMPORTED FORM EDITABLE. XLSForm import stores ODK appearances
     * verbatim (`likert`, `quick`, `minimal` on a multiple choice …) and the builder sends the whole field
     * back on every save, so refusing anything outside the vocabulary would refuse an imported question's
     * FIRST edit — the failure `FieldCreateRoundTripTest` records for M125. A stored value may be kept or
     * replaced; it can never be typed in fresh, because nothing in the builder types one any more.
     *
     * @return list<string>
     */
    private function allowedAppearances(FormField $field): array
    {
        $allowed = array_map(static fn (FieldAppearance $layout): string => $layout->value, FieldAppearance::for($field->field_type));

        if (is_string($field->appearance) && $field->appearance !== '') {
            $allowed[] = $field->appearance;
        }

        return $allowed;
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
