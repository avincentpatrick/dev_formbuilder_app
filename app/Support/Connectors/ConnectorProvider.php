<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Services\Connectors\ConnectorTester;
use App\Support\Webhooks\OutboundUrlGuard;

/**
 * One third-party service the native-connector framework can talk to (ADR-0009, H15a). Four responsibilities,
 * deliberately no more: build the consent URL, exchange a code, refresh a grant, and push one event.
 *
 * H16c (Airtable) is the first provider to press on that: PKCE is mandatory there, and the verifier has to
 * survive the hop from the authorize half-flow (tenant subdomain, session) to the exchange half-flow (central
 * domain, no session). The count of responsibilities held; two SIGNATURES gained a `$codeVerifier`, and that
 * is the honest answer to ADR-0009's "when the second and third providers land" revisit line. A fifth method
 * was not needed, and neither was a store: the verifier is derived from the state token both halves already
 * hold ({@see ConnectorOAuthStateService::codeVerifierFor()}).
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
     *
     * `$codeVerifier` is the PKCE verifier for this flow (H16c). An adapter whose provider does not use PKCE
     * ignores it; one that does publishes `base64url(sha256($codeVerifier))` as `code_challenge` here and
     * sends the verifier itself at {@see exchangeCode()}.
     */
    public function authorizeUrl(string $state, string $redirectUri, string $codeVerifier): string;

    /**
     * Trade an authorization code for a grant. `$codeVerifier` is the same value this flow's
     * {@see authorizeUrl()} was given — see that method, and §D3 for why it needs no storage to survive the
     * hop between the two half-flows.
     *
     * @throws ConnectorOAuthException the provider refused the exchange (expired/reused code, bad client)
     */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ConnectorGrant;

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
     * `$priorWriteUnconfirmed` says the PREVIOUS attempt on this same delivery issued a write and never
     * learned its outcome (M5) — `webhook_deliveries.unconfirmed_write_at` was set by
     * {@see ConnectorDeliveryResult::unconfirmed()}. An adapter whose write is naturally idempotent, or whose
     * destination cannot be searched, may ignore it; one that appends a row to a table the tenant analyses
     * must not, because re-driving blind is how a submission becomes two records. It defaults to `false` so
     * the one caller that is never a retry — {@see ConnectorTester}, which sends a fresh sample on demand —
     * says nothing rather than saying something it would have to keep true.
     *
     * @param  array<string, mixed>  $envelope  the `DomainEvent` envelope
     */
    public function deliver(Connection $connection, ConnectionSubscription $subscription, array $envelope, bool $priorWriteUnconfirmed = false): ConnectorDeliveryResult;
}
