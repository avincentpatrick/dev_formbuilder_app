<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Exceptions\Connectors\ConnectorChannelException;
use App\Models\Connection;
use App\Support\Webhooks\OutboundUrlGuard;

/**
 * An OPTIONAL capability: a provider that can enumerate the destinations a tenant may deliver into, so the UI
 * can offer a picker instead of asking a human to paste an opaque id (H15b).
 *
 * Deliberately NOT a fifth method on {@see ConnectorProvider}, whose docblock pins it at "four responsibilities,
 * deliberately no more". Listing destinations is not universal and not even the same concept across providers —
 * H16's Google Sheets adapter enumerates spreadsheets and Airtable enumerates bases — so folding it into the
 * core interface would force every future adapter to implement a Slack-shaped idea or throw. A provider that
 * offers no picker simply has no `channel_lister` entry in `config/connectors.php`, and
 * {@see ConnectorRegistry::channelListerFor()} returns null.
 *
 * Implementations inherit {@see ConnectorProvider}'s rules unchanged: never persist anything, guard every
 * outbound host through {@see OutboundUrlGuard} first, never follow redirects, bound every request with the
 * webhook timeouts, and reduce the wire response to {@see ConnectorChannel} so no provider field name escapes.
 */
interface ListsChannels
{
    /**
     * Every destination this grant may deliver into, in display order, plus whether the read was truncated.
     *
     * @throws ConnectorChannelException the provider refused or was unreachable
     */
    public function channels(Connection $connection): ConnectorChannelPage;
}
