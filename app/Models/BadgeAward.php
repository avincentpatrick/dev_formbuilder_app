<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeKey;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use App\Services\Gamification\BadgeAwarder;
use Database\Factories\BadgeAwardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One earned badge (data-dictionary §32, ADR-0020) — Increment K1b.
 *
 * ⚠️ **`implements TenantScoped` IS LOAD-BEARING AND IS NOT DECORATION.** {@see BelongsToTenant} gives the
 * read scope to anything that uses the trait, but `bootBelongsToTenant()`'s `creating` hook returns early
 * unless the model ALSO implements this interface — so a model with the trait alone scopes its reads and
 * never fills `tenant_id`, and the resulting INSERT matches no `WITH CHECK` policy and **writes zero rows
 * without erroring**. That is a measured bug class in this project, not a hypothetical, and it is repeated
 * here rather than cross-referenced because the cost of missing it is silent.
 *
 * Deliberately does NOT use `HasUuidv7`: the PK is a bigint identity, the {@see PointAward} /
 * {@see UsageCounter} precedent for internal aggregation rows never addressed externally.
 *
 * The table is `append_only` under RLS — no UPDATE or DELETE policy exists — so this model is
 * insert-and-read by construction and `updated_at` never moves. {@see BadgeAwarder} is the only writer.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $user_id
 * @property BadgeKey $badge
 * @property Carbon $awarded_at
 */
class BadgeAward extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<BadgeAwardFactory> */
    use HasFactory;

    /**
     * `tenant_id` is EXCLUDED — BelongsToTenant auto-fills it (bypassing $fillable). Listing it here would
     * make it *look* like a caller's responsibility, which is how a caller comes to pass the wrong one.
     */
    protected $fillable = [
        'user_id',
        'badge',
        'awarded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'badge' => BadgeKey::class,
            'awarded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
