<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use Database\Factories\SubmissionAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The JSONB answer document (data-dictionary §8) — a strict 1:1 with `submissions`, so `submission_id`
 * IS the primary key (no surrogate id, no UUID generation) and equals the owning submission's id.
 * `answers` is keyed by each `form_fields.key` (or `form_sections.key` for repeat-group arrays).
 *
 * @property string $submission_id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property array<string, mixed> $answers
 * @property ?string $answers_schema_checksum
 * @property ?string $answers_content_checksum
 * @property array<int, mixed> $attachment_refs
 * @property ?int $completeness_percent
 * @property Carbon|null $last_saved_at
 */
class SubmissionAnswer extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SubmissionAnswerFactory> */
    use HasFactory;

    protected $primaryKey = 'submission_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'submission_id',
        'tenant_id',
        'form_version_id',
        'answers',
        'answers_schema_checksum',
        'answers_content_checksum',
        'attachment_refs',
        'completeness_percent',
        'last_saved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'attachment_refs' => 'array',
            'completeness_percent' => 'integer',
            'last_saved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }
}
