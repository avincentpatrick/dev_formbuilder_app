<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Admin\ImpersonationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One cross-host impersonation grant (Increment I11b, rbac §9's resolved decision 1).
 *
 * Minted on the central console inside an adopted tenant context, redeemed once on the tenant subdomain,
 * then kept as the forensic record of a handoff that happened. The migration docblock argues the shape;
 * this class carries only what the two ends need to agree on.
 *
 * ⚠️ THE PLAINTEXT TOKEN IS NEVER AN ATTRIBUTE HERE. `token_hash` is `hash('sha256', $plain)` and the
 * plaintext exists only in the URL {@see ImpersonationService::mint()} returns. There is deliberately no
 * accessor, no cast and no `$appends` entry that could put it back — a model that could reproduce the
 * secret would put it in every `toArray()`, every log line and every Inertia prop that ever serialized one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $operator_id
 * @property string $target_user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ImpersonationToken extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'operator_id',
        'target_user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Redeemable RIGHT NOW: never used, and inside its window.
     *
     * ⚠️ BOTH HALVES, AND NEITHER IS REDUNDANT. `consumed_at IS NULL` alone leaves a token intercepted from
     * a proxy log valid until someone happens to use it; `expires_at > now()` alone permits a second use
     * inside the same minute. Expressed as ONE scope so no caller can apply half of it — the shape of bug
     * that reads as working code at every call site.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', Carbon::now());
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
