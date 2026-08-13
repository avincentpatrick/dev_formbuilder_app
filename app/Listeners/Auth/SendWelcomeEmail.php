<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use App\Support\Tenancy\PlatformHost;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Auth\Events\Verified;
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
}
