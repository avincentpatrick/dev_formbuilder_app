<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorChannelException;
use App\Models\Connection;
use App\Support\Connectors\ConnectorChannel;
use App\Support\Connectors\ConnectorChannelPage;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\ListsChannels;

/**
 * The read model behind the H15b channel picker's JSON sidecar: resolve the provider's optional
 * {@see ListsChannels} capability, ask it, and reduce every outcome — including every
 * failure — to a shape the picker can render.
 *
 * NOTHING HERE THROWS. A picker is an aid, not the feature: a Slack outage, a dead grant or a provider with no
 * listing capability must all leave the tenant able to finish writing their rule (the modal falls back to a
 * manual channel id). So a failure returns `error` beside an empty list rather than a 5xx, which would make the
 * sidecar's client discard the reason along with the response.
 *
 * NO CACHING IN THIS CLASS, deliberately: making a picker the first tenant-scoped cache consumer would buy a
 * cache-key tenancy question and an unproven dependency for a call that only fires when a human opens a
 * modal. The client holds the list for the page's lifetime and offers an explicit refresh, which is where
 * the benefit actually was. (`conversations.list` is a Tier-2 method at ~20 requests/minute; user-initiated
 * opens are nowhere near it.)
 *
 * ⛔ THE JUSTIFICATION ABOVE USED TO REST ON A GLOBAL CLAIM, AND THE GLOBAL CLAIM WAS FALSE. It asserted that
 * grepping the application tree for cache-facade calls finds nothing and that this repository had never
 * written to a cache at runtime. Increment M46 (2026-08-29) measured three runtime cache writes:
 * a connector-refresh lock in RefreshOneConnectionJob, an SSO replay guard in SsoLoginService, and the
 * guest proof-of-work replay guard in GuestChallengeService. ⚠️ THE THIRD IS THE ONE THAT MATTERS TO ANYONE
 * REPEATING THE CHECK: it reaches the cache through an INJECTED Cache\Repository, so the facade grep the old
 * comment prescribed cannot see it at all — and that grep also matched the old comment itself, which is how a
 * claim of absence stayed plausible while sitting three files from its own counter-examples.
 * NAME THE THING, NEVER QUOTE IT: a comment that embeds the command it wants you to run booby-traps that
 * command, and a grep over first-party code can only ever report absence from first-party code.
 * The local decision is unaffected and is stated on its own terms above.
 *
 * ── AN EXPIRED TOKEN IS HANDED OFF, NOT REPORTED AS A DEAD GRANT (M95) ──────────────────────────────────────
 * The Airtable rule editor asks this class for the tenant's bases before anything else, so an ordinary one-hour
 * token expiry used to greet the tenant with "Reconnect this account" on a healthy grant. The same three steps
 * as {@see TabularDestinationDirectory} now stand in front of that sentence: hand off a token already known to
 * be expired, re-read the grant after a refusal in case the sweep rotated it mid-request, and hand off a
 * refreshable grant the provider refused. The marker the hand-off keeps lives in ConnectionTokenRefresher, not
 * here, so the no-caching decision above is unchanged for the listing itself.
 */
