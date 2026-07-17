<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\Dto\MaterializeResult;
use App\Services\Xlsform\XlsformImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The INVERSE of {@see SchemaSnapshotSerializer}: rebuilds a version's id-free, key-referenced blueprint back
 * into normalized `form_sections` / `form_fields` / `form_field_validations` rows (Increment G9a). Nothing else
 * does this — {@see SchemaTreeCloner} only clones row→row from a LIVE version, and a platform template has no
 * live rows, only its stored `schema_blueprint`. So this is what "instantiate a template into a new form" and
 * (G9b) "insert a library question into a form" both build on.
 *
 * A PURE WRITER, exactly like {@see SchemaTreeCloner}: no transaction or lock of its own — the caller
 * ({@see TemplateService::instantiate}, or the builder's library insert) owns the `DB::transaction` + form lock
 * and has already resolved `$target` as the form's editable DRAFT (so the draft_child RLS guard passes and the
 * child rows' `tenant_id` auto-fills from context). Create order is sections → fields → validations, remapping
 * `section_key`→id and `related_field_key`→id, and reversing the serializer's `logic_group_ordinal` back into a
 * fresh uuid per distinct ordinal (per field) so a round-trip re-snapshot is byte-identical. Follows
 * {@see XlsformImporter::repopulate()} (explicit-key create + key→id remap) and
 * {@see FormBuilderService::replaceValidations()} (related_field_key→id).
 */
final class SchemaBlueprintMaterializer
{
    public function __construct(private readonly BlueprintValidator $validator) {}

    /**
     * Materialize a whole-form blueprint into the target draft. Returns the row counts written.
     *
     * `$actor` is required (as on {@see materializeField}): it lands in `form_fields.created_by`, which is NOT
     * NULL, so a null actor is not a "system-authored field" — it is a 23502 constraint violation, and one that
     * would surface as a raw QueryException past the fail-closed BlueprintValidator.
     *
     * @param  array<string, mixed>  $blueprint  the SchemaSnapshotSerializer shape: {sections: [...], fields: [...]}
     */
    public function materializeInto(FormVersion $target, array $blueprint, User $actor): MaterializeResult
    {
        $this->validator->validate($blueprint);

        $sections = $blueprint['sections'] ?? [];
        $fields = $blueprint['fields'] ?? [];

        $sectionIdByKey = [];
        foreach ($sections as $section) {
            $row = FormSection::create($this->sectionAttributes($target, $section));
            $sectionIdByKey[$section['key']] = $row->id;
        }

        $fieldIdByKey = [];
        foreach ($fields as $field) {
            $sectionKey = $field['section_key'] ?? null;
            $row = FormField::create($this->fieldAttributes(
                $target,
                $field,
                $sectionKey !== null ? ($sectionIdByKey[$sectionKey] ?? null) : null,
                $actor,
            ));
            $fieldIdByKey[$field['key']] = $row->id;
        }

        $validationCount = 0;
        foreach ($fields as $field) {
            $validationCount += $this->writeValidations(
                $target,
                $fieldIdByKey[$field['key']],
                $field['validations'] ?? [],
                $fieldIdByKey,
            );
        }

        return new MaterializeResult(
            sectionCount: count($sections),
            fieldCount: count($fields),
            validationCount: $validationCount,
        );
    }

    /**
     * Materialize a SINGLE field blueprint (a field-library item, Increment G9b) into the target draft under
     * an optional section, minting a fresh unique key. A related_field_key is remapped against the draft's
     * EXISTING fields (a standalone library question normally carries none → unresolved refs become null).
     *
     * @param  array<string, mixed>  $fieldBlueprint  a SchemaSnapshotSerializer field shape (minus/ignoring section_key)
     */
    public function materializeField(FormVersion $target, array $fieldBlueprint, ?string $sectionId, User $actor): FormField
    {
        $attributes = $this->fieldAttributes($target, $fieldBlueprint, $sectionId, $actor);
        $attributes['key'] = $this->uniqueFieldKey($target, is_string($fieldBlueprint['key'] ?? null) ? $fieldBlueprint['key'] : null);
        $attributes['sequence'] = $this->nextFieldSequence($target);
        $attributes['section_sequence'] = null;

        $field = FormField::create($attributes);

        $existingIdByKey = FormField::query()
            ->where('form_version_id', $target->id)
            ->pluck('id', 'key')
            ->all();

        $this->writeValidations($target, $field->id, $fieldBlueprint['validations'] ?? [], $existingIdByKey);

        return $field->refresh();
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function sectionAttributes(FormVersion $target, array $section): array
    {
        return [
            'form_version_id' => $target->id,
            'key' => $section['key'],
            'label' => $section['label'] ?? '',
            'label_translations' => $section['label_translations'] ?? null,
            'description' => $section['description'] ?? null,
            'description_translations' => $section['description_translations'] ?? null,
            'sequence' => $section['sequence'] ?? 0,
            'icon' => $section['icon'] ?? null,
            'color' => $section['color'] ?? null,
            'is_repeatable' => (bool) ($section['is_repeatable'] ?? false),
            'min_instances' => $section['min_instances'] ?? null,
            'max_instances' => $section['max_instances'] ?? null,
            'relevant_expression' => $section['relevant_expression'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function fieldAttributes(FormVersion $target, array $field, ?string $sectionId, User $actor): array
    {
        return [
            'form_version_id' => $target->id,
            'form_section_id' => $sectionId,
            'key' => $field['key'],
            'field_type' => FieldType::from((string) $field['field_type']),
            'config' => $field['config'] ?? [],
            'label' => $field['label'] ?? '',
            'label_translations' => $field['label_translations'] ?? null,
            'hint' => $field['hint'] ?? null,
            'hint_translations' => $field['hint_translations'] ?? null,
            'placeholder' => $field['placeholder'] ?? null,
            'default_value' => $field['default_value'] ?? null,
            'default_value_is_expression' => (bool) ($field['default_value_is_expression'] ?? false),
            'is_required' => RequiredMode::from((string) ($field['is_required'] ?? RequiredMode::Optional->value)),
            'relevant_expression' => $field['relevant_expression'] ?? null,
            'appearance' => $field['appearance'] ?? null,
            'sequence' => $field['sequence'] ?? 0,
            'section_sequence' => $field['section_sequence'] ?? null,
            'is_pii' => (bool) ($field['is_pii'] ?? false),
            'is_sensitive' => (bool) ($field['is_sensitive'] ?? false),
            'is_queryable' => (bool) ($field['is_queryable'] ?? false),
            'indexed_data_type' => $field['indexed_data_type'] ?? null,
            'created_by' => $actor->id,
        ];
    }

    /**
     * Write one field's validations, reversing the serializer's per-field `logic_group_ordinal` back into a
     * fresh uuid per distinct ordinal (so validations that shared a group rejoin the same one), and remapping
     * `related_field_key` → id against `$fieldIdByKey`. Returns the number of rows written.
     *
     * @param  list<array<string, mixed>>|array<mixed>  $validations
     * @param  array<string, string>  $fieldIdByKey  field key => new field id
     */
    private function writeValidations(FormVersion $target, string $fieldId, array $validations, array $fieldIdByKey): int
    {
        $groupUuidByOrdinal = []; // reset per field — the ordinal namespace is per-field (serializer §validations)
        $count = 0;

        foreach ($validations as $index => $validation) {
            $ordinal = $validation['logic_group_ordinal'] ?? null;
            $logicGroup = $ordinal === null
                ? null
                : ($groupUuidByOrdinal[$ordinal] ??= (string) Str::uuid());

            $relatedKey = $validation['related_field_key'] ?? null;

            FormFieldValidation::create([
                'form_version_id' => $target->id,
                'form_field_id' => $fieldId,
                'related_form_field_id' => $relatedKey !== null ? ($fieldIdByKey[$relatedKey] ?? null) : null,
                'rule_type' => $validation['rule_type'] ?? null,
                'operator' => $validation['operator'] ?? null,
                'rule_value' => $validation['rule_value'] ?? null,
                'expression' => $validation['expression'] ?? null,
                'error_message' => $validation['error_message'] ?? null,
                'error_message_translations' => $validation['error_message_translations'] ?? null,
                'logic_group' => $logicGroup,
                'logic_operator' => $validation['logic_operator'] ?? null,
                'sequence' => $validation['sequence'] ?? $index,
            ]);
            $count++;
        }

        return $count;
    }

    /** A collision-free field key within the draft (mirrors FormBuilderService::uniqueKey/copyKey). */
    private function uniqueFieldKey(FormVersion $target, ?string $base): string
    {
        $taken = DB::table('form_fields')->where('form_version_id', $target->id)->pluck('key')->flip();
        $base = ($base !== null && $base !== '') ? $base : 'field';

        if (! $taken->has($base)) {
            return $base;
        }

        $n = 2;
        while ($taken->has($candidate = "{$base}_{$n}")) {
            $n++;
        }

        return $candidate;
    }

    private function nextFieldSequence(FormVersion $target): int
    {
        return (int) FormField::query()->where('form_version_id', $target->id)->max('sequence') + 1;
    }
}
