<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Support\Webhooks\OutboundUrlGuard;

/**
 * One third-party service the native-connector framework can talk to (ADR-0009, H15a). Four responsibilities,
 * deliberately no more: build the consent URL, exchange a code, refresh a grant, and push one event.
 *
 * ADR-0009 rejects an OAuth client library for this: Socialite is built around a same-host, session-backed
 * redirect, whereas this framework's callback is stateless and lands on the central domain, so the library
 * would have to be bent around the design rather than supporting it. An adapter is ~one small class.
 *
 * Implementations must:
 *   - never persist anything (the service layer owns the {@see Connection} row);
 *   - guard every outbound host through {@see OutboundUrlGuard} before calling it —
 *     the repo's single SSRF authority, applied to connector endpoints for the same reason it is applied to
 *     tenant-supplied webhook URLs: a resolved host is not trusted just because a config file named it;
 *   - never follow redirects and always bound the request with the webhook timeouts;
 *   - reduce a wire response to {@see ConnectorGrant} / {@see ConnectorDeliveryResult} so no provider field
 *     name escapes the adapter.
 */
interface ConnectorProvider
{
    public function key(): ConnectorProviderKey;

    /**
     * The provider's consent screen URL for this flow. `$state` is the signed, provider-bound state token
     * (ADR-0009 §D3) and `$redirectUri` the central-domain callback (§D2) — both are opaque to the adapter.
     */
    public function authorizeUrl(string $state, string $redirectUri): string;

    /**
     * Trade an authorization code for a grant.
     *
     * @throws ConnectorOAuthException the provider refused the exchange (expired/reused code, bad client)
     */
    public function exchangeCode(string $code, string $redirectUri): ConnectorGrant;

    /**
     * Trade a refresh token for a fresh grant. A provider that returns no new refresh token leaves
     * {@see ConnectorGrant::$refreshToken} null and the caller keeps the stored one.
     *
     * @throws ConnectorOAuthException the provider refused (the grant is dead — the connection is marked
     *                                 `refresh_failed`, not retried into oblivion)
     */
    public function refresh(string $refreshToken): ConnectorGrant;

    /**
     * Push one domain-event envelope to the subscription's destination. Never throws for a delivery failure —
     * the outcome (including a dead credential) is the returned value, mirroring how `DeliverWebhookJob`
     * records rather than throws (a customer-side failure is a business condition, not a job defect).
     *
     * @param  array<string, mixed>  $envelope  the `DomainEvent` envelope
     */
    public function deliver(Connection $connection, ConnectionSubscription $subscription, array $envelope): ConnectorDeliveryResult;
}
