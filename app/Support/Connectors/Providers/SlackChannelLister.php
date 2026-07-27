<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Exceptions\Connectors\ConnectorChannelException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Support\Connectors\ConnectorChannel;
use App\Support\Connectors\ConnectorChannelPage;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Connectors\ListsChannels;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads a workspace's public channels through `conversations.list` so the H15b rule picker can offer real
 * destinations (ADR-0009 §D8 — this is the whole reason `channels:read` is in the consent scopes).
 *
 * A SEPARATE class from {@see SlackConnector} rather than another method on it: the adapter owns the four
 * {@see ConnectorProvider} responsibilities and is already at the size where a fifth
 * concern starts to blur it. The two share Slack's wire conventions, not their lifecycle — this runs on a page
 * render, that runs in a queue worker.
 *
 * THE SAME TWO SLACK TRAPS APPLY. `conversations.list` answers `{"ok": false, "error": "..."}` with HTTP 200,
 * so `$response->successful()` means "we reached Slack", never "it worked". And the token here is a bot token
 * that usually never expires, so an `invalid_auth` from this call means the grant is genuinely dead rather than
 * merely stale — but this class only reports; revoking a grant stays the delivery job's terminal path, because
 * a picker that could kill a working connection is a picker nobody should open.
 *
 * SCOPE NARROWING (recorded, not an oversight): `types=public_channel` only. Listing private channels needs
 * `groups:read`, which is not in the granted scopes, and adding it would force every already-connected
 * workspace back through the consent screen.
 */
final class SlackChannelLister implements ListsChannels
{
    private const LIST_URL = 'https://slack.com/api/conversations.list';

    /** Slack's per-page maximum for this method; fewer round trips for the same result. */
    private const PAGE_SIZE = 200;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    public function channels(Connection $connection): ConnectorChannelPage
    {
        try {
            $this->guard->assertPublic(self::LIST_URL);
        } catch (BlockedWebhookUrlException $e) {
            throw ConnectorChannelException::failed($e->reason);
        }

        $pageLimit = max(1, (int) config('connectors.channel_page_limit', 5));
        $channels = [];
        $cursor = '';
        $truncated = false;

        for ($page = 0; $page < $pageLimit; $page++) {
            $body = $this->fetch($connection, $cursor);

            foreach ($this->rows($body) as $row) {
                $channel = $this->channelFrom($row);

                if ($channel !== null) {
                    $channels[] = $channel;
                }
            }

            $cursor = $this->nextCursor($body);

            if ($cursor === '') {
                break;
            }

            // The budget ran out with Slack still offering a cursor — say so rather than implying the
            // channels we never asked for do not exist.
            $truncated = $page === $pageLimit - 1;
        }

        usort($channels, static fn (ConnectorChannel $a, ConnectorChannel $b): int => strcasecmp($a->label, $b->label));

        return new ConnectorChannelPage($channels, $truncated);
    }

    /**
     * One page of the listing. Mirrors {@see SlackConnector::postForm()}'s discipline — no redirects, bounded
     * timeouts, and `ok` read from the body because the HTTP status carries no verdict.
     *
     * @return array<string, mixed>
     */
    private function fetch(Connection $connection, string $cursor): array
    {
        $query = [
            'types' => 'public_channel',
            'exclude_archived' => 'true',
            'limit' => self::PAGE_SIZE,
        ];

        if ($cursor !== '') {
            $query['cursor'] = $cursor;
        }

        try {
            $response = Http::withToken($connection->access_token)
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout((int) config('webhooks.connect_timeout', 5))
                ->timeout((int) config('webhooks.delivery_timeout', 10))
                ->get(self::LIST_URL, $query);
        } catch (ConnectionException) {
            throw ConnectorChannelException::failed('transport_error');
        }

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';

            throw ConnectorChannelException::failed($error);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function rows(array $body): array
    {
        $rows = $body['channels'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function channelFrom(array $row): ?ConnectorChannel
    {
        $id = $row['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $name = is_string($row['name'] ?? null) ? $row['name'] : $id;
        $isMember = ($row['is_member'] ?? false) === true;

        return new ConnectorChannel(
            id: $id,
            label: '#'.$name,
            available: $isMember,
            unavailableReason: $isMember ? null : 'not_a_member',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function nextCursor(array $body): string
    {
        $meta = $body['response_metadata'] ?? null;

        if (! is_array($meta)) {
            return '';
        }

        $cursor = $meta['next_cursor'] ?? null;

        return is_string($cursor) ? $cursor : '';
    }
}
