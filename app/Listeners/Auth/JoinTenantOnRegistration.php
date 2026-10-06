<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\TenantUser;
use App\Models\User;
use App\Services\Settings\RegistrationGate;
use App\Services\Tenancy\TenantMembershipService;
use Illuminate\Http\Request;

/**
 * Turn a registration on a tenant's subdomain into a membership of that tenant (Increment I5, PRD Feature
 * #10) — when the registrant CONFIRMS the address, and not before (M140, `D34` = A). This is what makes the
 * Access panel's "off" position mean something: before I5, registering at `acme.meridian.test/register`
 * created an account that belonged to no workspace at all.
 *
 * ── ON CONFIRMATION, NOT ON REGISTRATION, AND THAT IS THE WHOLE OF M140's FIX ─────────────────────────────
 * Until M140 this was a `Registered` listener, so anyone who could reach an open workspace's sign-up form could
 * register someone else's address and hold an ACTIVE membership for it before any link was clicked
 * (`R-2dc95042`). An Active membership is what `SsoUserProvisioner::provision()` trusts ahead of its domain
 * check, so an SSO workspace that opened its own registration could later have its identity provider sign in
 * as the address's real owner (`R-5ce75abf`). `D34` = A: the address is not the account's own until the
 * emailed link is clicked, so nothing — membership, seat, points, the owners' "joined" notice — is minted
 * until then. It is no longer a listener: {@see SendWelcomeEmail}, the one `Verified` listener, calls
 * {@see self::onConfirmation()} FIRST and welcomes second, because the welcome reads the membership this
 * writes and two auto-discovered listeners have no guaranteed order. (No `handle()` method, so event
 * discovery no longer registers this class.)
 *
 * ── THREE CONDITIONS, EACH CLOSING A DOOR ────────────────────────────────────────────────────────────────
 *  1. **The person's FIRST confirmation** (`welcomed_at` still null). `Verified` fires again after every
 *     address change (`UpdateUserProfileInformation`), and a member re-confirming on another open workspace's
 *     host must not join it.
 *  2. **The password is still the one chosen at registration** — `password_set_at` equals `created_at`, which
 *     `CreateNewUser` stamps from one instant. A reset means the person holding the account is not provably
 *     the person who registered it: an owner who reclaims a squatted address, then clicks a confirmation link
 *     the squatter re-sent from the squatter's own workspace host, must not join that workspace. ⚠️ Cost,
 *     accepted: a registrant who resets before confirming is not joined automatically. It also excludes every
 *     account no registration made — a Google or SSO account has no `password_set_at`, an invitee's is
 *     stamped at acceptance — so they never join here.
 *  3. **The workspace still admits registrations** ({@see RegistrationGate}), asked again because the answer
 *     may have changed since the POST. The confirmation link carries the host it was requested on.
 *
 * ── SYNCHRONOUS, AND THAT IS NOT AN OVERSIGHT ──────────────────────────────────────────────────────────────
 * A queued worker has no request, and the request's host is the only thing that says WHICH workspace this is.
 * Central-host confirmations join nothing: there is no subdomain, so there is no workspace.
 */
final class JoinTenantOnRegistration
{
    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly RegistrationGate $gate,
    ) {}

    /**
     * Join the workspace this confirmation's host addresses, when all three conditions hold.
     *
     * Null when nothing was joined — including a full seat quota, which `joinOpenTenant()` answers with null
     * rather than a 402 (see its docblock).
     */
    public function onConfirmation(User $user, Request $request): ?TenantUser
    {
        if ($user->welcomed_at !== null) {
            return null; // an address change, not a registration
        }

        if (! self::passwordIsTheRegistrants($user)) {
            return null;
        }

        $tenant = $this->gate->tenantFor($request);

        if ($tenant === null || ! $this->gate->allows($request)) {
            return null;
        }

        return $this->memberships->joinOpenTenant($tenant, $user);
    }

    /** Is the account's password the one its registration chose? Stamped from one instant by `CreateNewUser`. */
    private static function passwordIsTheRegistrants(User $user): bool
    {
        $setAt = $user->password_set_at;
        $createdAt = $user->created_at;

        return $setAt !== null && $createdAt !== null && $setAt->equalTo($createdAt);
    }
}
