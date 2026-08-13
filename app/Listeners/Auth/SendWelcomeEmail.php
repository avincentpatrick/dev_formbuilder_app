<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use App\Support\Tenancy\PlatformHost;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Say hello, once, when a person's email address is confirmed (Increment J3a).
 *
 * ── ON `Verified` RATHER THAN `Registered`, AND ONE LISTENER COVERS EVERY DOOR ─────────────────────────
 * See {@see WelcomeNotification}'s docblock for why: a welcome raised on `Registered` competes with the
 * verification link in the same inbox in the same second, and ordering the listeners only chooses which of
 * the two is on top. `Verified` also reaches paths `Registered` never sees — Fortify's verification
 * controller, and any future flow that stamps `email_verified_at` and fires the event.
 *
 * ⚠️ **`InvitationController` deliberately does NOT reach this**, and that is worth knowing rather than
 * discovering: it sets `email_verified_at` with a `forceFill` and fires no `Verified` event, so an invitee
 * gets the invitation email and joins — no welcome. Correct: they were already told what this is and who
 * invited them, by a person they know. A second "welcome to the product" would be the third email in that
 * sequence and the least useful one.
 *
 * ── SYNCHRONOUS, LIKE {@see JoinTenantOnRegistration} AND FOR THE SAME REASON ──────────────────────────
 * The tenant is resolved from `request()->getHost()`, which a queued worker does not have. The
 * NOTIFICATION is queued (`ShouldQueue` on `QueueName::Mail`); this listener only has to build its three
 * scalars while the request is still standing.
 *
 * ── DELIVERED TO AN ON-DEMAND NOTIFIABLE, WHICH IS THE §D5 RULE ────────────────────────────────────────
 * `Notification::route('mail', $email)` rather than `$user->notify()`: a queued notification serializes its
 * notifiable, and a `User` restored on a worker under a NULL GUC fails closed against the join-shape RLS on
 * `users`. Every queued mail notification in this application is sent this way.
 */
final class SendWelcomeEmail
{
    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $email = (string) $user->getEmailForVerification();

        if ($email === '') {
            return;
        }

        // `tenants`/`domains` are RLS-exempt central tables, so this answers under any context — the same
        // call `JoinTenantOnRegistration` makes to decide whether a registration joins a workspace at all.
        // Null is the CENTRAL host: a real, documented state (an account belonging to no workspace yet),
        // not a failure to look one up.
        $tenant = PlatformHost::tenantFor(request()->getHost());

        // ⚠️ THE HOST IS NOT A MEMBERSHIP, AND A FIRST DRAFT OF THIS LISTENER TREATED IT AS ONE.
        // `joinOpenTenant()` returns null when the seat quota is full — its "SILENT STATE 2 OF 2" — and
        // `JoinTenantOnRegistration` discards that null deliberately, leaving a committed account with no
        // membership. That is not exotic: the Free plan caps `active_seats` at 2, so the THIRD person to
        // self-register on any free-tier workspace lands in it. They still verify their address on the
        // tenant subdomain, so the host still resolves Acme — and without this check the product's ONLY
        // non-transactional email would tell the one person who was quietly refused a seat, in writing,
        // that they are a member of a workspace they were just kept out of.
        //
        // Read under the tenant's own context because `tenant_users` is strict-RLS: without the GUC the
        // query returns nothing and every welcome would silently degrade to the central-host wording.
        if ($tenant !== null && ! $this->isMemberOf($tenant, (string) $user->getKey())) {
            $tenant = null;
        }

        Notification::route('mail', $email)->notify(new WelcomeNotification(
            name: (string) $user->name,
            // An explicit null check rather than `$tenant?->name ?? ''`: PHPStan flags a nullsafe on the
            // left of `??` as redundant (the `??` already swallows the null), and this reads the same way as
            // the `actionUrl` ternary directly below — one shape for one question.
            tenantName: $tenant === null ? '' : (string) $tenant->name,
            // Built here because a worker can resolve neither the tenant's app host nor the central one.
            // `TenantUrl::to()` is the APP arm, never `toPublic()` — a welcome points at the workspace, not
            // at a guest form runtime.
            actionUrl: $tenant === null
                ? rtrim((string) config('app.url'), '/').'/'
                : TenantUrl::to($tenant, 'dashboard'),
        ));
    }

    /**
     * Is this user an ACTIVE member of `$tenant`?
     *
     * Borrows the tenant's RLS context for the read and restores whatever was there, the
     * `TenantMembershipService::joinOpenTenant()` shape — this listener runs on Fortify's verification route,
     * which carries no tenancy middleware, so there is no ambient GUC to rely on.
     *
     * `Invited` and `Removed` are deliberately NOT members: an unaccepted invitation grants nothing (§7), and
     * telling someone they are a member of a workspace they have not joined is the same lie in a different
     * direction.
     */
    private function isMemberOf(Tenant $tenant, string $userId): bool
    {
        $tenantId = (string) $tenant->getKey();
        $savedTenant = TenantContext::currentTenantId();
        $savedUser = TenantContext::currentUserId();

        // `applyLocal()` is `SET LOCAL`, a silent no-op outside a transaction — hence the wrapper.
        return DB::transaction(function () use ($tenantId, $userId, $savedTenant, $savedUser): bool {
            TenantContext::applyLocal($tenantId, $userId);

            try {
                return TenantUser::query()
                    ->where('user_id', $userId)
                    ->where('status', TenantUserStatus::Active)
                    ->exists();
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }
}
