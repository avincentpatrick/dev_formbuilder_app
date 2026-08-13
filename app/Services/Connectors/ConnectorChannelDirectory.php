<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorChannelException;
use App\Models\Connection;
use App\Support\Connectors\ConnectorChannel;
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
 * NO CACHING, deliberately. `grep -rn "Cache::" app/` finds nothing — the repo has never written to a cache at
 * runtime, and making a picker the first consumer would buy a cache-key tenancy question and an unproven
 * dependency for a call that only fires when a human opens a modal. The client holds the list for the page's
 * lifetime and offers an explicit refresh, which is where the benefit actually was. (`conversations.list` is a
 * Tier-2 method at ~20 requests/minute; user-initiated opens are nowhere near it.)
 */
final class ConnectorChannelDirectory
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

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

        try {
            $page = $lister->channels($connection);
        } catch (ConnectorChannelException $e) {
            return $this->failure($this->message($connection->provider, $e->errorCode));
        }

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
     * @return array{channels: list<array{id: string, label: string, available: bool, unavailable_reason: ?string}>, truncated: bool, error: string}
     */
    private function failure(string $message): array
    {
        return ['channels' => [], 'truncated' => false, 'error' => $message];
    }
}
