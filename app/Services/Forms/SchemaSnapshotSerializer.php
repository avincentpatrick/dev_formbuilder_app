<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\FieldLibrary;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use Illuminate\Support\Collection;

/**
 * Builds the canonical, id-free representation of a version's structure and its SHA-256 checksum
 * (form-versioning-schema-migration.md §3.2/§3.3). The checksum is the drift-detection contract the
 * offline client and export tooling rely on, so it MUST be deterministic across row-id churn, DB return
 * order, and (within a serialization) key ordering:
 *
 *   - **id-stripped**: `id`/`tenant_id`/`form_version_id`/timestamps/`created_by`/`updated_by` are dropped.
 *   - **FK-by-key**: a field references its section by the section's stable `key`, and a validation
 *     references its comparison field by that field's `key` — never by row id.
 *   - **total, deterministic order**: sections and fields are ordered by `key`; a field's validations by
 *     a fully-specified tuple (sequence, rule_type, operator, related-field key, rule_value, expression);
 *     `logic_group` uuids (which change on every clone-forward) are remapped to stable per-field ordinals.
 *   - **recursively ksorted** object keys (byte order) + fixed JSON flags before the hash.
 *
 * Known caveat (form-versioning-schema-migration.md §3.2): float formatting inside `config` follows PHP's
 * json_encode, so cross-*language* checksum parity for non-integer numerics is not yet pinned — noted, not
 * yet load-bearing (no offline client exists until Phase 2).
 */
