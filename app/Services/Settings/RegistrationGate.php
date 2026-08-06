<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Http\Middleware\GateRegistration;
use App\Models\Tenant;
use App\Support\Tenancy\PlatformHost;
use Illuminate\Http\Request;

/**
 * May this request create an account (Increment I5, PRD Feature #10's first acceptance criterion)?
 *
 * TWO toggles answer it, one per level, and the platform's wins:
 *   · PLATFORM `registration.open_signup` — the super-admin's switch for whether signup is open at all.
 *   · TENANT  `registration.invite_only` — a workspace's own switch, consulted only on ITS subdomain.
 *
 * ── ONE gate, TWO consumers, so they cannot disagree ───────────────────────────────────────────────────
 * {@see GateRegistration} 404s the route, and the login page renders (or hides) its "create an account"
 * link from the same answer. A link to a 404 is the failure this class exists to prevent, and the only way
 * to guarantee it is that neither side re-derives the rule.
 *
 * ── Why the tenant read goes through forTenant() ───────────────────────────────────────────────────────
 * Fortify's routes carry no tenancy middleware, so there is no RLS context on `/register` — and under
 * `settings`'s nullable_global SELECT policy that means a tenant's own rows are INVISIBLE, not absent.
 * Reading them naively would return nothing, fall back to the invite-only default, and quietly make
 * "open" unreachable forever. {@see TenantSettingRegistry::forTenant()} borrows the tenant's context inside
 * a transaction to answer honestly; see its docblock for why the transaction is mandatory.
 */
final class RegistrationGate
{
    public function __construct(
        private readonly PlatformSettings $platform,
        private readonly TenantSettingRegistry $settings,
    ) {}

    public function allows(Request $request): bool
    {
        if (! $this->platform->signupOpen()) {
            return false;
        }

        $tenant = $this->tenantFor($request);

        // The central host: the platform toggle is the whole answer. An account created there belongs to
        // no workspace yet, which is the pre-existing shape of Fortify registration in this product.
        if ($tenant === null) {
            return true;
        }

        return ! $this->settings->getFor($tenant, SettingKey::RegistrationInviteOnly);
    }

    /** The workspace this request addresses, or null on the central host. */
    public function tenantFor(Request $request): ?Tenant
    {
        return PlatformHost::tenantFor($request->getHost());
    }
}
