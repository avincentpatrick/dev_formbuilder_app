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
use App\Models\FormField;
use App\Rules\ContentBlocks;
use App\Services\Expressions\DateMath;
use App\Services\Expressions\Rounding;
use App\Support\Forms\MatrixColumns;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A full field content edit from the config panel (Increment D4a). Validates SHAPE only — `key` format (a key
 * another question holds is suffixed by the service since M172, never refused), the config jsonb is an object, enum members, and the validation-rows'
 * expression-XOR-rule_type invariant (a friendly mirror of the DB CHECK). Expressions are NOT semantically
 * validated (the expression engine is deferred ADR-0004 work). Authorization is `can:update,form`; the
 * optimistic-concurrency token (`version`) is checked in the service, not here.
 *
 * Per-type `config` shape (Increment G4a/G4b/G5b2b): the choice-editor types (`config.options` = `[{value,label}]`),
 * `cascading_select` (`config.levels`/`config.options` with `level`/`parent`), the object-valued grids
 * `matrix` (`config.rows`/`columns`/`cells`, each column's own `type`/`options`/`required` since M183) + `likert_matrix`
 * (`config.rows`/`columns`), both with `config.layout`, and the geospatial
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
        /** @var FormField $field */
        $field = $this->route('field');

        return [
            ...$this->configRules($field->field_type),
            // M172 (`R-733eff09`, `D132`, closing `R-4c4e35ae`): NO uniqueness rule. A key another question holds is
            // adjusted by the service (`FormBuilderService::freeKey()`, a numbered suffix), never refused — the user's
            // "auto adjust the key naming to avoid conflicts". The rule this replaced read the form's CURRENT draft, so
            // a page left open across a publish was told "The key has already been taken" about its own unchanged key;
            // that page is now refused 409 by `builder.draft` before anything here runs.
            'key' => ['required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:500'],
            'hint' => ['nullable', 'string', 'max:2000'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'is_required' => ['required', Rule::enum(RequiredMode::class)],
            'relevant_expression' => ['nullable', 'string', 'max:2000'],
            'appearance' => ['nullable', 'string', 'max:60', Rule::in($this->allowedAppearances($field))],
            'config' => ['present', 'array'],
            // M177 (`R-f44e9d00`): Show to respondents — Never (`hidden`) or Always (`shown`); absent keeps the type's own rule.
            'config.visibility' => ['sometimes', 'nullable', 'in:hidden,shown'],
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
     * What each path is CALLED when a save is refused (M146, `R-b2d4a2c5`). With no `lang/` directory Laravel
     * writes the raw path — "The config.options.0.label field must not be greater than 500 characters." — and the
     * builder's save alert shows the server's message as sent. This request is the one place that knows every
     * path, so it names every one; keyed by the WILDCARD path, which Laravel resolves for the numbered one. The
     * same reasoning as `StoreConnectionRuleRequest::attributes()`.
     *
     * ⛔ EVERY KEY `rules()` CAN RETURN, FOR EVERY FIELD TYPE, HAS AN ENTRY HERE, and `FieldSaveAttributeNamesTest`
     * refuses a new path that ships without one. Plain words only: a dot or an underscore in a name is a path leak.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'key' => 'key',
            'label' => 'label',
            'hint' => 'hint',
            'placeholder' => 'placeholder',
            'is_required' => 'required setting',
            'relevant_expression' => 'show-when condition',
            'appearance' => 'layout',
            'config' => 'settings',
            'default_value' => 'default value',
            'default_value_is_expression' => 'default value kind',
            'is_pii' => 'personal data flag',
            'is_sensitive' => 'sensitive flag',
            'is_queryable' => 'queryable flag',
            'indexed_data_type' => 'indexed type',
            'version' => 'version',
            'validations' => 'rules',
            'validations.*.rule_type' => 'rule type',
            'validations.*.operator' => 'rule operator',
            'validations.*.rule_value' => 'rule value',
            'validations.*.expression' => 'rule expression',
            'validations.*.error_message' => 'rule message',
            'validations.*.related_field_key' => 'compared question',
            'validations.*.logic_group' => 'rule group',
            'validations.*.logic_operator' => 'group operator',
            // Media.
            'config.accepted_types' => 'accepted file types',
            'config.accepted_types.*' => 'accepted file type',
            'config.max_file_size_bytes' => 'maximum file size',
            'config.max_count' => 'maximum number of files',
            'config.min_count' => 'minimum number of files',
            'config.capture_source' => 'capture source',
            // Geospatial.
            'config.capture_altitude' => 'altitude capture',
            'config.accuracy_threshold' => 'accuracy threshold',
            'config.default_center' => 'default map centre',
            'config.default_center.lat' => 'default latitude',
            'config.default_center.lon' => 'default longitude',
            'config.default_zoom' => 'default zoom',
            // Choices, cascading select (its levels, and the level and parent on each choice), and a linked list.
            'config.visibility' => 'Show to respondents',
            // M180 — a calculation's display settings, named as the calculation editor names them.
            'config.calculated_type' => 'result type',
            'config.decimal_places' => 'decimal places',
            'config.duration_unit' => 'units',
            'config.options' => 'choices',
            'config.other_specify_key' => 'Please specify question',
            'config.options.*.value' => 'choice value',
            'config.options.*.label' => 'choice label',
            'config.options.*.level' => 'choice level',
            'config.options.*.parent' => 'choice parent',
            'config.options.*.label_translations' => 'choice label translations',
            'config.options.*.label_translations.*' => 'choice label translation',
            'config.options_source' => 'choices source',
            'config.options_source.form_id' => 'source form',
            'config.options_source.field_key' => 'source question',
            'config.options_list' => 'choice list',
            // M184 — where a Short text's suggestions come from.
            'config.suggestions_source' => 'suggestions source',
            'config.suggestions_source.kind' => 'where the suggestions come from',
            'config.suggestions_source.name' => 'suggestions list',
            'config.suggestions_source.form_id' => 'suggestions form',
            'config.suggestions_source.field_key' => 'suggestions question',
            'config.levels' => 'levels',
            'config.levels.*.key' => 'level key',
            'config.levels.*.label' => 'level label',
            'config.levels.*.label_translations' => 'level label translations',
            'config.levels.*.label_translations.*' => 'level label translation',
            'config.levels.*.list' => 'level choice list',
            'config.skip_single_choice_levels' => 'fill in a level with one choice',
            // The two grids.
            'config.rows' => 'rows',
            'config.rows.*.value' => 'row value',
            'config.rows.*.label' => 'row label',
            'config.rows.*.label_translations' => 'row label translations',
            'config.rows.*.label_translations.*' => 'row label translation',
            'config.columns' => 'columns',
            'config.columns.*.value' => 'column value',
            'config.columns.*.label' => 'column label',
            'config.columns.*.label_translations' => 'column label translations',
            'config.columns.*.label_translations.*' => 'column label translation',
            'config.columns.*.type' => 'column type',
            'config.columns.*.required' => 'column required',
            'config.columns.*.options' => 'column choices',
            'config.columns.*.options.*.value' => 'column choice value',
            'config.columns.*.options.*.label' => 'column choice label',
            'config.columns.*.options.*.label_translations' => 'column choice label translations',
            'config.columns.*.options.*.label_translations.*' => 'column choice label translation',
            // M185 — where a grid's rows, its shared cells and a column's choices come from.
            'config.rows_list' => 'rows list',
            'config.rows_source' => 'rows source',
            'config.rows_source.form_id' => 'rows form',
            'config.rows_source.field_key' => 'rows question',
            'config.cells_list' => 'cell choices list',
            'config.cells_source' => 'cell choices source',
            'config.cells_source.form_id' => 'cell choices form',
            'config.cells_source.field_key' => 'cell choices question',
            'config.columns.*.options_list' => 'column choices list',
            'config.columns.*.options_source' => 'column choices source',
            'config.columns.*.options_source.form_id' => 'column choices form',
            'config.columns.*.options_source.field_key' => 'column choices question',
            'config.layout' => 'layout',
            'config.cells' => 'cells',
            'config.cells.*.value' => 'cell value',
            'config.cells.*.label' => 'cell label',
            'config.cells.*.label_translations' => 'cell label translations',
            'config.cells.*.label_translations.*' => 'cell label translation',
            // Hidden, and a note.
            'config.prefill_source' => 'value source',
            'config.url_param' => 'URL parameter',
            'config.content' => 'content',
        ];
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

        // M180 (`R-f77aa45d`): a calculation's Type, a Number's decimal places and a Duration's unit — how its value is shown.
        if ($type === FieldType::Calculated) {
            return [
                'config.calculated_type' => ['sometimes', 'nullable', Rule::in(['number', 'text', 'date', 'duration'])],
                'config.decimal_places' => ['sometimes', 'nullable', 'integer', 'between:0,'.Rounding::MAX_PLACES],
                'config.duration_unit' => ['sometimes', 'nullable', Rule::in(DateMath::UNITS)],
            ];
        }

        if ($type === FieldType::CascadingSelect) {
            return [
                'config.levels' => ['sometimes', 'array'],
                'config.levels.*.key' => ['nullable', 'string', 'max:150'],
                'config.levels.*.label' => ['nullable', 'string', 'max:255'],
                'config.levels.*.label_translations' => ['sometimes', 'array'],
                'config.levels.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                // M141 (`D95`) — the CSV choice list a level takes its choices from, by name (`FormChoiceListService::nameFor()`);
                // since M186 (`R-70c7673a`) or one of the four built-in Philippine places levels (`PsgcLists`). An upload's
                // name never holds a colon, so the two cannot be confused.
                'config.levels.*.list' => ['nullable', 'string', 'max:64', 'regex:/^(psgc:(region|province|city|barangay)|[a-z0-9_-]+)$/'],
                // M186: a level with exactly one choice under the one picked above is filled in for the respondent.
                'config.skip_single_choice_levels' => ['sometimes', 'boolean:strict'],
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
                // M183 (`R-5033e87e`, `D129`): a column's own type, choices and required flag (`MatrixColumns`).
                'config.columns.*.type' => ['nullable', 'string', Rule::in(array_map(static fn (FieldType $type): string => $type->value, MatrixColumns::TYPES))],
                'config.columns.*.required' => ['sometimes', 'boolean:strict'],
                'config.columns.*.options' => ['sometimes', 'array'],
                'config.columns.*.options.*.value' => ['nullable', 'string', 'max:255'],
                'config.columns.*.options.*.label' => ['nullable', 'string', 'max:500'],
                'config.columns.*.options.*.label_translations' => ['sometimes', 'array'],
                'config.columns.*.options.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                'config.cells' => ['sometimes', 'array'],
                'config.cells.*.value' => ['nullable', 'string', 'max:255'],
                'config.cells.*.label' => ['nullable', 'string', 'max:500'],
                'config.cells.*.label_translations' => ['sometimes', 'array'],
                'config.cells.*.label_translations.*' => ['nullable', 'string', 'max:500'],
                // M183 (`R-1c1d7266`): cards at every width instead of a table where one fits; absent = the table.
                'config.layout' => ['nullable', Rule::in(['table', 'stacked'])],
                // M185 (`R-bb79aec0`, `D161`): the rows, the shared cells and a column's choices from a list file or another
                // form — shape only, as for a choice question's; whether each can serve is publish's (`GridSources`).
                ...self::gridSourceRules('config.rows'),
                ...self::gridSourceRules('config.cells'),
                ...self::gridSourceRules('config.columns.*.options'),
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
                'config.layout' => ['nullable', Rule::in(['table', 'stacked'])],
                // M185: a Likert matrix's statements may come from a list or a form; its scale stays typed.
                ...self::gridSourceRules('config.rows'),
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
                // M184 (`R-3e7c81bb`): choices taken from a list file of this form, by the list's name — empty while the author
                // is still choosing; whether the list exists is publish's, as a cascade level's list is.
                'config.options_list' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]*$/'],
                // M177 (`R-71509975`): the key of the Please specify question "Other, please specify" made.
                'config.other_specify_key' => ['sometimes', 'nullable', 'string', 'max:150'],
            ];
        }

        // M184 (`R-596d6afd`): where a Short text's suggestions come from — a list file by its name, or another form's
        // question. Shape only, as for a choice question's sources; whether either can serve is publish's.
        if ($type === FieldType::ShortText) {
            return [
                'config.suggestions_source' => ['sometimes', 'nullable', 'array:kind,name,form_id,field_key'],
                'config.suggestions_source.kind' => ['required_with:config.suggestions_source', Rule::in(['list', 'form'])],
                'config.suggestions_source.name' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]*$/'],
                'config.suggestions_source.form_id' => ['nullable', 'uuid'],
                'config.suggestions_source.field_key' => ['nullable', 'string', 'max:255'],
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
     * M185 (`R-bb79aec0`): a grid slot's `<items>_list` and `<items>_source`, the same shapes a choice question's
     * `options_list` and `options_source` take — `config.columns.*.options` makes `config.columns.*.options_list`.
     *
     * @return array<string, list<string>>
     */
    private static function gridSourceRules(string $items): array
    {
        return [
            "{$items}_list" => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]*$/'],
            "{$items}_source" => ['sometimes', 'nullable', 'array:form_id,field_key'],
            "{$items}_source.form_id" => ['nullable', 'uuid'],
            "{$items}_source.field_key" => ['nullable', 'string', 'max:255'],
        ];
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

                // Mirrors the form_field_validations XOR CHECK: exactly one of expression / rule_type. M176 (`R-5554c526`): in
                // plain words, one sentence per real case, naming no rule — the builder puts the rule's number on screen in
                // front ("Rule 2: …"). It read "must be either a structured rule or an expression — not both, not neither".
                if ($hasExpression === $hasRule) {
                    $validator->errors()->add(
                        "validations.{$i}",
                        $hasRule
                            ? 'Has both a check and an expression. Keep one of them.'
                            : 'Empty. Choose what it checks, or remove it.',
                    );
                }
            }
        });
    }
}
