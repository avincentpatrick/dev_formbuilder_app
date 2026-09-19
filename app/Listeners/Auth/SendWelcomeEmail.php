<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use App\Support\Branding\BrandPalette;
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

        // ⛔ ONCE PER PERSON, NOT ONCE PER EVENT (M103, R-4f23d9c7). The docblock above has always said
        // "once"; nothing enforced it. `Verified` repeats for a real, ordinary reason —
        // `UpdateUserProfileInformation::updateVerifiedUser()` nulls `email_verified_at` and re-sends
        // verification on every address change, and the tenant Settings page offers that field to every
        // member — so a tester fixing a typo in their own address was welcomed to the product twice.
        if (! $this->claimWelcome($user)) {
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

        // The header logo's destination, resolved HERE (M99, R-62fb2e05). Without it the palette fell
        // back to `config('app.url')`, which D46 puts at the agency's own public website — so the one
        // non-transactional email the product sends linked its header off this application. Note this is
        // NOT `BrandPalette::forTenant($tenant)`: that requires the tenant to match the current tenant
        // context, and this listener runs on Fortify's verification route with no TENANT identification and
        // no ambient GUC (see isMemberOf() below), so it would silently return the product palette and
        // change nothing. The value is the one already computed for `actionUrl`.
        //
        // ⚠️ An account that belongs to no workspace gets NO LINK rather than the central address. There
        // is no page there for it — that absence is R-e6a10f97's subject, and its copy awaits D44 — and
        // under D46 the central address is somebody else's website.
        Notification::route('mail', $email)->notify((new WelcomeNotification(
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
        ))->withBrand(BrandPalette::product(
            $tenant === null ? '' : TenantUrl::to($tenant, 'dashboard')
        )));
    }

    /**
     * Claim the one welcome this person is owed, atomically. True if this call won it.
     *
     * ── A CONDITIONAL UPDATE, NOT read-then-write ─────────────────────────────────────────────────────
     * `WHERE welcomed_at IS NULL` inside the UPDATE makes the claim and the test one statement, so two
     * concurrent `Verified` events for the same account cannot both see null and both send. The affected
     * row count IS the answer. This is the shape `PointsRecorder` uses for "award exactly once", in the
     * only form available here — `users` is a central table with no tenant to hang a ledger row on.
     *
     * ⛔ THE USER GUC IS SET EXPLICITLY, BECAUSE AN UNQUALIFIED UPDATE HERE WRITES NOTHING ON ONE OF THE
     * TWO DOORS. `users` carries FORCE RLS, and PostgreSQL applies SELECT policies to an UPDATE whose
     * WHERE reads a column — so with no `app.current_user_id` the row is invisible to its own update,
     * which affects ZERO rows and raises nothing. That GUC happens to be set on the Fortify verification
     * door, where `config/fortify.php` mounts `EstablishTenantDatabaseContext` ahead of the controller.
     * It is NOT set on the other door: `GoogleSessionStarter` fires `Verified` immediately after
     * `Auth::login()`, and that same config file records in terms that "a write issued AFTER
     * Auth::login() in the same request still has no user GUC". Relying on the ambient value would have
     * produced a guard that passed every test and silently failed for Google sign-ups.
     *
     * So the context is BORROWED for the write, the shape {@see isMemberOf()} below already uses — and
     * the arm it satisfies is `users_users_visibility`'s first disjunct, `id = app.current_user_id`.
     * Setting a person's own id to read their own row is the narrowest possible use of it. The tenant
     * half is left exactly as found, because this policy's self arm does not consult it.
     *
     * ⚠️ NOT `pgsql_auth`, THOUGH ITS `USING (true)` CARVE-OUT WOULD ALSO HAVE WORKED. That connection is
     * a separate SESSION, so under `RefreshDatabase` it cannot see an uncommitted fixture — every
     * existing test in `WelcomeEmailTest` builds its user with `User::factory()`, and routing this write
     * there would have made the guard refuse them all and silently suppressed the very email those tests
     * assert. Same trap as `/members`, reached from the other side: the connection that is right for a
     * pre-auth READ is wrong for a write the request's own session must see.
     *
     * ⚠️ SOFT-DELETED ACCOUNTS CLAIM NOTHING, which is the wanted answer rather than an oversight: the
     * model's `SoftDeletes` scope excludes them, the update matches no row, and a deleted account is not
     * someone to welcome to the product.
     */
    private function claimWelcome(User $user): bool
    {
        $userId = (string) $user->getKey();
        $savedTenant = TenantContext::currentTenantId();
        $savedUser = TenantContext::currentUserId();

        // `applyLocal()` is `SET LOCAL`, a silent no-op outside a transaction — hence the wrapper.
        return DB::transaction(function () use ($userId, $savedTenant, $savedUser): bool {
            TenantContext::applyLocal($savedTenant, $userId);

            try {
                return User::query()
                    ->whereKey($userId)
                    ->whereNull('welcomed_at')
                    ->update(['welcomed_at' => now()]) === 1;
            } finally {
                TenantContext::applyLocal($savedTenant, $savedUser);
            }
        });
    }

    /**
     * Is this user an ACTIVE member of `$tenant`?
     *
     * Borrows the tenant's RLS context for the read and restores whatever was there, the
     * `TenantMembershipService::joinOpenTenant()` shape — this listener runs on Fortify's verification route,
     * which carries no TENANT identification, so there is no ambient tenant GUC to rely on.
     *
     * ⚠️ PRECISION CORRECTED IN M103, BECAUSE A WRITE NOW SITS BESIDE IT. Both of this file's notes used
     * to say the route "carries no tenancy middleware" and "no ambient GUC" flat. `config/fortify.php`
     * DOES mount `EstablishTenantDatabaseContext` on that group, so `app.current_user_id` is set here;
     * what is absent is stancl's tenant IDENTIFICATION, so `app.current_tenant_id` is null on every
     * Fortify route, subdomain or not. Both conclusions below are unaffected — this method still has to
     * borrow the tenant's context — but the flat version would have told the next reader that `users`
     * writes from here are impossible, which is the opposite of what {@see claimWelcome()} relies on.
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
