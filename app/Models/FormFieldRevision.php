<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FieldRevisionKind;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Forms\FieldRevisionRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a question's history (M188, `R-3894369d`; data-dictionary §42). Strictly tenant-scoped and append-only:
 * written by {@see FieldRevisionRecorder} inside the builder's own transactions, never edited.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $form_version_id the draft it was made in
 * @property string $lineage_id the question's identity across versions and renames (`form_fields.lineage_id`)
 * @property string $field_key the key at the time
 * @property string $field_type the type at the time
 * @property FieldRevisionKind $kind
 * @property list<array{setting: string, before: mixed, after: mixed, too_large?: bool}> $changes
 * @property string|null $put_back_of the entry a Put back put back
 * @property string|null $user_id NULL once the member's account is gone
 * @property Carbon $created_at
 */
class FormFieldRevision extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    public const UPDATED_AT = null;

    /**
     * Nothing is mass-assignable: every row is written by the recorder with `forceFill()`, from the database's own before
     * and after — never from a request payload.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FieldRevisionKind::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
