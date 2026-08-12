<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorDestinationException;
use App\Models\Connection;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\ProvisionsTabularDestinations;
use App\Support\Connectors\TabularDestination;

/**
 * The read/provision model behind the Sheets rule editor's JSON sidecars (H16b): resolve the provider's
 * optional {@see ProvisionsTabularDestinations} capability, ask it, and reduce every outcome — including every
 * failure — to a shape the editor can render.
 *
 * NOTHING HERE THROWS, the {@see ConnectorChannelDirectory} contract, and for a sharper reason than that class
 * had. `bootstrap/app.php` keys its JSON error branch on the `api/v1/*` PATH, so a domain exception raised on
 * a tenant-web route renders as `back()->with('toast')` — a 302. A `fetch` client follows it, sees `ok`, and
 * chokes parsing HTML; `integrationsClient.ts` says so in its own docblock, which is why it was READ-ONLY
 * until now. Answering 200-with-`error` for every outcome is what lets a WRITE (`create`) live behind the
 * same client without that hazard, and it keeps the tenant on the half-filled form they were writing.
 *
 * ⚠️ THE ERROR STRINGS ARE THE FEATURE, NOT PADDING. The whole reason this increment exists is that under
 * `drive.file` an unreachable destination used to be discovered at DELIVERY time, where it surfaces as a
 * paused rule and reads like an outage. Every arm of {@see message()} is a sentence that turns a setup
 * mistake back into a setup mistake, at the moment the tenant can still fix it.
 */
final class SheetDestinationDirectory
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @param  list<string>  $headers
     * @return array{destination: ?array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>}, error: ?string}
     */
    public function create(Connection $connection, string $title, array $headers): array
    {
        return $this->attempt(
            $connection,
            fn (ProvisionsTabularDestinations $directory): TabularDestination => $directory->create($connection, $title, $headers),
        );
    }

    /**
     * @return array{destination: ?array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>}, error: ?string}
     */
    public function inspect(Connection $connection, string $reference, ?string $sheetName = null): array
    {
        $spreadsheetId = self::spreadsheetIdFrom($reference);

        if ($spreadsheetId === null) {
            return $this->failure('That doesn’t look like a Google Sheets link or id. Paste the whole address from your browser.');
        }

        return $this->attempt(
            $connection,
            fn (ProvisionsTabularDestinations $directory): TabularDestination => $directory->inspect($connection, $spreadsheetId, $sheetName),
        );
    }

    /**
     * The spreadsheet id inside whatever the tenant pasted, or null if there isn't one.
     *
     * ⚠️ A URL IS THE NORMAL INPUT AND A BARE ID IS THE RARE ONE — which is the opposite of what a field
     * labelled "spreadsheet id" implies. Nobody reads an id out of a Google Sheets URL by hand; they copy the
     * address bar. Accepting only the id would reject the thing every tenant will actually paste, and the
     * server would then answer 404 for a perfectly valid sheet, which is the exact confusion this increment
     * exists to remove.
     *
     * Google's ids are 20+ characters of `[A-Za-z0-9_-]`, so the pattern is anchored on the `/d/<id>/` path
     * segment rather than on "a long token somewhere in the string": a URL also contains `docs`, `google` and
     * a `gid` fragment, and a greedy match would happily return one of those.
     */
    public static function spreadsheetIdFrom(string $reference): ?string
    {
        $trimmed = trim($reference);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]{20,})#', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        // A bare id. The length floor is what stops a stray word being treated as one, and it is deliberately
        // the same 20 the URL arm uses so the two cannot disagree about what an id looks like.
        return preg_match('#^[A-Za-z0-9_-]{20,}$#', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * @param  callable(ProvisionsTabularDestinations): TabularDestination  $operation
     * @return array{destination: ?array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>}, error: ?string}
     */
    private function attempt(Connection $connection, callable $operation): array
    {
        if ($connection->status !== ConnectionStatus::Active) {
            return $this->failure('This account needs to be reconnected before we can reach your spreadsheets.');
        }

        $directory = $this->registry->tabularDirectoryFor($connection->provider);

        if ($directory === null) {
            // Not a misconfiguration — a provider whose destinations are not documents (Slack) simply has no
            // entry. The registry's null-not-throw contract covers exactly this, and the CONNECTION is fine.
            return $this->failure('This integration doesn’t deliver into spreadsheets.');
        }

        try {
            return ['destination' => $operation($directory)->toArray(), 'error' => null];
        } catch (ConnectorDestinationException $e) {
            return $this->failure($this->message($connection->provider, $e->errorCode));
        }
    }

    /**
     * A provider error code to copy the tenant can act on. The default is deliberately generic: the code can
     * be any string the provider chooses, so echoing it would put unreviewed third-party text on our page —
     * the {@see ConnectorChannelDirectory} rule, unchanged.
     */
    private function message(ConnectorProviderKey $provider, string $errorCode): string
    {
        $label = $provider->label();

        return match ($errorCode) {
            'unauthenticated' => "{$label} rejected our credentials. Reconnect this account, then try again.",
            // The `drive.file` case, and the one worth spelling out: the tenant is looking at a spreadsheet
            // they own and we genuinely cannot see it, which reads as a bug unless the reason is given.
            'not_shared', 'not_found' => 'We can’t open that spreadsheet. With per-file access we can only reach sheets we created for you — create one here instead, and we’ll set up its columns.',
            'unknown_tab' => 'That tab isn’t in the spreadsheet any more. Pick another one.',
            'no_tabs' => 'That spreadsheet has no tabs to write into.',
            'invalid_destination' => 'That doesn’t look like a Google Sheets link. Paste the whole address from your browser.',
            'rate_limited' => "{$label} is rate-limiting us. Wait a moment and try again.",
            'transport_error', 'provider_unavailable' => "We couldn’t reach {$label}. Check your connection and try again.",
            default => 'We couldn’t set up that spreadsheet. Try again, or create a new one here.',
        };
    }

    /**
     * @return array{destination: null, error: string}
     */
    private function failure(string $message): array
    {
        return ['destination' => null, 'error' => $message];
    }
}
