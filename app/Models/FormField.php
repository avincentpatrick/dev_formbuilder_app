<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\FormFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The center of the schema (data-dictionary §5). Content child of form_versions (draft_child RLS
 * shape). `key` is unique per version; `form_section_id` NULL = ungrouped top-level field.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property ?string $form_section_id
 * @property string $key
 * @property FieldType $field_type
 * @property array<string, mixed> $config
 * @property string $label
 * @property ?array<string, string> $label_translations
 * @property ?string $hint
 * @property ?array<string, string> $hint_translations
 * @property ?string $placeholder
 * @property ?string $relevant_expression
 * @property int $sequence
 * @property ?int $section_sequence
 * @property RequiredMode $is_required
 * @property ?IndexedDataType $indexed_data_type
 * @property bool $is_queryable
 */
class FormField extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormFieldFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'form_version_id',
        'form_section_id',
        'key',
        'field_type',
        'config',
        'label',
        'label_translations',
        'hint',
        'hint_translations',
        'placeholder',
        'default_value',
        'default_value_is_expression',
        'is_required',
        'relevant_expression',
        'appearance',
        'sequence',
        'section_sequence',
        'is_pii',
        'is_sensitive',
        'is_queryable',
        'indexed_data_type',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => FieldType::class,
            'is_required' => RequiredMode::class,
            'indexed_data_type' => IndexedDataType::class,
            'config' => 'array',
            'label_translations' => 'array',
            'hint_translations' => 'array',
            'default_value_is_expression' => 'boolean',
            'sequence' => 'integer',
            'section_sequence' => 'integer',
            'is_pii' => 'boolean',
            'is_sensitive' => 'boolean',
            'is_queryable' => 'boolean',
        ];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<FormSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(FormSection::class, 'form_section_id');
    }

    /** @return HasMany<FormFieldValidation, $this> */
    public function validations(): HasMany
    {
        return $this->hasMany(FormFieldValidation::class, 'form_field_id');
    }
}
