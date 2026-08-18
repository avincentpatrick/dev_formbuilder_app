<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Support\Connectors\ConnectorDeliveryResult;
use App\Support\Connectors\ConnectorEventContextResolver;
use App\Support\Connectors\ConnectorGrant;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The Slack adapter — the first {@see ConnectorProvider} (ADR-0009, webhook-integration-design.md §4 row 3,
 * H15a). Bot-token OAuth v2: the tenant installs our Slack app into their workspace, and we post messages
 * as that app with the least-privilege scopes in `config/connectors.php`.
 *
 * TWO SLACK BEHAVIOURS SHAPE THIS CLASS AND ARE EASY TO GET WRONG:
 *
 *  1. **Slack reports failure with HTTP 200.** `oauth.v2.access` and `chat.postMessage` return
 *     `{"ok": false, "error": "..."}` with a 200 status on rejection, so `$response->successful()` means
 *     "the request reached Slack", never "it worked". Every method reads `ok` and treats the HTTP status as
 *     transport-level information only.
 *  2. **Bot tokens do not expire by default.** Unless the workspace enables token rotation, `expires_in` is
 *     absent and there is no refresh token — so {@see exchangeCode()} yields a grant with a null expiry, and
 *     the refresh sweep correctly skips it forever (ADR-0009 §D6). The refresh path is still implemented
 *     because rotation-enabled workspaces do issue expiring tokens, and H16's Google provider will lean on
 *     the same code path heavily.
 *
 * Every outbound call goes through {@see OutboundUrlGuard} first. The host is ours, not a tenant's, so this
 * is not the SSRF case the guard was written for — it is applied anyway because "config named it" is a
 * weaker guarantee than "we checked it," and a single unguarded outbound call site is how that invariant
 * starts eroding. A blocked host is reported as a delivery failure, never an exception into the job.
 */
final class SlackConnector implements ConnectorProvider
{
    private const AUTHORIZE_URL = 'https://slack.com/oauth/v2/authorize';

    private const TOKEN_URL = 'https://slack.com/api/oauth.v2.access';

    private const POST_MESSAGE_URL = 'https://slack.com/api/chat.postMessage';

