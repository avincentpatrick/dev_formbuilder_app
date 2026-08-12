<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Services\Connectors\ConnectionService;
use App\Support\Connectors\ConnectorDeliveryResult;
use App\Support\Connectors\ConnectorGrant;
use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The Airtable adapter — the third {@see ConnectorProvider} (ADR-0009, webhook-integration-design.md §4 row 5,
 * H16c). Tabular like Google Sheets, but its OAuth differs from both predecessors in three ways, and each one
 * is a thing the framework had never been asked for.
 *
 * ── 1. PKCE IS MANDATORY, AND THIS FRAMEWORK HAD NOWHERE TO PUT A VERIFIER ───────────────────────────────
 *
 * Airtable requires `code_challenge` + `code_challenge_method=S256` at the consent screen and the matching
 * `code_verifier` at the token endpoint (verified against its OAuth reference at implementation time,
 * 2026-08-13). Those two calls happen in DIFFERENT REQUESTS ON DIFFERENT HOSTS — the authorize on a tenant
 * subdomain with a session, the exchange on the central domain with none — which is §D2/§D3's design and
 * exactly what makes "stash the verifier in the session" unavailable.
 *
 * The answer is {@see ConnectorOAuthStateService::codeVerifierFor()}: derive it from the state token both
 * halves already hold, under the key that signs the state. No session, no cache row, no table. This adapter
 * therefore never generates or stores a verifier — it is handed one and publishes its S256 digest.
 *
 * ── 2. THE TOKEN ENDPOINT AUTHENTICATES WITH HTTP BASIC, NOT FORM FIELDS ─────────────────────────────────
 *
 * Slack and Google both take `client_id`/`client_secret` as form fields. Airtable requires
 * `Authorization: Basic base64(client_id:client_secret)` for a confidential client and REFUSES the header for
 * a public one. Sending the secret in the body instead is not a benign difference: it is a 401 with an error
 * code that reads like a bad code rather than a bad request shape.
 *
 * ── 3. REFRESH TOKENS ROTATE, WHICH MAKES A PREVIOUSLY DORMANT BUG PATH LIVE ─────────────────────────────
 *
 * Slack's bot tokens never expire; Google returns no new refresh token, so the stored one is reused forever.
 * Airtable returns a NEW access + refresh pair on every refresh and INVALIDATES the previous pair. Dropping
 * the returned refresh token would kill the connection at the first renewal — 60 minutes after connecting.
 * {@see ConnectionService::applyRefreshedGrant()} already writes `$grant->refreshToken ?? $stored`, so
 * rotation persists correctly with no change there; what matters here is that {@see refresh()} must pass the
 * NEW token through rather than the one it was called with.
 *
 * ── THE CLASSIFICATION RULE INHERITED FROM H16a, WHICH STILL APPLIES ─────────────────────────────────────
 *
 * A 401 from the DATA API is reported as a retryable failure, never as
 * {@see ConnectorDeliveryResult::credentialRejected()}. An access token that lived 60 minutes and just
 * expired is indistinguishable at that endpoint from a grant the tenant revoked, and mapping it the obvious
 * way would revoke a healthy connection roughly hourly. Only {@see refresh()} throwing can know.
 *
 * Every outbound call goes through {@see OutboundUrlGuard} first, per the interface's stated rules.
 */
final class AirtableConnector implements ConnectorProvider
{
    private const AUTHORIZE_URL = 'https://airtable.com/oauth2/v1/authorize';

    private const TOKEN_URL = 'https://airtable.com/oauth2/v1/token';

    /** Scope-free (verified 2026-08-13) — it returns the user id for ANY valid token, and the email only with `user.email:read`, which this connector does not request. */
    private const WHOAMI_URL = 'https://api.airtable.com/v0/meta/whoami';

    /**
     * Token-endpoint errors meaning THE GRANT IS DEAD rather than "this request failed". Airtable uses the
     * RFC 6749 vocabulary: `invalid_grant` for a refresh token that was revoked or has passed its 60-day
     * life, the other two for the app-registration cases. No retry fixes any of them.
     */
    private const CREDENTIAL_ERRORS = ['invalid_grant', 'invalid_client', 'unauthorized_client'];

    public function __construct(
        private readonly OutboundUrlGuard $guard,
    ) {}

    public function key(): ConnectorProviderKey
    {
        return ConnectorProviderKey::Airtable;
    }

    public function authorizeUrl(string $state, string $redirectUri, string $codeVerifier): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('connectors.providers.airtable.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()), // Airtable delimits with SPACES, like Google
            'state' => $state,
            'code_challenge' => self::challengeFor($codeVerifier),
            // S256 is the only method Airtable accepts; `plain` is refused outright rather than downgraded.
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ConnectorGrant
    {
        $body = $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], fn (string $error): ConnectorOAuthException => ConnectorOAuthException::exchangeFailed($error, $this->isTerminal($error)));

        $accessToken = self::accessTokenFrom($body);

