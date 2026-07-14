<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Enums\ScanStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single stored file in the shared polymorphic `attachments` table (data-dictionary §10, Increment G6).
 * Mutable tenant-owned record → the plain `strict` RLS shape (same as {@see Submission}). The repo's first
 * `morphTo`: `attachable` resolves via the morph map registered in AppServiceProvider (`submission` /
 * `form_field`), never a hand-rolled type switch.
 *
 * A file is not served to another user until its {@see ScanStatus::servable()} — the serving gate the
 * threat model relies on. `path` is a server-generated key; `original_filename` is display metadata only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $attachable_type
 * @property string $attachable_id
 * @property AttachmentKind $kind
 * @property string $disk
 * @property string $path
 * @property ?string $original_filename
 * @property ?string $mime_type
 * @property ?int $size_bytes
 * @property ?string $checksum_sha256
 * @property ?int $width
 * @property ?int $height
 * @property ?int $duration_seconds
 * @property bool $is_encrypted_at_rest
 * @property bool $is_pii
 * @property ScanStatus $virus_scan_status
 * @property ?float $ocr_confidence_avg
 * @property ?string $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Attachment extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'attachable_type',
        'attachable_id',
        'kind',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'width',
        'height',
        'duration_seconds',
        'is_encrypted_at_rest',
        'is_pii',
        'virus_scan_status',
        'ocr_confidence_avg',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'virus_scan_status' => ScanStatus::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'is_encrypted_at_rest' => 'boolean',
            'is_pii' => 'boolean',
            'ocr_confidence_avg' => 'float',
        ];
    }

    /**
     * The polymorphic owner — a {@see Submission} (after persist) or a {@see FormField} (while staged,
     * before its submission exists). Resolved through the morph map, so `attachable_type` holds the short
     * alias, not the fully-qualified class.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The `AttachmentRef` envelope a media answer carries (Increment G6) — the `id` is authoritative;
     * `mime`/`size`/`name`/`width`/`height`/`duration` are display metadata the control shows without a
     * server round-trip. Null members are dropped so the shape stays compact and mirrors the TS `MediaAnswer`.
     *
     * @return array<string, mixed>
     */
    public function toAnswerRef(): array
    {
        return array_filter([
            'id' => $this->id,
            'mime' => $this->mime_type,
            'size' => $this->size_bytes,
            'name' => $this->original_filename,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration_seconds,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
