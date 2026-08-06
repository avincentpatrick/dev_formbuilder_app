<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\Tenancy\TenantMembershipService;

/**
 * Someone was invited to join a tenant (Increment I3). Raised post-commit by
 * {@see TenantMembershipService::invite()}, immediately after the invitation email it does NOT replace.
 *
 * ── The invite email did not move behind this event, on purpose ─────────────────────────────────────────
 * The I-map's sketch was "member-invite moves from direct mail to an event". Three properties of that call
 * site defeat it, and all three are load-bearing rather than incidental:
 *
 *   1. The plaintext invite token exists ONLY as a local variable in `invite()` — the column stores its
 *      sha256 — so an event raised afterwards cannot re-derive the accept URL.
 *   2. The accept URL must be built in-request under live tenant context (`TenantUrl::to()`, the APP arm;
 *      H22a fixed exactly this producing `https://acme/invitations/…`, a URL resolving nowhere).
 *   3. **This enum is the webhook subscription vocabulary.** Putting the accept URL in `data()` would hand
 *      a working invite link to whatever third-party endpoint a tenant has configured — a privilege
 *      escalation dressed as an integration.
 *
 * So {@see TenantInvitationNotification} still goes to the invitee from the same line it always did, and
 * this event carries only what a bystander may know: who was invited, as what, by whom, until when. Its
 * consumers are the tenant's own admins (an in-app notice that a seat was offered) and any webhook the
 * tenant has pointed at their own systems.
 */
final class MemberInvited extends DomainEvent
{
    private function __construct(
        public readonly string $tenantId,
        public readonly string $email,
        public readonly string $role,
        public readonly ?string $invitedByUserId,
        public readonly ?string $expiresAt,
    ) {
        parent::__construct();
    }

    public static function for(Tenant $tenant, string $email, string $role, ?User $actor, ?string $expiresAt): self
    {
        return new self(
            tenantId: (string) $tenant->getKey(),
            email: $email,
            role: $role,
            invitedByUserId: $actor === null ? null : (string) $actor->getKey(),
            expiresAt: $expiresAt,
        );
    }

    public function eventType(): DomainEventType
    {
        return DomainEventType::MemberInvited;
    }

    /** An invitation always belongs to a tenant — narrower than the base's nullable contract (covariant). */
    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * The invitee's email IS included: it is the identifier of the thing that happened, the tenant already
     * knows it (they typed it), and the endpoint is one the tenant configured for their own workspace. The
     * token is what must never appear here — see the class docblock.
     *
     * @return array<string, scalar|null>
     */
    public function data(): array
    {
        return [
            'email' => $this->email,
            'role' => $this->role,
            'invited_by' => $this->invitedByUserId,
            'expires_at' => $this->expiresAt,
        ];
    }
}
