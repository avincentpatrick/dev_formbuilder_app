<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ComparisonOperator;
use App\Enums\FieldAppearance;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;
use App\Models\FieldLibrary;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use DateTimeZone;
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
     * The share block moved to {@see FormSharePresenter} in J2b, unchanged, because the form hub needs the
     * same payload and two encodings of "what is this form's public link" is J1e's audit-export defect over
     * again. `BuilderRoutesTest` and `ShareModal.test.ts` pass unedited, which is the proof it moved nothing.
     */
    public function __construct(
        private readonly FormSharePresenter $share,
        private readonly FormSettingsPresenter $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Form $form, ?User $viewer = null): array
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

        // M130 — the viewer, for the destination picker's list of forms they may open (FormSettingsPresenter).
        $settings = $this->settings->form($form, $viewer);

        return [
            // `id` and `status` are the builder's own. Every other key belongs to a settings section and comes
            // from {@see FormSettingsPresenter}, which the form hub's Settings tab reads too (M129, `D63`), in
            // the order these keys always had.
            'form' => [
                'id' => $form->id,
                'title' => $settings['title'],
                'description' => $settings['description'],
                'status' => $form->status->value,
                ...array_diff_key($settings, ['title' => true, 'description' => true]),
            ],
            'share' => $this->share->present($form),
            'draft' => $draft ? [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
            ] : null,
            'sections' => $sections,
            'fields' => $fields,
            'palette' => $this->palette(),
            'enums' => $this->enums(),
            'library' => $this->libraryList(),
            // The canonical IANA identifier list for the Schedule modal's timezone <select> (Increment H12b).
            // Sourced server-side so every option is guaranteed to pass UpdateFormScheduleRequest's
            // Rule::in(DateTimeZone::listIdentifiers()) — a client-built list could drift and 422.
            'timezones' => DateTimeZone::listIdentifiers(),
            // M129 — the Scanning section's facts, or null where this workspace cannot scan (the PATCH route's
            // gates). An OPTIONAL prop on the client, so a builder fixture without it still type-checks.
            'ocr_scanning' => $this->settings->ocrScanning($form),
        ];
    }

    /**
     * The question-library picker data (Increment G9b): the tenant's own + all platform items, active only,
     * "popular" first. RLS returns own + platform from a plain `query()`; delivered as an Inertia prop
     * (the builder's fetch sidecar has no GET) alongside `palette`/`enums`, refetchable via `forms.library-items`.
     *
     * @return list<array<string, mixed>>
     */
    public function libraryList(): array
    {
        return array_values(
            FieldLibrary::query()
                ->where('is_active', true)
                ->orderByDesc('usage_count')
                ->orderByDesc('id')
                ->get()
                ->map(fn (FieldLibrary $item): array => $this->libraryItem($item))
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function libraryItem(FieldLibrary $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'category' => $item->category,
            'field_type' => $item->field_type,
            'usage_count' => $item->usage_count,
            'is_platform' => $item->tenant_id === null,
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
     * The palette: every FieldType grouped by category, with labels/icons, the advanced flag and the variant
     * group (M125). Built from the enum so the frontend never re-lists the 31 types — or which are variants.
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
                // Increment M115 — what may be ASSERTED about this type's value. It rides here rather than
                // in `enums()` because the panel already builds its per-type maps from this list, and
                // because a per-type key in `enums()` would be a thirty-one-entry copy of a twelve-row table.
                'value_shape' => ValueShape::for($type)->value,
                // Increment M125 — additive: the palette shows ONE entry per group, and all 31 entries stay.
                'variant' => $type->variantGroup()?->paletteVariant($type),
                // Increment M130 — the layouts an author may choose for this type (`FieldAppearance::for()`),
                // transmitted rather than listed in the client; empty for every type with no layout setting.
                'appearances' => array_map(
                    static fn (FieldAppearance $appearance): array => ['value' => $appearance->value, 'label' => $appearance->label()],
                    FieldAppearance::for($type),
                ),
            ];
        }

        return array_values($groups);
    }

    /**
     * The option lists the config panels bind to.
     *
     * ⛔ THE APPLICABILITY LISTS ARE TRANSMITTED DATA, NOT A CLIENT MIRROR, AND THE DISTINCTION IS THE
     * WHOLE DESIGN (Increment M115). `shapes` is regenerated from {@see ValueShape} on every page load,
     * so it cannot drift from the enum the way a hand-written client set does — this repository has
     * measured ten of those. The client filters with it; it never re-states it. What would have been a
     * mirror is `ValidationEditor.vue` deciding for itself which rules suit which field, which is
     * exactly the defect `R-e878d49a` filed.
     *
     * ⚠️ KEYED ON A SHAPE RATHER THAN ON A FIELD TYPE, for the reason {@see ValueShape}'s docblock
     * gives: twelve shapes by eleven rule types is a table a person can read, and thirty-one by eleven
     * is not. The field's own shape rides on the palette entry ({@see palette()}), which is where the
     * panel already derives its per-type facts.
     *
     * @return array<string, list<array<string, mixed>>>
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
                fn (ValidationRuleType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    // Which value shapes may carry this rule at all — `ValueShape::allows()`, the same
                    // table the publish gate refuses on. The editor and the gate now answer to one source.
                    'shapes' => $this->shapesAllowing(fn (ValueShape $s) => $s->allows($t)),
                    'takes_operator' => $t->takesOperator(),
                    'takes_related_field' => $t->takesRelatedField(),
                    'operator_may_be_empty' => $t->operatorMayBeEmpty(),
                    // Increment M116 — whether this rule is what makes `Conditional` requiredness mean
                    // something, so the Basics tab's "Required when…" reveal can show exactly the rows the
                    // requiredness setting reads. Transmitted rather than mirrored for the same reason
                    // `shapes` is: a client-side list of two rule names is a second source that drifts.
                    'governs_requiredness' => $t->governsRequiredness(),
                ],
                ValidationRuleType::cases(),
            ),
            'comparison_operators' => array_map(
                fn (ComparisonOperator $t) => [
                    'value' => $t->value,
                    // The ROW rendering — `at most (≤)` — because this list feeds a rule row's control and
                    // the report that filed the row asked for "the text plus the symbol".
                    //
                    // ⛔ `sentenceLabel()` IS DELIBERATELY NOT SHIPPED HERE. Its consumers are the two
                    // client copies in `ConditionRow.vue` and `condition-describer.ts`, which compose a
                    // sentence and keep their own maps for a vocabulary wider than this enum; what holds
                    // them to PHP is `ConditionLabelMirrorDriftTest`, not this payload. Transmitting a
                    // second label nothing reads would be exactly the decorative surface `M43` measured.
                    'label' => $t->label(),
                    // ⚠️ These shapes belong to the RELATED field, never the rule's owner: an operator in a
                    // conditional rule compares the value of the field the rule NAMES. See
                    // ValidationRuleType::takesOperator() for where that was measured.
                    'shapes' => $this->shapesAllowing(fn (ValueShape $s) => $s->allowsOperator($t)),
                ],
                ComparisonOperator::cases(),
            ),
        ];
    }

    /**
     * @param  callable(ValueShape): bool  $predicate
     * @return list<string>
     */
    private function shapesAllowing(callable $predicate): array
    {
        return array_values(array_map(
            fn (ValueShape $s): string => $s->value,
            array_filter(ValueShape::cases(), $predicate),
        ));
    }
}
