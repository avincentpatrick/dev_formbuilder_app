<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Forms\ChoiceListMaterializer;
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
 * @property ?string $change_summary
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

    /**
     * The reference files this version shows its respondents (M132, `R-bf49e4c1`), frozen with it (`D61` = B).
     *
     * @return HasMany<FormVersionReferenceFile, $this>
     */
    public function referenceFiles(): HasMany
    {
        return $this->hasMany(FormVersionReferenceFile::class);
    }

    /**
     * The choice lists this version takes from uploaded CSV files (M141, `R-f69aab42`, `D95`), frozen with it.
     *
     * @return HasMany<FormVersionChoiceList, $this>
     */
    public function choiceLists(): HasMany
    {
        return $this->hasMany(FormVersionChoiceList::class);
    }

    /**
     * The frozen schema as a browser is sent it: a CSV-backed cascade's options left out (M141, `R-f69aab42`).
     *
     * A published list-backed cascade holds its whole list in `config.options` — about 42,000 rows for a barangay list —
     * because every server-side reader needs it there ({@see ChoiceListMaterializer}). A browser fetches it beside the
     * schema instead, once per version; the runtime never recomputes the checksum, so the payload may differ from the
     * bytes it pins.
     *
     * @return array<string, mixed>
     */
    public function schemaWithoutListOptions(): array
    {
        $snapshot = $this->getAttribute('schema_snapshot');

        if (! is_array($snapshot)) {
            return [];
        }

        if (is_array($snapshot['fields'] ?? null)) {
            foreach ($snapshot['fields'] as $index => $field) {
                if (is_array($field) && ($field['field_type'] ?? null) === FieldType::CascadingSelect->value && is_array($field['config'] ?? null)) {
                    $snapshot['fields'][$index]['config'] = ChoiceListMaterializer::withoutListOptions($field['config']);
                }
            }
        }

        return $snapshot;
    }
}
