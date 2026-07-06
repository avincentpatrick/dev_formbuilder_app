<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormCollaboratorCapacity;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\FormCollaboratorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-form editor/reviewer access (multi-tenancy-rbac-design.md §8). Grants a Form Editor/Reviewer the
 * per-form `.own` capability; the FormPolicy composition that reads it lands in Increment D2. Strictly
 * tenant-scoped (strict RLS).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $user_id
 * @property FormCollaboratorCapacity $capacity
 * @property ?string $added_by
 */
class FormCollaborator extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormCollaboratorFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'form_id',
        'user_id',
        'capacity',
        'added_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => FormCollaboratorCapacity::class,
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
