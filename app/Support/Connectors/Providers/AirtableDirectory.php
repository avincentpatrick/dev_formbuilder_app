<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Exceptions\Connectors\ConnectorChannelException;
use App\Exceptions\Connectors\ConnectorDestinationException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Support\Connectors\InspectsTabularDestinations;
use App\Support\Connectors\ProvisionsTabularDestinations;
use App\Support\Connectors\TabularDestination;
use App\Support\Mapping\ColumnMapping;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads the shape of an Airtable table so a rule's {@see ColumnMapping} can be authored against it (H16c).
 *
 * ── IT IMPLEMENTS THE READING HALF ONLY, AND THAT IS THE POINT OF THE H16c SPLIT ─────────────────────────
 *
 * {@see ProvisionsTabularDestinations} is not implemented here and the omission is load-bearing: creating a
 * table needs `schema.bases:write`, which ADR-0009 §D8 refused because Airtable can ENUMERATE — so a tenant
 * picks a base and a table that already exist, and the platform never alters their base structure. There is
 * no method here that throws "unsupported"; the capability simply is not claimed, and
 * `ConnectorRegistry::provisioningDirectoryFor()` returns null for Airtable as a result.
 *
 * ── THE VOCABULARY MAP, WHICH IS THE WHOLE ADAPTATION ────────────────────────────────────────────────────
 *
 *   Airtable base   → `spreadsheetId` / `title`      a document
 *   Airtable tables → `tabs`                         its sub-tables, in the order Airtable returns them
 *   chosen table    → `sheetName` (+ `sheetId`)      one of them
 *   its FIELD NAMES → `headerRow`                    what a mapping binds to
 *
 * {@see TabularDestination}'s docblock committed to exactly this before Airtable existed. The field names are
 * verbatim, in Airtable's order, because a mapping binds positionally and the delivery path writes by field
 * NAME — a normalised or reordered copy would bind answers to the wrong fields.
 *
 * ── WHY `sheetId` EXISTS NOW ─────────────────────────────────────────────────────────────────────────────
 *
 * Airtable accepts either a table id or a table name in a write URL, and a tenant renaming a table is an
 * ordinary Tuesday. Keying delivery on the NAME would turn a rename into a 404 that reads as a broken
 * integration; keying it on the id makes a rename invisible, which leaves genuine FIELD changes as the only
 * thing drift detection ever reports. Same rule H16b applied to `spreadsheet_title`: the id is the identity,
 * the name is a caption.
 */
