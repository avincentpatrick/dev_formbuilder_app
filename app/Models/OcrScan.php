<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OcrScanStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded scan of a printed blank form and what reading it produced (M128 — single-form OCR
 * groundwork 1). The migration's docblock is the design record; in short: a STAGED proposal a person
 * reviews before anything reaches `SubmissionPipeline` (`docs/ocr-pipeline-design.md` §1), with its page
 * files in `attachments` (kind `ocr_source_scan`, owner alias `ocr_scan`).
 *
 * `pages` is an ordered list of `{attachment_id: string, response_path: string|null}`; a null
 * `response_path` is a page not yet read. `extraction` is `PrintedFormMatcher`'s result once every page
 * is read.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string|null $form_version_id
 * @property string|null $uploaded_by
 * @property OcrScanStatus $status
 * @property string $provider
 * @property list<array{attachment_id: string, response_path: string|null}> $pages
 * @property array<string, mixed>|null $extraction
 * @property int $attempts
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OcrScan extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'form_id',
        'form_version_id',
        'uploaded_by',
        'status',
        'provider',
        'pages',
        'extraction',
        'attempts',
        'error_code',
        'error_message',
        'read_at',
    ];

    /**
     * The database default, mirrored so a freshly created model reports it without a re-read — Eloquent
     * never reads a column default back, which is how `M125`'s create response carried `null` booleans.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OcrScanStatus::class,
            'pages' => 'array',
            'extraction' => 'array',
            'attempts' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }
}
