<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Exceptions\Connectors\ConnectorChannelException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Services\Connectors\ConnectorChannelDirectory;
use App\Support\Connectors\ConnectorChannel;
use App\Support\Connectors\ConnectorChannelPage;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Connectors\ListsChannels;
use App\Support\Connectors\ProvisionsTabularDestinations;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads a tenant's Airtable bases through `GET /v0/meta/bases` so the rule editor can offer real destinations
 * (H16c) — the `schema.bases:read` half of ADR-0009 §D8's scope justification.
 *
 * ── THIS IS THE CAPABILITY GOOGLE COULD NOT HAVE, AND IT CHANGES THE SETUP FLOW ──────────────────────────
 *
 * {@see ProvisionsTabularDestinations}'s docblock explains at length why Sheets has
 * no lister: `drive.file` shows the platform only files it created, so there is nothing to enumerate, and
 * H16b's answer was to CREATE the spreadsheet so the destination is reachable by construction. Airtable's
 * read scope enumerates properly, so H16c picks a base from a list instead — and consequently never needs
 * `schema.bases:write`. A capability the framework has had since H15b, unused by the provider it was written
 * for, turning out to be exactly right for the third one.
 *
 * ── A BASE IS LISTED EVEN WHEN WE CANNOT WRITE TO IT ─────────────────────────────────────────────────────
 *
 * `permissionLevel` is the tenant's own access to the base, and `read`/`comment` cannot create records. Those
 * bases are still LISTED and marked unavailable, following {@see SlackChannelLister}'s
 * precedent for a channel the app has not been invited to: hiding them answers "why isn't my base here?" with
 * silence, when the honest answer is "you have read-only access to it" and is actionable in Airtable.
 *
 * A SEPARATE class from {@see AirtableConnector} for that adapter's own reason: the four
 * {@see ConnectorProvider} responsibilities are its whole job, this runs on a page
 * render rather than in a queue worker, and the two share Airtable's wire conventions rather than a lifecycle.
 */
final class AirtableBaseLister implements ListsChannels
{
    private const LIST_URL = 'https://api.airtable.com/v0/meta/bases';

    /** The permission levels that can actually create a record. `read` and `comment` cannot; `none` is not returned. */
    private const WRITABLE_LEVELS = ['edit', 'create'];

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    public function channels(Connection $connection): ConnectorChannelPage
    {
        try {
            $this->guard->assertPublic(self::LIST_URL);
        } catch (BlockedWebhookUrlException $e) {
            throw ConnectorChannelException::failed($e->reason);
        }

        $pageLimit = max(1, (int) config('connectors.channel_page_limit', 5));
        $bases = [];
        $offset = '';
        $truncated = false;

        for ($page = 0; $page < $pageLimit; $page++) {
            $body = $this->fetch($connection, $offset);

            foreach ($this->rows($body) as $row) {
                $base = self::baseFrom($row);

                if ($base !== null) {
                    $bases[] = $base;
                }
            }

            // Airtable paginates with an opaque `offset` echoed back rather than Slack's `next_cursor`; its
            // ABSENCE, not an empty string, is what means "that was the last page".
            $offset = is_string($body['offset'] ?? null) ? $body['offset'] : '';

            if ($offset === '') {
                break;
            }

            // The budget ran out with Airtable still offering an offset — say so rather than implying the
            // bases we never asked for do not exist.
            $truncated = $page === $pageLimit - 1;
        }

        usort($bases, static fn (ConnectorChannel $a, ConnectorChannel $b): int => strcasecmp($a->label, $b->label));

        return new ConnectorChannelPage($bases, $truncated);
    }

    /**
     * One page of the listing. Mirrors {@see AirtableConnector}'s discipline — no redirects, bounded timeouts.
     *
     * Unlike Slack, Airtable reports failure with a real HTTP status, so the status IS the verdict here and
     * there is no `ok`-at-200 trap to read around.
     *
     * @return array<string, mixed>
     */
    private function fetch(Connection $connection, string $offset): array
    {
        $query = $offset === '' ? [] : ['offset' => $offset];

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

        if (! $response->successful() || ! is_array($body)) {
            throw ConnectorChannelException::failed(self::errorCodeFor($response->status(), $body));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function rows(array $body): array
    {
        $rows = $body['bases'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function baseFrom(array $row): ?ConnectorChannel
    {
        $id = $row['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $level = is_string($row['permissionLevel'] ?? null) ? $row['permissionLevel'] : '';
        $writable = in_array($level, self::WRITABLE_LEVELS, true);

        return new ConnectorChannel(
            id: $id,
            label: is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : $id,
            available: $writable,
            unavailableReason: $writable ? null : 'read_only',
        );
    }

    /**
     * An HTTP status to one of OUR error codes. Airtable's own `error.type` strings are not echoed anywhere a
     * tenant can read them — the {@see ConnectorChannelDirectory} rule that
     * third-party text never reaches our page unreviewed.
     */
    private static function errorCodeFor(int $status, mixed $body): string
    {
        return match (true) {
            $status === 401 => 'invalid_auth',
            // Airtable answers 403 when the token is valid but lacks `schema.bases:read` — a re-consent
            // condition rather than a dead grant, and distinct from 401 for exactly that reason.
            $status === 403 => 'missing_scope',
            $status === 429 => 'ratelimited',
            $status >= 500 => 'provider_unavailable',
            default => is_array($body) ? 'unknown_error' : 'malformed_response',
        };
    }
}
