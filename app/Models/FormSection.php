<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\FormSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups fields within one form_version, optionally a repeat group (data-dictionary §4). Content child
 * of form_versions (draft_child RLS shape). `key` is stable across versions, unique per version.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property string $key
 * @property string $label
 * @property int $sequence
 * @property bool $is_repeatable
 */
class FormSection extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormSectionFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'form_version_id',
        'key',
        'label',
        'label_translations',
        'description',
        'description_translations',
        'sequence',
        'icon',
        'color',
        'is_repeatable',
        'min_instances',
        'max_instances',
        'relevant_expression',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label_translations' => 'array',
            'description_translations' => 'array',
            'sequence' => 'integer',
            'is_repeatable' => 'boolean',
            'min_instances' => 'integer',
            'max_instances' => 'integer',
        ];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class, 'form_section_id');
    }
}
