<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\ConnectionStatus;
use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\ConnectorDestinationException;
use App\Models\Connection;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\TabularDestination;

/**
 * The read/provision model behind the tabular rule editor's JSON sidecars (H16b as `SheetDestinationDirectory`,
 * renamed and generalised in H16c): resolve the provider's optional destination capability, ask it, and reduce
 * every outcome — including every failure — to a shape the editor can render.
 *
 * ── WHY IT IS NO LONGER CALLED `SheetDestinationDirectory` ───────────────────────────────────────────────
 * H16b named it for the only provider it served. Airtable now goes through the same class and the same two
 * routes, so the old name would have had an Airtable rule editor calling `/sheets?reference=appXXXX` — the
 * kind of thing that reads as a bug to whoever finds it next. The rename is mechanical and touches no
 * behaviour; the routes moved with it (`/destinations`), which is safe because both are internal sidecars
 * consumed only by this app's own client.
 *
 * NOTHING HERE THROWS, the {@see ConnectorChannelDirectory} contract, and for a sharper reason than that class
 * had. `bootstrap/app.php` keys its JSON error branch on the `api/v1/*` PATH, so a domain exception raised on
 * a tenant-web route renders as `back()->with('toast')` — a 302. A `fetch` client follows it, sees `ok`, and
 * chokes parsing HTML; `integrationsClient.ts` says so in its own docblock, which is why it was READ-ONLY
 * until H16b. Answering 200-with-`error` for every outcome is what lets a WRITE (`create`) live behind the
 * same client without that hazard, and it keeps the tenant on the half-filled form they were writing.
 *
 * ⚠️ THE ERROR STRINGS ARE THE FEATURE, NOT PADDING. The whole reason this seam exists is that an unreachable
 * destination used to be discovered at DELIVERY time, where it surfaces as a paused rule and reads like an
 * outage. Every arm of {@see message()} is a sentence that turns a setup mistake back into a setup mistake, at
 * the moment the tenant can still fix it.
 */