final class ConnectorChannelDirectory
{
    /**
     * The codes a lister reports for an access token the provider refused, and that a refresh can cure (M95).
     * `invalid_auth` is Slack's, and the code `AirtableBaseLister` maps a 401 onto; `token_expired` is Slack's
     * answer for a rotation-enabled workspace whose token lapsed. `token_revoked` and `account_inactive` are
     * deliberately absent — no refresh brings those back.
     */
    private const REFUSED_TOKEN_CODES = ['invalid_auth', 'token_expired'];

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectionTokenRefresher $refresher,
    ) {}

    /**
     * @return array{channels: list<array{id: string, label: string, available: bool, unavailable_reason: ?string}>, truncated: bool, error: ?string}
     */
    public function list(Connection $connection): array
    {
        if ($connection->status !== ConnectionStatus::Active) {
            return $this->failure('This workspace needs to be reconnected before we can list its channels.');
        }

        $lister = $this->registry->channelListerFor($connection->provider);

        if ($lister === null) {
            // The NORMAL state for Google Sheets (H16a), not a misconfiguration: under the `drive.file` scope
            // we can reach only the files the tenant explicitly picks, so there is nothing to enumerate
            // server-side and no lister is registered. The registry's null-not-throw contract was written for
            // exactly this case, and the CONNECTION is still perfectly valid.
            return $this->failure('This integration doesn’t offer a destination list. Enter the destination id instead.');
        }

        $handOff = $this->refresher->handOffIfDue($connection, now());

        if ($handOff !== null) {
            return $this->failure($this->handOffMessage($connection->provider, $handOff));
        }

        try {
            return $this->page($lister->channels($connection));
        } catch (ConnectorChannelException $e) {
            return $this->afterRefusal($lister, $connection, $e->errorCode);
        }
    }

    /**
     * @return array{channels: list<array{id: string, label: string, available: bool, unavailable_reason: ?string}>, truncated: bool, error: ?string}
     */
    private function afterRefusal(ListsChannels $lister, Connection $connection, string $errorCode): array
    {
        if (! in_array($errorCode, self::REFUSED_TOKEN_CODES, true)) {
            return $this->failure($this->message($connection->provider, $errorCode));
        }

        // The hourly sweep may have rotated this grant after the route bound the row; then the stored token is
        // already new, and one retry with it is the whole fix.
        $fresh = $connection->fresh();

        if ($fresh instanceof Connection
            && $fresh->status === ConnectionStatus::Active
            && $fresh->access_token !== $connection->access_token) {
            try {
                return $this->page($lister->channels($fresh));
            } catch (ConnectorChannelException $e) {
                if (! in_array($e->errorCode, self::REFUSED_TOKEN_CODES, true)) {
                    return $this->failure($this->message($fresh->provider, $e->errorCode));
                }

                $connection = $fresh;
                $errorCode = $e->errorCode;
            }
        }

        $handOff = $this->refresher->handOffAfterRejection($connection);

        return $this->failure($handOff !== null
            ? $this->handOffMessage($connection->provider, $handOff)
            : $this->message($connection->provider, $errorCode));
    }

    /**
     * @return array{channels: list<array{id: string, label: string, available: bool, unavailable_reason: ?string}>, truncated: bool, error: null}
     */
    private function page(ConnectorChannelPage $page): array
    {
        return [
            'channels' => array_map(static fn (ConnectorChannel $c): array => $c->toArray(), $page->channels),
            'truncated' => $page->truncated,
            'error' => null,
        ];
    }

    /**
     * Provider error code to copy a tenant can act on. The default is deliberately generic: the code can be any
     * string the provider chooses, so echoing it would put unreviewed third-party text on our page.
     *
     * NAMED PER PROVIDER (H16a). Every string here used to say "Slack" from inside a provider-agnostic service
     * — harmless while Slack was the only provider with a lister, and wrong the moment a second one has one.
     * The error CODES stay Slack's because Slack is the only implementor today; a second lister brings its own
     * arm rather than being forced through this vocabulary.
     *
     * ✅ H16c: THE SECOND LISTER ARRIVED AND DID THE OPPOSITE, WHICH IS THE BETTER OUTCOME. `AirtableBaseLister`
     * maps its HTTP statuses ONTO this vocabulary (401 → `invalid_auth`, 403 → `missing_scope`, 429 →
     * `ratelimited`) rather than bringing Airtable's own `error.type` strings here. The codes were never
     * really Slack's — they name what a tenant has to DO, and "reconnect" / "re-grant" / "wait" is the whole
     * space. Translating at the edge is also what keeps third-party text out of this file, which the paragraph
     * above already required for a different reason. Only `provider_unavailable` was genuinely new.
     */
    private function message(ConnectorProviderKey $provider, string $errorCode): string
    {
        $label = $provider->label();

        return match ($errorCode) {
            'invalid_auth', 'token_revoked', 'token_expired', 'account_inactive', 'not_authed' => "{$label} rejected our credentials. Reconnect this account, then try again.",
            'missing_scope' => "The {$label} app is missing the permission needed to list destinations. Reconnect this account to grant it.",
            'ratelimited' => "{$label} is rate-limiting us. Wait a moment and refresh the list.",
            'transport_error', 'provider_unavailable' => "We couldn’t reach {$label}. Check your connection and refresh the list.",
            default => "We couldn’t load destinations from {$label}. Refresh to try again, or enter a destination id.",
        };
    }

    /**
     * A refresh handed to the worker (M95). No time promise, for the reason
     * {@see TabularDestinationDirectory} gives: the job runs on the lowest-priority queue.
     */
    private function handOffMessage(ConnectorProviderKey $provider, string $handOff): string
    {
        $label = $provider->label();

        return $handOff === ConnectionTokenRefresher::HANDOFF_STALLED
            ? "We couldn’t renew access to your {$label} account yet. Refresh the list in a few minutes, or reconnect it if this keeps happening."
            : "We’re renewing access to your {$label} account. Refresh the list shortly.";
    }

    /**
     * @return array{channels: list<array{id: string, label: string, available: bool, unavailable_reason: ?string}>, truncated: bool, error: string}
     */
    private function failure(string $message): array
    {
        return ['channels' => [], 'truncated' => false, 'error' => $message];
    }
}
