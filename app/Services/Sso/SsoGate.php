<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoConnection;
use App\Services\Entitlements\EntitlementService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The single place that decides whether a tenant's SAML protocol endpoints may serve (Phase 4, P1a).
 *
 * ── WHY THIS IS A 404 AND NOT THE `feature:` MIDDLEWARE ──────────────────────────────────────────────
 * The repo's idiomatic entitlement gate is `RequireFeature` (`feature:<key>`), and the SETTINGS routes use
 * it. The PROTOCOL routes deliberately do not, for two reasons that only apply here:
 *
 *   1. `RequireFeature` answers a web request with `back()` plus an error toast. The ACS is an
 *      unauthenticated cross-origin POST from an identity provider — there is no `back()` to go to, and a
 *      redirect is not something an IdP's form post can meaningfully follow.
 *   2. Any answer other than 404 discloses that the endpoint exists. "This tenant has SSO configured but you
 *      are not entitled" is information about another organisation's plan, offered to an unauthenticated
 *      caller who supplied nothing but a hostname.
 *
 * So an unentitled, unconfigured, draft or disabled tenant all produce exactly the same response: the
 * endpoint is not there. This is the `PwaManifestController` posture.
 *
 * ⚠️ THE ENTITLEMENT CHECK IS DELIBERATELY SECOND. `EntitlementService::feature()` resolves against the
 * CURRENT tenant context, so it is only meaningful once tenancy has been established — which the protocol
 * route group guarantees via `EstablishTenantDatabaseContext`. Calling it off-tenant returns false (a null
 * plan short-circuits), so ordering it first would turn every misrouted request into a misleading
 * "not entitled" rather than "no connection".
 */
final class SsoGate
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * The tenant's live SAML connection, or a 404 that says nothing about which condition failed.
     *
     * @throws NotFoundHttpException
     */
    public function activeConnectionOrAbort(): SsoConnection
    {
        $connection = SsoConnection::query()->first();

        if ($connection === null || ! $connection->servesProtocol()) {
            throw new NotFoundHttpException;
        }

        if (! $this->entitlements->feature('sso_saml')) {
            throw new NotFoundHttpException;
        }

        return $connection;
    }

    /**
     * Whether the tenant may CONFIGURE SSO — the settings screen's question, which is about the plan alone.
     *
     * Separate from {@see activeConnectionOrAbort()} on purpose: a tenant that is entitled but has not yet
     * configured anything must reach the settings form, and a tenant whose connection is `Draft` must be able
     * to finish it. Neither may serve the protocol.
     */
    public function isEntitled(): bool
    {
        return $this->entitlements->feature('sso_saml');
    }
}
