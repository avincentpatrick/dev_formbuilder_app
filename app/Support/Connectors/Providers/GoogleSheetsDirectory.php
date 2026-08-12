<?php

declare(strict_types=1);

namespace App\Support\Connectors\Providers;

use App\Exceptions\Connectors\ConnectorDestinationException;
use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Models\Connection;
use App\Support\Connectors\ProvisionsTabularDestinations;
use App\Support\Connectors\TabularDestination;
use App\Support\Mapping\ColumnMapping;
use App\Support\Webhooks\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Creates and inspects the spreadsheets a Sheets rule delivers into (H16b) — the setup-time counterpart to
 * {@see GoogleSheetsConnector}, which only ever appends.
 *
 * A SEPARATE class from the adapter for the reason {@see SlackChannelLister}'s docblock gives and this case
 * makes sharper: these run on a page render, under a session, at human latency; the adapter runs in a queue
 * worker. They share Google's wire conventions, not their lifecycle. The adapter is also `final` and already
 * at the size where a fifth concern blurs it.
 *
 * ── `spreadsheets.create` IS AVAILABLE UNDER `drive.file`, AND THAT IS THE WHOLE DESIGN ────────────────────
 * H16a chose `drive.file` over `spreadsheets` to stay in Google's *Recommended, Non-sensitive* tier, and
 * recorded the consequence: the platform cannot enumerate a tenant's spreadsheets, so there is no picker.
 * `config/connectors.php` states the alternative in as many words — "the destination is created by us or
 * named by id". The second half alone does not work: an id a tenant pastes is unreachable unless the app
 * already holds a per-file grant, so for most tenants a paste field can only ever answer 404. Creating the
 * file is what makes `drive.file` sufficient rather than merely narrow, because the scope covers, verbatim,
 * "specific Google Drive files you use with this app" — a file this app created is one of them, permanently.
 *
 * ── THE HEADER ROW IS WRITTEN BY US, IN THE SAME CALL ──────────────────────────────────────────────────────
 * `spreadsheets.create` accepts the initial cell data inline, so the document arrives complete rather than
 * created-then-populated. That matters beyond tidiness: a two-call create has a window where the spreadsheet
 * exists with no header row, and if the second call fails the tenant owns a blank file the rule editor would
 * then read as "a sheet with no columns". One call, one outcome.
 *
 * ── ⚠️ `valueInputOption` HAS NO EQUIVALENT HERE, SO THE HEADERS MUST BE SAFE BY CONSTRUCTION ──────────────
 * The adapter disarms formula injection structurally with `valueInputOption=RAW` on every append. The create
 * endpoint takes `userEnteredValue` and has no such switch — `stringValue` IS the literal-text field, and the
 * value is never parsed as a formula, which is why {@see cellFor()} sets that and nothing else. Reaching for
 * `formulaValue`, or letting a caller choose, would reopen `security-threat-model.md` §5's row on the one
 * surface where Google evaluates the result on its own servers with no human opening the file.
 */
