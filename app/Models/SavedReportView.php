<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\SavedReportViewPolicy;
use App\Support\Analytics\AnalyticsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's saved analytics report (ADR-0011 §D8, Increment H24a).
 *
 * `strict` RLS scopes every query to the TENANT, not to the user — see the migration docblock for why the
 * `belongs_to_user` variant is forbidden here. Per-user privacy is therefore an application predicate:
 * {@see scopeOwnedBy()} on every list read, and {@see SavedReportViewPolicy}'s owner check on every
 * single-row action.
 *
 * `definition` holds an {@see AnalyticsQuery} payload — a DECLARATION resolved at read time, never a
 * materialized result. That is what lets a view naming something since deleted degrade to a stated refusal
 * instead of a wrong number.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SavedReportView extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'definition',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The per-user predicate RLS does not supply.
     *
     * `docs/PRD.md:204` promises "saved/custom report views **per user**", and under `strict` isolation an
     * Owner would otherwise see every member's. Applied on every list read; the single-row equivalent is the
     * policy's owner check.
     *
     * @param  Builder<SavedReportView>  $query
     * @return Builder<SavedReportView>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