final class SchemaSnapshotSerializer
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * The canonical snapshot stored in form_versions.schema_snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(FormVersion $version): array
    {
        /** @var Collection<int, FormSection> $sections */
        $sections = $version->sections()->get();
        /** @var Collection<int, FormField> $fields */
        $fields = $version->fields()->get();
        /** @var Collection<int, FormFieldValidation> $validations */
        $validations = $version->validations()->get();

        $sectionKeyById = $sections->pluck('key', 'id');
        $fieldKeyById = $fields->pluck('key', 'id');
        $validationsByField = $validations->groupBy('form_field_id');

        $canonical = [
            'sections' => $sections
                ->map(fn (FormSection $s): array => $this->section($s))
                ->sortBy('key', SORT_STRING)->values()->all(),
            'fields' => $fields
                ->map(fn (FormField $f): array => $this->field(
                    $f,
                    $sectionKeyById,
                    $fieldKeyById,
                    $validationsByField->get($f->id) ?? collect(),
                ))
                ->sortBy('key', SORT_STRING)->values()->all(),
        ];

        /** @var array<string, mixed> $sorted */
        $sorted = $this->ksortRecursive($canonical);

        return $sorted;
    }

    /**
     * Serialize a SINGLE field into the canonical field shape (Increment G9b — capture a draft field into the
     * question library). Builds the section/field key maps from the field's own version so `section_key` and
     * `related_field_key` resolve exactly as they do inside {@see snapshot()}. Reused by
     * {@see FieldLibrary::fromField()}.
     *
     * @return array<string, mixed>
     */
    public function snapshotField(FormField $field): array
    {
        $versionId = $field->form_version_id;

        $sectionKeyById = FormSection::query()->where('form_version_id', $versionId)->pluck('key', 'id');
        $fieldKeyById = FormField::query()->where('form_version_id', $versionId)->pluck('key', 'id');
        $validations = $field->validations()->get();

        return $this->field($field, $sectionKeyById, $fieldKeyById, $validations);
    }

    /** SHA-256 over the canonical serialization of a version. */
    public function checksum(FormVersion $version): string
    {
        return $this->checksumOf($this->snapshot($version));
    }

    /**
     * SHA-256 over an already-canonical snapshot array (so callers that already built it don't rebuild).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function checksumOf(array $snapshot): string
    {
        return hash('sha256', (string) json_encode($snapshot, self::JSON_FLAGS));
    }

    /**
     * @return array<string, mixed>
     */
    private function section(FormSection $s): array
    {
        return [
            'key' => $s->key,
            'label' => $s->label,
            'label_translations' => $s->label_translations,
            'description' => $s->description,
            'description_translations' => $s->description_translations,
            'sequence' => $s->sequence,
            'icon' => $s->icon,
            'color' => $s->color,
            'is_repeatable' => $s->is_repeatable,
            'min_instances' => $s->min_instances,
            'max_instances' => $s->max_instances,
            'relevant_expression' => $s->relevant_expression,
        ];
    }

    /**
     * @param  Collection<int|string, string>  $sectionKeyById
     * @param  Collection<int|string, string>  $fieldKeyById
     * @param  Collection<int, FormFieldValidation>  $validations
     * @return array<string, mixed>
     */
    private function field(FormField $f, Collection $sectionKeyById, Collection $fieldKeyById, Collection $validations): array
    {
        return [
            'key' => $f->key,
            'section_key' => $f->form_section_id !== null ? $sectionKeyById->get($f->form_section_id) : null,
            'field_type' => $f->field_type->value,
            'config' => $f->config,
            'label' => $f->label,
            'label_translations' => $f->label_translations,
            'hint' => $f->hint,
            'hint_translations' => $f->hint_translations,
            'placeholder' => $f->placeholder,
            'default_value' => $f->default_value,
            'default_value_is_expression' => $f->default_value_is_expression,
            'is_required' => $f->is_required->value,
            'relevant_expression' => $f->relevant_expression,
            'appearance' => $f->appearance,
            'sequence' => $f->sequence,
            'section_sequence' => $f->section_sequence,
            'is_pii' => $f->is_pii,
            'is_sensitive' => $f->is_sensitive,
            'is_queryable' => $f->is_queryable,
            'indexed_data_type' => $f->indexed_data_type?->value,
            'validations' => $this->validations($validations, $fieldKeyById),
        ];
    }

    /**
     * A field's validations in canonical order, with logic_group uuids remapped to stable ordinals.
     *
     * @param  Collection<int, FormFieldValidation>  $validations
     * @param  Collection<int|string, string>  $fieldKeyById
     * @return list<array<string, mixed>>
     */
    private function validations(Collection $validations, Collection $fieldKeyById): array
    {
        $ordered = $validations
            ->sortBy(fn (FormFieldValidation $v): string => implode('|', [
                str_pad((string) $v->sequence, 10, '0', STR_PAD_LEFT),
                $this->enumValue($v->rule_type) ?? '',
                $this->enumValue($v->operator) ?? '',
                $v->related_form_field_id !== null ? ($fieldKeyById->get($v->related_form_field_id) ?? '') : '',
                (string) ($v->rule_value ?? ''),
                (string) ($v->expression ?? ''),
            ]), SORT_STRING)
            ->values();

        // Remap each distinct logic_group uuid to a per-field ordinal by first appearance, so the
        // grouping survives clone-forward (which mints new uuids) without changing the checksum.
        $groupOrdinals = [];
        $next = 0;
        $result = [];

        foreach ($ordered as $v) {
            $groupOrdinal = null;
            if ($v->logic_group !== null) {
                if (! array_key_exists($v->logic_group, $groupOrdinals)) {
                    $groupOrdinals[$v->logic_group] = $next++;
                }
                $groupOrdinal = $groupOrdinals[$v->logic_group];
            }

            $result[] = [
                'rule_type' => $this->enumValue($v->rule_type),
                'operator' => $this->enumValue($v->operator),
                'rule_value' => $v->rule_value,
                'expression' => $v->expression,
                'error_message' => $v->error_message,
                'error_message_translations' => $v->error_message_translations,
                'related_field_key' => $v->related_form_field_id !== null
                    ? $fieldKeyById->get($v->related_form_field_id)
                    : null,
                'logic_group_ordinal' => $groupOrdinal,
                'logic_operator' => $this->enumValue($v->logic_operator),
                'sequence' => $v->sequence,
            ];
        }

        return $result;
    }

    /**
     * The backing value of a (possibly null) backed enum. Accepts a raw string too, so it is robust to
     * how the model's enum casts are statically resolved.
     */
    private function enumValue(\BackedEnum|string|null $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : $value;
    }

    /**
     * Recursively sort object keys (byte order); leave list order untouched (already deterministically
     * sorted by the callers above). Config jsonb / translation maps get their keys normalized here too.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function ksortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortRecursive($v);
            }
        }

        return $value;
    }
}