final class GoogleSheetsDirectory implements ProvisionsTabularDestinations
{
    private const SHEETS_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * Google's own limit is 200 characters for a tab name and far more for a document title; both are cut well
     * short of it because these are composed from a tenant-authored form title and a long one helps nobody.
     */
    private const MAX_TITLE = 120;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @param  list<string>  $headers
     */
    public function create(Connection $connection, string $title, array $headers): TabularDestination
    {
        $documentTitle = $this->clamp($title, self::MAX_TITLE, 'Meridian responses');

        // The tab is named for the app rather than for the form. Google's own default is `Sheet1`, which tells
        // a tenant opening the file nothing about where the rows came from; the FORM's title is already the
        // document title, so repeating it inside would be noise.
        $tabTitle = 'Responses';

        $body = $this->send(fn (): Response => $this->request($connection)->asJson()->post(self::SHEETS_BASE, [
            'properties' => ['title' => $documentTitle],
            'sheets' => [[
                'properties' => ['title' => $tabTitle],
                'data' => [[
                    'startRow' => 0,
                    'startColumn' => 0,
                    'rowData' => [['values' => array_map($this->cellFor(...), $headers)]],
                ]],
            ]],
        ]));

        $spreadsheetId = is_string($body['spreadsheetId'] ?? null) ? $body['spreadsheetId'] : '';

        if ($spreadsheetId === '') {
            // A 2xx with no id is not something Google does, but reading it as success would store an empty
            // destination that fails at first delivery with no trace of where it came from.
            throw ConnectorDestinationException::failed('malformed_response');
        }

        return new TabularDestination(
            spreadsheetId: $spreadsheetId,
            title: $this->titleOf($body, $documentTitle),
            url: $this->urlOf($body, $spreadsheetId),
            tabs: $this->tabsOf($body) ?: [$tabTitle],
            sheetName: $tabTitle,
            // Echo back what WE sent rather than re-reading it. A second round trip to learn a value we just
            // wrote would be the create/populate race this method exists to avoid, one call later.
            headerRow: $headers,
        );
    }

    public function inspect(Connection $connection, string $spreadsheetId, ?string $sheetName = null): TabularDestination
    {
        // `fields` is not an optimisation — an unfiltered GET on a large spreadsheet returns every sheet's
        // properties including grid data descriptors, and this call runs while a human waits.
        $body = $this->send(fn (): Response => $this->request($connection)->get(
            self::SHEETS_BASE.'/'.rawurlencode($spreadsheetId),
            ['fields' => 'spreadsheetId,spreadsheetUrl,properties.title,sheets.properties.title'],
        ));

        $tabs = $this->tabsOf($body);

        if ($tabs === []) {
            throw ConnectorDestinationException::failed('no_tabs');
        }

        // An explicitly named tab that is gone is a different fact from "no tab was named", and the tenant can
        // act on it (re-pick, or rename it back). Falling through to the first tab would silently retarget a
        // rule at the wrong data, which is the failure mode `ocr-pipeline-design.md` §84 names for mappings.
        if ($sheetName !== null && ! in_array($sheetName, $tabs, true)) {
            throw ConnectorDestinationException::failed('unknown_tab');
        }

        $tab = $sheetName ?? $tabs[0];

        return new TabularDestination(
            spreadsheetId: is_string($body['spreadsheetId'] ?? null) ? $body['spreadsheetId'] : $spreadsheetId,
            title: $this->titleOf($body, $spreadsheetId),
            url: $this->urlOf($body, $spreadsheetId),
            tabs: $tabs,
            sheetName: $tab,
            headerRow: $this->headerRow($connection, $spreadsheetId, $tab),
        );
    }

    /**
     * Row 1 of `$tab`, verbatim and positional.
     *
     * ⚠️ TRAILING BLANKS ARE THE POINT OF `padded=false` NOT EXISTING HERE. Google truncates a row's trailing
     * empty cells, so `['A', '', 'C', '']` comes back as `['A', '', 'C']` — the interior blank survives and the
     * trailing one does not. That asymmetry is Google's, and it is safe to inherit: an interior blank is an
     * addressable column that would shift everything after it if dropped, while a trailing blank addresses
     * nothing. {@see ColumnMapping} already draws the same distinction.
     *
     * @return list<string>
     */
    private function headerRow(Connection $connection, string $spreadsheetId, string $tab): array
    {
        $range = "'".str_replace("'", "''", $tab)."'!1:1";

        $body = $this->send(fn (): Response => $this->request($connection)->get(
            self::SHEETS_BASE.'/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range),
            ['majorDimension' => 'ROWS'],
        ));

        $values = $body['values'] ?? null;
        $first = is_array($values) && is_array($values[0] ?? null) ? $values[0] : [];

        return array_values(array_map(
            static fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '',
            $first,
        ));
    }

