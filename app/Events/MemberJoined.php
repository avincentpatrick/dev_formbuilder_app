<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DomainEventType;
use App\Enums\NotificationType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone self-registered on a tenant's subdomain and became a member (Increment J3a). Raised by
 * {@see TenantMembershipService::joinOpenTenant()}.
 *
 * ── ⚠️ A PLAIN EVENT, NOT A {@see DomainEvent}, AND THAT IS THE SCOPE DECISION RATHER THAN AN OVERSIGHT ──
 * {@see DomainEventType} is not merely a catalog: it is the webhook and connector SUBSCRIPTION vocabulary,
 * and it feeds `openapi.json`. A case there is a four-file act — a `webhook_deliveries_event_type_check`
 * migration, an exhaustive `label()` arm, and arms in two presenters whose `UnhandledMatchError` is a fatal
 * — with a published contract attached forever after.
 *
 * **What that costs, stated rather than discovered: a tenant cannot make their own systems react to a
 * self-registration.** No webhook delivery, no Slack or Sheets fan-out, nothing in the subscription list
 * they see on `/webhooks`. Only the bell and the email. `docs/feature-backlog.md` carries the row to open if
 * anyone asks for the other half; promoting this to a `DomainEvent` later is additive and breaks nothing.
 *
 * ── ⚠️ RAISED INSIDE THE TRANSACTION, WHICH IS THE OPPOSITE OF {@see DomainEvent}'S RULE ────────────────
 * `DomainEvent`'s docblock says post-commit, always. This one must not be, and the reason is structural
 * rather than stylistic — it is the third consequence of `joinOpenTenant()` being the one membership write
 * in the codebase that runs with NO ambient tenant context:
 *
 *   1. `TenantContext::applyLocal()` is `SET LOCAL`, so the RLS GUC dies WITH the transaction. `notifications`
 *      is strict-RLS, so a post-commit listener's INSERT is REFUSED — not mis-scoped, refused.
 *   2. Spatie's permissions team id is restored in the same `finally`, and
 *      `NotificationRecipientResolver` depends on it to resolve who holds `owner`/`admin` here.
 *   3. Atomicity improves rather than degrades: a rolled-back membership leaves no phantom bell row. The
 *      queued EMAIL still cannot precede the commit, because `after_commit` is on globally (ADR-0007 §D8).
 *
 * ── THE TWO SILENT STATES ARE RESPECTED BY POSITION, NOT BY A CONDITION ────────────────────────────────
 * `joinOpenTenant()` returns early — `null` on a full seat quota, the existing row when the person is
 * already an active member — and BOTH returns sit above the emission point. Neither is "someone joined", so
 * neither raises this. That is enforced by where the `event()` call is written; each early return carries a
 * ⚠️ comment saying so, because hoisting the emission would break it invisibly.
 */
final class MemberJoined
{
    use Dispatchable;

    private function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        /** The joiner's display name — what {@see NotificationType::MemberJoined}'s copy renders. */
        public readonly string $name,
        public readonly string $role,
    ) {}

    public static function for(Tenant $tenant, User $user, string $role): self
    {
        return new self(
            tenantId: (string) $tenant->getKey(),
            userId: (string) $user->getKey(),
            name: (string) $user->name,
            role: $role,
        );
    }
}
