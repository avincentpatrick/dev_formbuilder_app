<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Exceptions\Connectors\ConnectorDestinationException;
use App\Models\Connection;
use App\Support\Mapping\ColumnFingerprint;
use App\Support\Webhooks\OutboundUrlGuard;

/**
 * An OPTIONAL capability: a provider whose destinations are DOCUMENTS the tenant owns, which the platform can
 * create on their behalf and read the shape of (H16b).
 *
 * ── WHY THIS EXISTS BESIDE {@see ListsChannels} RATHER THAN INSTEAD OF IT ──────────────────────────────────
 * They answer different questions and only one of them is askable of Google. `ListsChannels` means "show me
 * everywhere I could deliver", which needs the provider to enumerate the tenant's estate. Under `drive.file` —
 * Google's own *Recommended, Non-sensitive* scope, chosen in H16a precisely to stay out of the sensitive-scope
 * verification tier — the platform can see only files it created or the tenant explicitly handed it. There is
 * nothing to enumerate, which is why `config/connectors.php` registers no `channel_lister` for Sheets and
 * {@see ConnectorChannelDirectory} answers "enter the destination id instead".
 *
 * That answer was honest and unusable: with per-file access, an id a tenant pastes is unreachable unless the
 * app already had it, so the only outcome the field could produce for most tenants was a 404 — deferred to
 * DELIVERY time, where {@see ConnectorDeliveryResult}'s `blocked` arm reports it as the rule being broken
 * rather than as the setup never having been possible. {@see create()} is what closes that: `drive.file`
 * covers a file the app CREATES, so the platform makes the spreadsheet, writes the header row it will then
 * append under, and the destination is reachable by construction.
 *
 * ── NOT A FIFTH METHOD ON {@see ConnectorProvider} ─────────────────────────────────────────────────────────
 * Same argument its docblock makes and `ListsChannels` already follows: the core interface is pinned at four
 * responsibilities, and provisioning a destination is neither universal nor the same concept across providers.
 * A provider without this capability has no `tabular_directory` entry and
 * {@see ConnectorRegistry::tabularDirectoryFor()} returns null.
 *
 * ── H16c SPLIT THIS IN TWO, AND LEFT THIS HALF WHERE IT WAS ──────────────────────────────────────────────
 * Reading now lives in {@see InspectsTabularDestinations}, which this extends. The reason is in that file:
 * bundling reading with creating looked like cohesion but was a workaround for `drive.file` in the shape of a
 * design, and Airtable — which can read a tenant's tables and deliberately may not alter them — needs exactly
 * the half that was underneath. Nothing about Sheets changed; `GoogleSheetsDirectory` still implements this
 * interface and required no edit.
 *
 * Implementations inherit the adapter rules unchanged: never persist anything, guard every outbound host
 * through {@see OutboundUrlGuard} first, never follow redirects, bound every request with the webhook
 * timeouts, and reduce the wire response to {@see TabularDestination} so no provider field name escapes.
 */
interface ProvisionsTabularDestinations extends InspectsTabularDestinations
{
    /**
     * Create a document this grant owns, with `$headers` written into row 1 of its first tab.
     *
     * The header row is written by US, deliberately: it makes the stored
     * {@see ColumnFingerprint} describe a row the platform authored rather than one it
     * merely observed, so the first drift a tenant ever sees is a real edit of theirs.
     *
     * @param  list<string>  $headers
     *
     * @throws ConnectorDestinationException the provider refused or was unreachable
     */
    public function create(Connection $connection, string $title, array $headers): TabularDestination;
}