    /**
     * A literal text cell. `stringValue` is Google's own field for "this is text": a leading `=` in it is
     * stored as text and never evaluated, which is the create-time equivalent of the adapter's RAW.
     *
     * @return array{userEnteredValue: array{stringValue: string}}
     */
    private function cellFor(string $header): array
    {
        return ['userEnteredValue' => ['stringValue' => mb_substr($header, 0, 255)]];
    }

    /**
     * Run one Sheets call and return its decoded body, or throw with a code the caller can map.
     *
     * The classification is deliberately NOT {@see GoogleSheetsConnector::classifyFailure()}'s. That method
     * answers "should the ledger retry this delivery?" and is built around the fact that Google's 401 is
     * ambiguous between an expired token and a revoked grant, so it reports 401 as retryable. Here there is no
     * ledger and no retry: a human is waiting, and every outcome is a sentence they read once.
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $this->guard->assertPublic(self::SHEETS_BASE);
        } catch (BlockedWebhookUrlException $e) {
            throw ConnectorDestinationException::failed($e->reason);
        }

        try {
            $response = $call();
        } catch (ConnectionException) {
            throw ConnectorDestinationException::failed('transport_error');
        }

        if (! $response->successful()) {
            throw ConnectorDestinationException::failed($this->codeFor($response));
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * One unsuccessful response to a code the directory can turn into copy.
     *
     * ⚠️ 403 IS TWO DIFFERENT FACTS AND ONLY THE BODY SEPARATES THEM — the same trap H16a recorded on the
     * delivery path. A quota/rate refusal and "this app has no grant on that file" both arrive as 403, and
     * telling a tenant to re-pick their spreadsheet because Google is rate-limiting us would be worse than
     * saying nothing.
     */
    private function codeFor(Response $response): string
    {
        $status = $response->status();
        $reason = is_string($response->json('error.status')) ? (string) $response->json('error.status') : '';

        if ($status === 401) {
            return 'unauthenticated';
        }

        if ($status === 429 || $reason === 'RESOURCE_EXHAUSTED') {
            return 'rate_limited';
        }

        if ($status === 403) {
            return $reason === 'PERMISSION_DENIED' ? 'not_shared' : 'rate_limited';
        }

        if ($status === 404) {
            return 'not_found';
        }

        // 400 INVALID_ARGUMENT on a GET here is an unparseable id, which is a typo rather than an outage — and
        // distinguishing it from a real 5xx is what lets the tenant be told to check what they pasted.
        if ($status === 400) {
            return 'invalid_destination';
        }

        return $status >= 500 ? 'provider_unavailable' : 'unknown_error';
    }

    private function request(Connection $connection): PendingRequest
    {
        return Http::withToken($connection->access_token)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) config('webhooks.connect_timeout', 5))
            ->timeout((int) config('webhooks.delivery_timeout', 10));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function tabsOf(array $body): array
    {
        $sheets = $body['sheets'] ?? null;

        if (! is_array($sheets)) {
            return [];
        }

        $tabs = [];

        foreach ($sheets as $sheet) {
            $title = is_array($sheet) && is_array($sheet['properties'] ?? null)
                ? ($sheet['properties']['title'] ?? null)
                : null;

            if (is_string($title) && $title !== '') {
                $tabs[] = $title;
            }
        }

        return $tabs;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function titleOf(array $body, string $fallback): string
    {
        $properties = $body['properties'] ?? null;
        $title = is_array($properties) ? ($properties['title'] ?? null) : null;

        return is_string($title) && $title !== '' ? $title : $fallback;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function urlOf(array $body, string $spreadsheetId): string
    {
        $url = $body['spreadsheetUrl'] ?? null;

        // Composed rather than left empty when Google omits it: the editor renders this as the tenant's "open
        // your sheet" link, and the canonical form is stable and documented.
        return is_string($url) && $url !== ''
            ? $url
            : 'https://docs.google.com/spreadsheets/d/'.rawurlencode($spreadsheetId).'/edit';
    }

    private function clamp(string $value, int $max, string $fallback): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? $fallback : mb_substr($trimmed, 0, $max);
    }
}