        return $this->grantFrom($body, null, $this->accountIdFor($accessToken), 'Airtable');
    }

    public function refresh(string $refreshToken): ConnectorGrant
    {
        $body = $this->postToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], fn (string $error): ConnectorOAuthException => ConnectorOAuthException::refreshFailed($error, $this->isTerminal($error)));

        // ⚠️ The identity is NOT re-fetched here, and that is deliberate rather than lazy. `whoami` is a second
        // network call, and a blip on it during the hourly sweep would surface as a refresh FAILURE — which is
        // terminal: it clears the tokens, pauses every rule and emails the owner. Trading a field the caller
        // does not read for that risk is the H16a classification trap in a new costume.
        // `ConnectionService::applyRefreshedGrant()` writes only the tokens, the expiry and the status; these
        // two arguments reach no column on this path.
        return $this->grantFrom($body, $refreshToken, '', 'Airtable');
    }

    public function deliver(Connection $connection, ConnectionSubscription $subscription, array $envelope): ConnectorDeliveryResult
    {
        // H16c (1/3) ships the OAuth arm only; the mapping-driven record write is H16c (2/3), which replaces
        // this method wholesale. `blocked` rather than `failed` on purpose: the rule cannot succeed by being
        // retried, so it pauses once with a reason a human can read instead of climbing the retry ladder.
        return ConnectorDeliveryResult::blocked(null, '[not_ready] The Airtable connector can’t deliver yet. Nothing was sent.');
    }

    /**
     * The PKCE challenge: base64url(SHA-256(verifier)), unpadded, per RFC 7636 §4.2.
     *
     * The digest is taken over the verifier's ASCII characters, NOT over bytes decoded from it — a verifier is
     * a string in that spec, and treating base64url output as data to decode first is the classic way to build
     * a challenge the provider computes differently and rejects with an opaque `invalid_grant`.
     */
    private static function challengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * The shared token-endpoint call. Guards the host, authenticates with HTTP Basic, POSTs form-encoded, and
     * converts a transport error or an error body into the caller's exception.
     *
     * @param  array<string, string>  $form
     * @param  callable(string): ConnectorOAuthException  $failure
     * @return array<string, mixed>
     */
    private function postToken(array $form, callable $failure): array
    {
        try {
            $this->guard->assertPublic(self::TOKEN_URL);
        } catch (BlockedWebhookUrlException $e) {
            throw $failure($e->reason);
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withBasicAuth(
                    (string) config('connectors.providers.airtable.client_id'),
                    (string) config('connectors.providers.airtable.client_secret'),
                )
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->asForm()
                ->post(self::TOKEN_URL, $form);
        } catch (ConnectionException) {
            // Non-terminal: a timeout is not evidence that the grant is dead, and marking it so would revoke
            // a healthy connection over a dropped packet.
            throw $failure('transport_error');
        }

        $body = $response->json();

        if (! is_array($body) || ! $response->successful() || isset($body['error'])) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';

            throw $failure($error);
        }

        return $body;
    }

    /**
     * The Airtable user id behind a freshly issued token, used as `external_account_id`.
     *
     * Unlike Google — whose `grantFrom()` records a CONSTANT id because reading the real one would cost an
     * identity scope, and accepts the resulting one-connection-per-tenant narrowing — Airtable's `whoami`
     * needs no scope at all. So a tenant can hold two Airtable grants for two different Airtable accounts and
     * `connections_tenant_provider_account_unique` tells them apart, which is the behaviour that index was
     * written for.
     *
     * A failure here fails the CONNECT rather than falling back to a constant: a fallback would let two
     * genuinely different accounts collapse onto one row and silently overwrite each other's tokens, and the
     * user is standing right there and can click Connect again.
     */
    private function accountIdFor(string $accessToken): string
    {
        try {
            $this->guard->assertPublic(self::WHOAMI_URL);
        } catch (BlockedWebhookUrlException $e) {
            throw ConnectorOAuthException::exchangeFailed($e->reason);
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->withToken($accessToken)
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->get(self::WHOAMI_URL);
        } catch (ConnectionException) {
            throw ConnectorOAuthException::exchangeFailed('identity_unavailable', false);
        }

        $body = $response->json();
        $id = is_array($body) && is_string($body['id'] ?? null) ? $body['id'] : '';

        if (! $response->successful() || $id === '') {
            throw ConnectorOAuthException::exchangeFailed('identity_unavailable', false);
        }

        return $id;
    }

    /**
     * Normalize an Airtable token response into a grant.
     *
     * @param  array<string, mixed>  $body
     */
    private function grantFrom(array $body, ?string $existingRefreshToken, string $accountId, string $accountLabel): ConnectorGrant
    {
        $accessToken = self::accessTokenFrom($body);

        $refreshToken = is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : $existingRefreshToken;

        if ($refreshToken === null) {
            // Airtable issues a refresh token on every successful grant, so its absence on the FIRST exchange
            // means the integration is misconfigured rather than that the tenant did something. Refusing here
            // is what stops a connection that looks healthy for 60 minutes and then cannot be renewed — the
            // same guard `GoogleSheetsConnector` carries for the `access_type=offline` case.
            throw ConnectorOAuthException::exchangeFailed('missing_refresh_token');
        }

        $expiresIn = $body['expires_in'] ?? null;

        return new ConnectorGrant(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: is_int($expiresIn) && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            scopes: $this->grantedScopes($body),
            externalAccountId: $accountId,
            externalAccountLabel: $accountLabel,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function accessTokenFrom(array $body): string
    {
        $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        if ($accessToken === '') {
            throw ConnectorOAuthException::exchangeFailed('missing_access_token');
        }

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function grantedScopes(array $body): array
    {
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : '';

        return $scope === '' ? $this->scopes() : array_values(array_filter(explode(' ', $scope)));
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('connectors.providers.airtable.scopes', []);

        return is_array($scopes) ? array_values(array_map(strval(...), $scopes)) : [];
    }

    private function isTerminal(string $error): bool
    {
        return in_array($error, self::CREDENTIAL_ERRORS, true);
    }
}