final class AirtableDirectory implements InspectsTabularDestinations
{
    private const META_BASE = 'https://api.airtable.com/v0/meta/bases';

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly AirtableBaseLister $bases,
    ) {}

    public function inspect(Connection $connection, string $spreadsheetId, ?string $sheetName = null): TabularDestination
    {
        $tables = $this->tablesOf($this->send(fn (): Response => $this->request($connection)->get(
            self::META_BASE.'/'.rawurlencode($spreadsheetId).'/tables',
        )));

        if ($tables === []) {
            throw ConnectorDestinationException::failed('no_tabs');
        }

        $names = array_map(static fn (array $table): string => $table['name'], $tables);

        // An explicitly named table that is gone is a different fact from "no table was named", and the tenant
        // can act on it. Falling through to the first table would silently retarget a rule at the wrong data.
        if ($sheetName !== null && ! in_array($sheetName, $names, true)) {
            throw ConnectorDestinationException::failed('unknown_tab');
        }

        $chosen = $sheetName === null
            ? $tables[0]
            : $tables[array_search($sheetName, $names, true)];

        return new TabularDestination(
            spreadsheetId: $spreadsheetId,
            title: $this->baseTitleFor($connection, $spreadsheetId),
            // The table-scoped deep link. Airtable resolves a base-only URL to whichever table the viewer
            // last opened, which is not necessarily the one this rule writes into.
            url: 'https://airtable.com/'.rawurlencode($spreadsheetId).'/'.rawurlencode($chosen['id']),
            tabs: $names,
            sheetName: $chosen['name'],
            headerRow: $chosen['fields'],
            sheetId: $chosen['id'],
        );
    }

    /**
     * The base's display name, or its id when it cannot be resolved.
     *
     * ⚠️ BEST-EFFORT ON PURPOSE, AND IT COSTS A SECOND CALL. Airtable has no "get one base" endpoint — a name
     * is only obtainable from the paginated list — so this delegates to the lister rather than duplicating its
     * pagination and error handling. A failure here is SWALLOWED: the caption is a nicety and refusing to
     * inspect a table the tenant can see because we could not fetch a display name would be a worse product
     * than showing the id. The tenant picking from the list also has the name on screen already.
     */
    private function baseTitleFor(Connection $connection, string $baseId): string
    {
        try {
            foreach ($this->bases->channels($connection)->channels as $base) {
                if ($base->id === $baseId) {
                    return $base->label;
                }
            }
        } catch (ConnectorChannelException) {
            // Fall through to the id.
        }

        return $baseId;
    }

    /**
     * Airtable's table list, reduced to the three things this class needs and nothing else.
     *
     * A table with no `fields` array is kept with an EMPTY header row rather than dropped: it is a real table
     * the tenant can see, and hiding it would read as the platform losing their data. It cannot be mapped, and
     * `ColumnMapping` refusing an empty column list is what says so.
     *
     * @param  array<string, mixed>  $body
     * @return list<array{id: string, name: string, fields: list<string>}>
     */
    private function tablesOf(array $body): array
    {
        $rows = $body['tables'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $tables = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];

            $tables[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'fields' => array_values(array_filter(array_map(
                    static fn (mixed $field): string => is_array($field) && is_string($field['name'] ?? null) ? $field['name'] : '',
                    $fields,
                ), static fn (string $name): bool => $name !== '')),
            ];
        }

        return $tables;
    }

    private function request(Connection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('webhooks.connect_timeout', 5))
            ->timeout((int) config('webhooks.delivery_timeout', 10));
    }

    /**
     * Run one meta call and return its decoded body, or throw with a code the caller can map.
     *
     * The classification is deliberately NOT the adapter's, the same split {@see GoogleSheetsDirectory}
     * records: that one answers "should the ledger retry this delivery?" and reports 401 as retryable because
     * an expired token and a revoked grant are indistinguishable there. Here there is no ledger and no retry —
     * a human is waiting, and every outcome is a sentence they read once.
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $this->guard->assertPublic(self::META_BASE);
        } catch (BlockedWebhookUrlException $e) {
            throw ConnectorDestinationException::failed($e->reason);
        }

        try {
            $response = $call();
        } catch (ConnectionException) {
            throw ConnectorDestinationException::failed('transport_error');
        }

        if (! $response->successful()) {
            throw ConnectorDestinationException::failed(self::codeFor($response));
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * One unsuccessful response to a code the directory can turn into copy.
     *
     * Airtable's 403 is NOT the two-facts-one-status trap Google's is: rate limiting is always 429 here, so a
     * 403 means the token genuinely lacks `schema.bases:read` for this base — a re-consent condition, and one
     * worth separating from 401 because the remedy differs (reconnect vs. the base was shared away).
     */
    private static function codeFor(Response $response): string
    {
        $type = is_string($response->json('error.type')) ? (string) $response->json('error.type') : '';

        return match (true) {
            $response->status() === 401 => 'unauthenticated',
            $response->status() === 403 => 'not_shared',
            // Airtable answers 404 for a base id that does not exist AND for one this grant cannot see, which
            // is the correct disclosure behaviour on their side and means the two are one outcome for us.
            $response->status() === 404, $type === 'TABLE_NOT_FOUND' => 'not_found',
            $response->status() === 422, $type === 'INVALID_REQUEST_UNKNOWN' => 'invalid_destination',
            $response->status() === 429 => 'rate_limited',
            $response->status() >= 500 => 'provider_unavailable',
            default => 'unknown_error',
        };
    }
}
