<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormVersionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\FormVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The immutable snapshot per publish (data-dictionary §3). Append-only history (no soft-deletes); a
 * draft is discarded by hard delete, which the `form_version` RLS shape permits only while draft.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property int $version_number
 * @property FormVersionStatus $status
 * @property string $title
 * @property array<string, mixed> $schema_snapshot
 * @property ?string $checksum
 * @property Carbon|null $published_at
 * @property Carbon|null $superseded_at
 */
class FormVersion extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormVersionFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'form_id',
        'version_number',
        'status',
        'title',
        'description',
        'schema_snapshot',
        'change_summary',
        'checksum',
        'published_at',
        'published_by',
        'superseded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FormVersionStatus::class,
            'version_number' => 'integer',
            'schema_snapshot' => 'array',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return HasMany<FormSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(FormSection::class);
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class);
    }

    /** @return HasMany<FormFieldValidation, $this> */
    public function validations(): HasMany
    {
        return $this->hasMany(FormFieldValidation::class);
    }
}
