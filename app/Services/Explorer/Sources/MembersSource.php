<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Database\Query\Builder;

/**
 * The workspace's members and invitations, one row each (ADR-0025 §D5).
 *
 * Gated on `tenant.members.invite`, which is the permission `/members` itself asks for. A role is read the
 * way the roster reads it: an active member's from `model_has_roles`, a pending invitation's from the role
 * it was invited as.
 */
final class MembersSource extends AbstractExplorerSource
{
    public function key(): string
    {
        return 'members';
    }

    public function label(): string
    {
        return 'Members';
    }

    public function description(): string
    {
        return 'People in this workspace and pending invitations: their role, status and when they joined.';
    }

    public function rowNoun(): string
    {
        return 'members';
    }

    public function defaultRangeDays(): int
    {
        return ExplorerQuery::MAX_RANGE_DAYS;
    }

    public function allows(User $user): bool
    {
        return $user->can('tenant.members.invite');
    }

    public function rangeField(): string
    {
        return 'added_at';
    }

    public function fields(User $user, ?Form $form): array
    {
        return [
            $this->enumField('status', 'Status', 'tenant_users.status', TenantUserStatus::class, 'Member'),
            $this->category('role', 'Role', 'coalesce(member_roles.name, invited_roles.name)', 'Member', headline: true),
            $this->time('added_at', 'Added or invited', 'tenant_users.created_at', 'Dates'),
            $this->time('joined_at', 'Joined', 'tenant_users.joined_at', 'Dates'),
        ];
    }

    public function query(User $user, ?Form $form): Builder
    {
        return TenantUser::query()
            ->toBase()
            ->leftJoin('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'tenant_users.user_id')
                    ->on('model_has_roles.tenant_id', '=', 'tenant_users.tenant_id')
                    ->where('model_has_roles.model_type', '=', (new User)->getMorphClass());
            })
            ->leftJoin('roles as member_roles', 'member_roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('roles as invited_roles', 'invited_roles.id', '=', 'tenant_users.invited_role_id');
    }
}
