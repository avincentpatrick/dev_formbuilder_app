<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;

/**
 * A tenant-membership business-rule violation (multi-tenancy-rbac-design.md §7) — an already-active
 * member re-invited, an expired/consumed invite accepted, the sole Owner removed without a transfer,
 * etc. Distinct from an authorization failure (that is a 403 from the permission gate) and from a
 * missing invite (that is a 404 in the controller). Rendered as a redirect-back-with-errors for web
 * requests by the handler registered in bootstrap/app.php.
 */
final class MembershipException extends RuntimeException
{
    public static function cannotInviteAsOwner(): self
    {
        return new self('Ownership is established by transfer, not by invitation.');
    }

    public static function unknownRole(string $role): self
    {
        return new self("Unknown role: {$role}.");
    }

    public static function alreadyMember(string $email): self
    {
        return new self("{$email} is already an active member of this tenant.");
    }

    public static function notPending(): self
    {
        return new self('This invitation is no longer pending.');
    }

    public static function expired(): self
    {
        return new self('This invitation has expired.');
    }

    public static function invitationMismatch(): self
    {
        return new self('This invitation was issued for a different account.');
    }

    public static function notAMember(): self
    {
        return new self('That user is not an active member of this tenant.');
    }

    public static function cannotRemoveOwner(): self
    {
        return new self('Transfer ownership before removing the tenant Owner.');
    }

    public static function ownershipTargetNotMember(): self
    {
        return new self('Ownership can only be transferred to an active member.');
    }

    public static function alreadyOwner(): self
    {
        return new self('That user is already the Owner of this tenant.');
    }

    /*
    |--------------------------------------------------------------------------
    | Role change (Increment I8a) — the four refusals TenantMembershipService::changeRole() can make.
    |--------------------------------------------------------------------------
    | All four are ROLE-MODEL invariants rather than permission checks: an Admin holding
    | `tenant.members.role` legitimately reaches the route, and is then told no by the domain. The Owner
    | pair in particular is what keeps §5's "exactly one Owner, changed only by transfer" true no matter
    | what the roster UI sends.
    */

    public static function cannotAssignOwner(): self
    {
        return new self('Ownership is established by transfer, not by a role change.');
    }

    public static function cannotChangeOwnerRole(): self
    {
        return new self("The Owner's role changes only by transferring ownership.");
    }

    public static function cannotChangeOwnRole(): self
    {
        return new self('You cannot change your own role.');
    }

    public static function roleUnchanged(string $role): self
    {
        return new self("That member already holds the {$role} role.");
    }
}