final class TabularDestinationDirectory
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * @param  list<string>  $headers
     * @return array{destination: ?array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>, sheet_id: ?string}, error: ?string}
     */
    public function create(Connection $connection, string $title, array $headers): array
    {
        if ($connection->status !== ConnectionStatus::Active) {
            return $this->failure($this->reconnectMessage($connection->provider));
        }

        $directory = $this->registry->provisioningDirectoryFor($connection->provider);

        if ($directory === null) {
            // ⚠️ A REACHABLE GUARD, NOT A DEAD ONE (H16c). Airtable's directory reads and deliberately cannot
            // create — `schema.bases:write` was refused in ADR-0009 §D8 — and its editor therefore never
            // offers a create control. But the ROUTE still exists for every provider, so a hand-made POST
            // lands here, and the honest answer is a sentence rather than a 500 from a missing capability.
            return $this->failure($this->cannotCreateMessage($connection->provider));
        }

        return $this->run($connection, fn (): TabularDestination => $directory->create($connection, $title, $headers));
    }

    /**
     * @return array{destination: ?array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>, sheet_id: ?string}, error: ?string}
     */
    public function inspect(Connection $connection, string $reference, ?string $sheetName = null): array
    {
        if ($connection->status !== ConnectionStatus::Active) {
            return $this->failure($this->reconnectMessage($connection->provider));
        }

        // ⚠️ CAPABILITY FIRST, THEN THE INPUT — the order matters and H16c got it backwards once. H16b parsed
        // the reference first, which was harmless while the parser was Google's regardless of provider. Now it
        // is provider-keyed, so a Slack connection reaching this route fails the PARSE (Slack has no document
        // id shape at all) and would be told its link looks wrong — when the real answer is that this
        // integration has no such destination. Ask "can this provider do this?" before "is this input valid?".
        $directory = $this->registry->tabularDirectoryFor($connection->provider);

        if ($directory === null) {
            // Not a misconfiguration — a provider whose destinations are not documents (Slack) simply has no
            // entry. The registry's null-not-throw contract covers exactly this, and the CONNECTION is fine.
            // Static rather than provider-worded: this describes what the ROUTE is for, and the provider it
            // is describing has no tabular vocabulary of its own to borrow.
            return $this->failure('This integration doesn’t deliver into a spreadsheet or a table.');
        }

        $documentId = self::documentIdFrom($connection->provider, $reference);

        if ($documentId === null) {
            return $this->failure($this->message($connection->provider, 'invalid_destination'));
        }

        return $this->run($connection, fn (): TabularDestination => $directory->inspect($connection, $documentId, $sheetName));
    }

    /**
     * The document id inside whatever the tenant supplied, or null if there isn't one.
     *
     * `default`-less on the enum, the forcing device the rest of this surface uses: a fourth provider with a
     * tabular destination must say what its references look like rather than inheriting Google's regex.
     */
    public static function documentIdFrom(ConnectorProviderKey $provider, string $reference): ?string
    {
        return match ($provider) {
            // Unreachable in practice — Slack registers no tabular directory, so `attempt()` refuses first.
            // Present because the match is exhaustive by design, and null is the truthful answer.
            ConnectorProviderKey::Slack => null,
            ConnectorProviderKey::GoogleSheets => self::googleSpreadsheetIdFrom($reference),
            ConnectorProviderKey::Airtable => self::airtableBaseIdFrom($reference),
        };
    }

    /**
     * ⚠️ A URL IS THE NORMAL INPUT AND A BARE ID IS THE RARE ONE — which is the opposite of what a field
     * labelled "spreadsheet id" implies. Nobody reads an id out of a Google Sheets URL by hand; they copy the
     * address bar. Accepting only the id would reject the thing every tenant will actually paste, and the
     * server would then answer 404 for a perfectly valid sheet, which is the exact confusion this seam exists
     * to remove.
     *
     * Google's ids are 20+ characters of `[A-Za-z0-9_-]`, so the pattern is anchored on the `/d/<id>/` path
     * segment rather than on "a long token somewhere in the string": a URL also contains `docs`, `google` and
     * a `gid` fragment, and a greedy match would happily return one of those.
     */
    private static function googleSpreadsheetIdFrom(string $reference): ?string
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
     * Airtable base ids are exactly `app` + 14 alphanumerics, which is a far tighter shape than Google's — so
     * this is anchored on the prefix rather than on a length floor.
     *
     * The bare id is the NORMAL input here, inverting the Google case above: the editor picks a base from the
     * enumerated list and hands the id over directly, because `schema.bases:read` can list where `drive.file`
     * cannot. A pasted URL is still accepted, because a tenant who has the base open in another tab will paste
     * one and being strict would reject something we can read perfectly well.
     */
    private static function airtableBaseIdFrom(string $reference): ?string
    {
        $trimmed = trim($reference);

        if (preg_match('#\b(app[A-Za-z0-9]{14})\b#', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  callable(): TabularDestination  $operation
     * @return array{destination: ?array{spreadsheet_id: string, title: string, url: string, tabs: list<string>, sheet_name: string, header_row: list<string>, sheet_id: ?string}, error: ?string}
     */
    private function run(Connection $connection, callable $operation): array
    {
        try {
            return ['destination' => $operation()->toArray(), 'error' => null];
        } catch (ConnectorDestinationException $e) {
            return $this->failure($this->message($connection->provider, $e->errorCode));
        }
    }

    /**
     * A provider error code to copy the tenant can act on. The default is deliberately generic: the code can
     * be any string the provider chooses, so echoing it would put unreviewed third-party text on our page —
     * the {@see ConnectorChannelDirectory} rule, unchanged.
     *
     * ⚠️ THE COPY IS PER-PROVIDER WHERE THE REMEDY IS, AND SHARED WHERE IT IS NOT. `not_found` is the sharpest
     * example: for Sheets it means "per-file access cannot see this, create one here instead", which is the
     * whole H16b argument — and for Airtable that sentence would be nonsense, because we CAN list their bases
     * and cannot create anything. Same code, opposite advice.
     */
    private function message(ConnectorProviderKey $provider, string $errorCode): string
    {
        $label = $provider->label();
        // TWO nouns, because a tabular destination has two: the tab/table a rule writes into, and the
        // spreadsheet/base it lives in. Collapsing them re-points "that tab is gone" onto the whole document,
        // which is advice for a different problem — see `ConnectorProviderKey::destinationNoun()`.
        $noun = $provider->destinationNoun();
        $container = $provider->containerNoun() ?? 'destination';

        if ($errorCode === 'not_found' || $errorCode === 'not_shared') {
            return match ($provider) {
                ConnectorProviderKey::GoogleSheets => 'We can’t open that spreadsheet. With per-file access we can only reach sheets we created for you — create one here instead, and we’ll set up its columns.',
                ConnectorProviderKey::Airtable => 'We can’t open that base. Check it’s still shared with the Airtable account you connected, then pick it again.',
                ConnectorProviderKey::Slack => 'We can’t open that destination.',
            };
        }

        if ($errorCode === 'invalid_destination') {
            return match ($provider) {
                ConnectorProviderKey::GoogleSheets => 'That doesn’t look like a Google Sheets link. Paste the whole address from your browser.',
                ConnectorProviderKey::Airtable => 'That doesn’t look like an Airtable base. Pick one from the list, or paste a base link from your browser.',
                ConnectorProviderKey::Slack => 'That doesn’t look like a destination we can write to.',
            };
        }

        return match ($errorCode) {
            'unauthenticated' => "{$label} rejected our credentials. Reconnect this account, then try again.",
            'unknown_tab' => "That {$noun} isn’t in the {$container} any more. Pick another one.",
            'no_tabs' => "That {$container} has no {$noun}s to write into.",
            'rate_limited' => "{$label} is rate-limiting us. Wait a moment and try again.",
            'transport_error', 'provider_unavailable' => "We couldn’t reach {$label}. Check your connection and try again.",
            default => "We couldn’t set up that {$noun}. Try again, or pick a different one.",
        };
    }

    private function reconnectMessage(ConnectorProviderKey $provider): string
    {
        return 'This account needs to be reconnected before we can reach your '.($provider->containerNoun() ?? 'destination').'s.';
    }

    /**
     * Only reachable by a hand-made request — see the guard in {@see create()}.
     */
    private function cannotCreateMessage(ConnectorProviderKey $provider): string
    {
        return 'Meridian can’t create a '.$provider->destinationNoun().' in '.$provider->label().
            '. Make one there, then pick it here.';
    }

    /**
     * @return array{destination: null, error: string}
     */
    private function failure(string $message): array
    {
        return ['destination' => null, 'error' => $message];
    }
}
