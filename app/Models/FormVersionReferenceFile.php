<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Forms\FormReferenceFileService;
use App\Services\Forms\SchemaTreeCloner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reference file one form version shows its respondents (M132, `R-bf49e4c1`, `D61` = B) — the version's
 * "reference row". The file is an {@see Attachment} of kind `form_reference_file`, owned by the form and stored
 * once; this row is what freezes it into a version. See the `form_version_reference_files` migration for why.
 *
 * Written by {@see FormReferenceFileService} on a draft, and copied forward by {@see SchemaTreeCloner} on publish
 * and restore. Never written for a published version: the table's draft-child RLS refuses it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property string $attachment_id
 * @property string $label
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormVersionReferenceFile extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'form_version_id',
        'attachment_id',
        'label',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
