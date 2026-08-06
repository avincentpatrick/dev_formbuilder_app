<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Http\Middleware\GateRegistration;
use App\Models\User;
use App\Services\Settings\RegistrationGate;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\PlatformHost;
use Illuminate\Auth\Events\Registered;

/**
 * Turn a registration on a tenant's subdomain into a membership of that tenant (Increment I5, PRD Feature
 * #10). This is what makes the Access panel's "off" position mean something: before I5, registering at
 * `acme.meridian.test/register` created an account that belonged to no workspace at all, so the toggle
 * would have had one live position and one decorative one.
 *
 * ── SYNCHRONOUS, NOT QUEUED, AND THAT IS NOT AN OVERSIGHT ──────────────────────────────────────────────
 * Two reasons, either sufficient: a queued worker has no request, so `request()->getHost()` — the only
 * thing that says WHICH workspace this is — would be gone by the time it ran; and Fortify logs the user in
 * and redirects to the dashboard immediately, so a membership that materializes a second later means the
 * person's very first page load is the one that 403s.
 *
 * ── IT DOES NOT RE-CHECK WHETHER REGISTRATION WAS ALLOWED ──────────────────────────────────────────────
 * {@see GateRegistration} already 404ed a POST that should not have happened, on the same
 * {@see RegistrationGate} answer. Re-asking here would be a second implementation of the rule — the thing
 * that class exists to prevent — and would be asking it at the wrong moment anyway: the account has been
 * created and committed by now, so "no" has no useful meaning left.
 *
 * Central-host registrations fall through untouched: there is no subdomain, so there is no workspace to
 * join, which is exactly the pre-I5 behaviour.
 */
final class JoinTenantOnRegistration
{
    public function __construct(private readonly TenantMembershipService $memberships) {}

    public function handle(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $tenant = PlatformHost::tenantFor(request()->getHost());

        if ($tenant === null) {
            return;
        }

        // Null when the workspace's seat quota is full — a deliberate, documented outcome rather than an
        // exception; see TenantMembershipService::joinOpenTenant().
        $this->memberships->joinOpenTenant($tenant, $event->user);
    }
}
