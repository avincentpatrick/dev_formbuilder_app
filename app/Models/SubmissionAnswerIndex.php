<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use Database\Factories\SubmissionAnswerIndexFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The typed projection of a single `is_queryable` field's value (data-dictionary §9). Internal
 * projection row → `bigint` auto-increment PK (not UUID), so it does NOT use HasUuidv7. Exactly one
 * `value_*` column is populated per row, per the field's IndexedDataType. The default table name would
 * pluralize to `submission_answer_indices`, so it is pinned explicitly.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $submission_id
 * @property string $form_version_id
 * @property string $form_field_id
 * @property string $field_key
 * @property ?string $value_text
 * @property ?float $value_number
 * @property ?bool $value_boolean
 * @property Carbon|null $value_date
 * @property Carbon|null $value_datetime
 */
class SubmissionAnswerIndex extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SubmissionAnswerIndexFactory> */
    use HasFactory;

    protected $table = 'submission_answer_index';

    protected $fillable = [
        'tenant_id',
        'submission_id',
        'form_version_id',
        'form_field_id',
        'field_key',
        'value_text',
        'value_number',
        'value_boolean',
        'value_date',
        'value_datetime',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'float',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
            'value_datetime' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }
}