    /**
     * Slack error codes meaning THE GRANT IS DEAD rather than "this message failed" — the connection must be
     * revoked and the tenant asked to re-connect, not retried on the ladder for seven days.
     */
    private const CREDENTIAL_ERRORS = ['invalid_auth', 'token_revoked', 'token_expired', 'account_inactive', 'not_authed'];

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly SlackMessageFormatter $formatter,
        private readonly ConnectorEventContextResolver $contexts,
    ) {}

    public function key(): ConnectorProviderKey
    {
        return ConnectorProviderKey::Slack;
    }

    /** `$codeVerifier` is unused: Slack's OAuth v2 does not offer PKCE for a confidential client. */
    public function authorizeUrl(string $state, string $redirectUri, string $codeVerifier): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => (string) config('connectors.providers.slack.client_id'),
            'scope' => implode(',', $this->scopes()),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /** `$codeVerifier` is unused — see {@see authorizeUrl()}; nothing was sent, so nothing is proved. */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ConnectorGrant
    {
        $body = $this->postForm(self::TOKEN_URL, [
            'client_id' => (string) config('connectors.providers.slack.client_id'),
            'client_secret' => (string) config('connectors.providers.slack.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], fn (string $error, bool $terminal = true): ConnectorOAuthException => ConnectorOAuthException::exchangeFailed($error, $terminal));

        return $this->grantFrom($body);
    }

    public function refresh(string $refreshToken): ConnectorGrant
    {
        $body = $this->postForm(self::TOKEN_URL, [
            'client_id' => (string) config('connectors.providers.slack.client_id'),
            'client_secret' => (string) config('connectors.providers.slack.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], fn (string $error, bool $terminal = true): ConnectorOAuthException => ConnectorOAuthException::refreshFailed($error, $terminal));

        return $this->grantFrom($body);
    }

    public function deliver(Connection $connection, ConnectionSubscription $subscription, array $envelope): ConnectorDeliveryResult
    {
        $channel = $subscription->config['channel_id'] ?? null;

        if (! is_string($channel) || $channel === '') {
            // A destination-less subscription can never succeed; failing it terminally (as a credential-shaped
            // outcome would) is wrong, but so is retrying — the ladder will exhaust it into the dead letter.
            return ConnectorDeliveryResult::failed(null, '[missing_channel] This subscription has no Slack channel configured.');
        }

        try {
            $this->guard->assertPublic(self::POST_MESSAGE_URL);
        } catch (BlockedWebhookUrlException $e) {
            return ConnectorDeliveryResult::failed(null, '['.$e->reason.'] '.$e->getMessage());
        }

        $message = $this->formatter->build($envelope, $this->contexts->resolve($envelope));

        try {
            $response = Http::withToken($connection->access_token)
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->asJson()
                ->post(self::POST_MESSAGE_URL, [
                    'channel' => $channel,
                    'text' => $message['text'],
                    'blocks' => $message['blocks'],
                ]);
        } catch (ConnectionException $e) {
            return ConnectorDeliveryResult::failed(null, $this->excerpt('[transport_error] '.$e->getMessage()));
        }

        return $this->deliveryOutcome($response);
    }

    /** Map one Slack response to a ledger outcome — remembering that `ok:false` arrives with HTTP 200. */
    private function deliveryOutcome(Response $response): ConnectorDeliveryResult
    {
        $status = $response->status();
        $body = $response->json();

        if (! is_array($body)) {
            return ConnectorDeliveryResult::failed($status, $this->excerpt('[malformed_response] '.$response->body()));
        }

        if (($body['ok'] ?? false) === true) {
            return ConnectorDeliveryResult::delivered($status, 'ok');
        }

        $error = is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';
        $excerpt = $this->excerpt('['.$error.'] Slack rejected the message.');

        return in_array($error, self::CREDENTIAL_ERRORS, true)
            ? ConnectorDeliveryResult::credentialRejected($status, $excerpt)
            : ConnectorDeliveryResult::failed($status, $excerpt);
    }

    /**
     * The shared token-endpoint call. Guards the host, POSTs form-encoded (Slack's OAuth endpoint is not
     * JSON), and converts a transport error or an `ok:false` body into the caller's exception.
     *
     * @param  array<string, string>  $form
     * @param  callable(string, bool=): ConnectorOAuthException  $failure
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $form, callable $failure): array
    {
        try {
            $this->guard->assertPublic($url);
        } catch (BlockedWebhookUrlException $e) {
            throw $failure($e->reason);
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->asForm()
                ->post($url, $form);
        } catch (ConnectionException) {
            // NON-TERMINAL (H16a): a timeout says nothing about the credential, and the refresh sweep would
            // otherwise mark the grant dead — clearing both tokens and pausing every rule — because Slack was
            // briefly unreachable. See ConnectorOAuthException's `$terminal` docblock; every other Slack error
            // code keeps the default, because they are all genuine refusals.
            throw $failure('transport_error', false);
        }

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';

            throw $failure($error);
        }

        return $body;
    }

    /**
     * Normalize an `oauth.v2.access` body into a grant. Slack nests the workspace under `team` and — with
     * rotation enabled — returns `expires_in` seconds plus a new refresh token; without it, both are absent
     * and the grant never expires.
     *
     * @param  array<string, mixed>  $body
     */
    private function grantFrom(array $body): ConnectorGrant
    {
        $accessToken = is_string($body['access_token'] ?? null) ? $body['access_token'] : '';

        if ($accessToken === '') {
            throw ConnectorOAuthException::exchangeFailed('missing_access_token');
        }

        $team = is_array($body['team'] ?? null) ? $body['team'] : [];
        $expiresIn = $body['expires_in'] ?? null;

        return new ConnectorGrant(
            accessToken: $accessToken,
            refreshToken: is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : null,
            expiresAt: is_int($expiresIn) && $expiresIn > 0 ? Carbon::now()->addSeconds($expiresIn) : null,
            scopes: $this->grantedScopes($body),
            externalAccountId: is_string($team['id'] ?? null) ? $team['id'] : 'unknown',
            externalAccountLabel: is_string($team['name'] ?? null) ? $team['name'] : 'Slack workspace',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function grantedScopes(array $body): array
    {
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : '';

        return $scope === '' ? $this->scopes() : array_values(array_filter(explode(',', $scope)));
    }

    /** @return list<string> */
    private function scopes(): array
    {
        $scopes = config('connectors.providers.slack.scopes', []);

        return is_array($scopes) ? array_values(array_map(strval(...), $scopes)) : [];
    }

    private function excerpt(string $body): string
    {
        return mb_substr($body, 0, (int) config('webhooks.response_excerpt_bytes', 2000));
    }
}
