<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Exceptions\Connectors\ConnectorDestinationException;
use App\Models\Connection;
use App\Support\Webhooks\OutboundUrlGuard;

/**
 * An OPTIONAL capability: a provider whose destinations are DOCUMENTS with a header row the platform can read
 * the shape of (H16b as {@see ProvisionsTabularDestinations}, split out here in H16c).
 *
 * ── WHY THIS WAS SPLIT OFF, WHICH IS A STORY ABOUT ONE GOOGLE CONSTRAINT ─────────────────────────────────
 *
 * H16b wrote reading and creating as ONE capability because Google needs both and needs them together: under
 * `drive.file` the platform can see only files it created, so "inspect an existing document" is barely usable
 * unless "create the document" comes with it. Bundling them looked like cohesion. It was a workaround for a
 * scope limitation wearing the shape of a design.
 *
 * Airtable (H16c) can enumerate a tenant's bases and read any table's fields with `schema.bases:read`, and
 * deliberately does NOT request `schema.bases:write` — the platform never alters a tenant's base structure
 * (ADR-0009 §D8). So it needs exactly the reading half, and an interface that also demanded `create()` would
 * have forced either a scope nobody wanted or a method that throws on every call.
 *
 * Reading is therefore the base capability and provisioning EXTENDS it, which is the direction the dependency
 * genuinely runs: nothing can usefully create a destination it cannot then read the shape of, while plenty of
 * providers can read one they may not create. `GoogleSheetsDirectory` needed no edit for this split.
 *
 * Implementations inherit the adapter rules unchanged: never persist anything, guard every outbound host
 * through {@see OutboundUrlGuard} first, never follow redirects, bound every request with the webhook
 * timeouts, and reduce the wire response to {@see TabularDestination} so no provider field name escapes.
 */
interface InspectsTabularDestinations
{
    /**
     * Read an existing document's tabs and the header row of `$sheetName` (or its first tab when null).
     *
     * The two argument names are Sheets-flavoured and the CONCEPTS are not: `$spreadsheetId` is whatever
     * identifies the document (a Google spreadsheet id, an Airtable base id) and `$sheetName` whichever
     * sub-table inside it is being written to (a Google tab, an Airtable table). {@see TabularDestination}
     * carries the same deliberate naming, for the same reason — renaming the pair would ripple through the
     * stored rule `config`, the wire types and the routes to buy vocabulary.
     *
     * @throws ConnectorDestinationException the provider refused, was unreachable, or cannot see the document
     */
    public function inspect(Connection $connection, string $spreadsheetId, ?string $sheetName = null): TabularDestination;
}
