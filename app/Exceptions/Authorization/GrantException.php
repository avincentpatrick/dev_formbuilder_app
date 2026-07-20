<?php

declare(strict_types=1);

namespace App\Exceptions\Authorization;

use App\Enums\ResourceCapacity;
use App\Services\Authorization\ResourceGrantService;
use RuntimeException;

/**
 * A resource-grant write refused on escalation grounds (Increment G10b), raised by
 * {@see ResourceGrantService}.
 *
 * Deliberately NOT a plain 403 from a policy: the base gate (`forms.collaborators.manage`) has already
 * passed by the time these fire, so the actor IS allowed to manage grants — they are being stopped from
 * a *specific* escalation. Collapsing that into a bare "forbidden" would make the refusal
 * indistinguishable from "you may not manage grants at all", which is the one thing an administrator
 * debugging a failed grant most needs to tell apart. Each cause therefore carries its own stable code.
 *
 * `recipientNotActive` is the odd one out: it is not an escalation but an INERTNESS guard. The resolver
 * loads grants behind a `tenant_users.status = 'active'` EXISTS, so a grant to a non-member is written
 * successfully, resolves to nothing, and looks granted in every listing — the worst possible failure
 * mode for an authorization surface. Refusing at the write is what keeps the table honest.
 */
final class GrantException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    /** An actor may not grant themselves a capacity — that turns the manage permission into self-service. */
    public static function selfGrant(): self
    {
        return new self(
            'You cannot grant access to yourself.',
            'grant_self_denied',
            403,
        );
    }

    /**
     * Anti-amplification: you cannot hand out an authority you do not hold tenant-wide yourself. A grant
     * activates the `.own` half of a permission pair, so the granter must hold the `.any` counterpart.
     */
    public static function amplification(ResourceCapacity $capacity, string $requiredPermission): self
    {
        return new self(
            "Granting {$capacity->value} access requires the “{$requiredPermission}” permission.",
            'grant_amplification_denied',
            403,
        );
    }

    /** The recipient is not an active member, so the grant would resolve to nothing. See the class docblock. */
    public static function recipientNotActive(): self
    {
        return new self(
            'That person is not an active member of this workspace.',
            'grant_recipient_inactive',
            422,
        );
    }

    /**
     * `includes_descendants` only means anything for a scope-node target; a form has no descendants. The
     * `resource_grants_descendants_check` CHECK is the backstop — this is the clean 422 in front of it.
     */
    public static function descendantsNotApplicable(): self
    {
        return new self(
            'Descendant inheritance applies only to a grant on a scope node.',
            'grant_descendants_not_applicable',
            422,
        );
    }

    /** The stable, machine-readable error code (never the HTTP status text, never translated). */
    public function code(): string
    {
        return $this->errorCode;
    }

    /** The HTTP status for the /api/v1 envelope — 403 for an escalation refusal, 422 for a bad request shape. */
    public function status(): int
    {
        return $this->status;
    }
}
