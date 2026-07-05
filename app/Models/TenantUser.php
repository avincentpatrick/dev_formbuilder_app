<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantUserStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tenant membership + invite lifecycle row (multi-tenancy-rbac-design.md §7). Strictly tenant-scoped
 * (RLS strict shape); the join target of the `users` visibility policy. B1 lands the model + isolation;
 * the invite/accept/decline/remove/ownership-transfer behavior (TenantMembershipService) and the
 * `invited_role_id` FK are B2.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property TenantUserStatus $status
 * @property string|null $invited_role_id
 * @property string|null $invited_by
 * @property Carbon|null $invite_expires_at
 * @property string|null $invite_token
 * @property Carbon|null $joined_at
 * @property Carbon|null $removed_at
 * @property string|null $removed_by
 */
class TenantUser extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'status',
        'invited_role_id',
        'invited_by',
        'invited_at',
        'invite_expires_at',
        'invite_token',
        'joined_at',
        'removed_at',
        'removed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantUserStatus::class,
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'joined_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
