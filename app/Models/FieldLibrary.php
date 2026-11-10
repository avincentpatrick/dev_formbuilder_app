<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RequiredMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Services\Forms\SchemaSnapshotSerializer;
use Database\Factories\FieldLibraryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable single-question definition (data-dictionary §11) — the "question library" the builder inserts
 * FROM and saves TO. NULLABLE-GLOBAL shape (ADR-0002 §D2 named exception), the same recipe as
 * {@see FormTemplate}: a NULL `tenant_id` is a platform/system question readable by every tenant; a non-null
 * one is a tenant's own saved question. Unlike a whole-form template it stores a single field DECOMPOSED into
 * columns (`field_type` + `default_*`) rather than a `schema_blueprint`.
 *
 * {@see toBlueprintField()} rebuilds the {@see SchemaSnapshotSerializer} field shape from those columns so
 * {@see SchemaBlueprintMaterializer::materializeField()} can insert it into a draft, and {@see fromField()}
 * is the inverse capture (a draft field → library columns) for "save to library".
 *
 * Deliberately does NOT use {@see BelongsToTenant}: the ORM strict-equality scope would hide the platform
 * (NULL) rows the widened SELECT policy is written to reveal (same reasoning as {@see GlobalProbe} /
 * {@see FormTemplate}). Reads rely on RLS directly — a plain `query()` already returns own + platform rows;
 * `tenant_id` is NOT auto-filled, so every create path sets it explicitly.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $name
 * @property ?string $description
 * @property ?string $category
 * @property string $field_type
 * @property string $default_label
 * @property ?array<string, mixed> $default_label_translations
 * @property ?string $default_hint
 * @property array<string, mixed> $default_config
 * @property list<array<string, mixed>> $default_validations
 * @property int $usage_count
 * @property bool $is_active
 * @property ?string $created_by
 */
class FieldLibrary extends Model
{
    /** @use HasFactory<FieldLibraryFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $table = 'field_library';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'field_type',
        'default_label',
        'default_label_translations',
        'default_hint',
        'default_config',
        'default_validations',
        'usage_count',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_label_translations' => 'array',
            'default_config' => 'array',
            'default_validations' => 'array',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Reconstruct the {@see SchemaSnapshotSerializer} field shape from the stored columns, synthesizing safe
     * defaults for every key the library doesn't persist, so {@see SchemaBlueprintMaterializer::materializeField}
     * can insert it (it overwrites `key`/`sequence`/`section_sequence`).
     *
     * @return array<string, mixed>
     */
    public function toBlueprintField(): array
    {
        return [
            'key' => null,
            'section_key' => null,
            'field_type' => $this->field_type,
            'config' => $this->default_config ?? [],
            'label' => $this->default_label,
            'label_translations' => $this->default_label_translations,
            'hint' => $this->default_hint,
            'hint_translations' => null,
            'placeholder' => null,
            'default_value' => null,
            'default_value_is_expression' => false,
            'is_required' => RequiredMode::Optional->value,
            'relevant_expression' => null,
            'appearance' => null,
            'sequence' => 0,
            'section_sequence' => null,
            'is_pii' => false,
            'is_sensitive' => false,
            'is_queryable' => false,
            'indexed_data_type' => null,
            'validations' => $this->default_validations ?? [],
        ];
    }

    /**
     * Capture a draft field into the attributes of a NEW library row (the inverse of {@see toBlueprintField}).
     * Reuses the canonical single-field serialization ({@see SchemaSnapshotSerializer::snapshotField}), then
     * DROPS cross-field validations (a non-null `related_field_key`) — a single question is portable on its
     * own, a comparison against another field is not (it would resolve to null in a different form anyway).
     * The caller adds `tenant_id`/`created_by`/`is_active`/`usage_count`.
     *
     * ⛔ M220 (`R-fcdb4594`): A GROUP THAT LOSES A MEMBER IS DROPPED WHOLE. The engines fold a group within its
     * family and combine units as ANY (required, skip) or ALL (constraints), so a group with a member taken
     * out is STRICTER in every use: an "any of these" constraint offers fewer alternatives, and an "all of
     * these" required rule holds more often. Dropping the whole unit can only make the copy more permissive.
     * Ungrouping the survivors would be stricter still, which is why it is not done.
     *
     * @param  array<string, mixed>  $meta  optional name/description/category (one-click save sends none)
     * @return array<string, mixed>
     */
    public static function fromField(FormField $field, array $meta): array
    {
        $shape = app(SchemaSnapshotSerializer::class)->snapshotField($field);
        $all = array_values(array_filter(
            is_array($shape['validations'] ?? null) ? $shape['validations'] : [],
            static fn (mixed $v): bool => is_array($v),
        ));

        $crossField = static fn (array $v): bool => ($v['related_field_key'] ?? null) !== null;

        $brokenGroups = [];
        foreach ($all as $v) {
            if ($crossField($v) && ($v['logic_group_ordinal'] ?? null) !== null) {
                $brokenGroups[$v['logic_group_ordinal']] = true;
            }
        }

        $validations = array_values(array_filter(
            $all,
            static fn (array $v): bool => ! $crossField($v)
                && (($v['logic_group_ordinal'] ?? null) === null || ! isset($brokenGroups[$v['logic_group_ordinal']])),
        ));

        $label = $field->label !== '' ? $field->label : 'Untitled question';
        $name = $meta['name'] ?? $label;

        return [
            'name' => mb_substr((string) $name, 0, 150),
            'description' => $meta['description'] ?? null,
            'category' => $meta['category'] ?? null,
            'field_type' => $shape['field_type'],
            'default_label' => mb_substr($label, 0, 500),
            'default_label_translations' => $shape['label_translations'] ?? null,
            'default_hint' => $shape['hint'] ?? null,
            'default_config' => $shape['config'] ?? [],
            'default_validations' => $validations,
        ];
    }
}
