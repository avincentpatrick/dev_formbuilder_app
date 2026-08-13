<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Connection;
use App\Support\Connectors\ConnectorProvider;
use App\Support\Mapping\ColumnMapping;

/**
 * The third-party services the native-connector framework can hold an OAuth grant for (ADR-0009, H15a).
 * The `connections_provider_check` CHECK is generated from {@see values()} so enum and constraint cannot
 * drift, and `config('connectors.providers')` maps each case to its {@see ConnectorProvider} adapter.
 *
 *   - {@see Slack} — the first provider, built in H15a (webhook-integration-design.md §4 row 3).
 *   - {@see GoogleSheets} — §4 row 4, built in H16a: appends one row per event to a tenant's spreadsheet.
 *   - {@see Airtable} — §4 row 5, built in H16c: appends one record per event to a tenant's table.
 *
 * ── ADDING A FOURTH, AND THE COUNT IN THIS PARAGRAPH USED TO BE WRONG ────────────────────────────────────
 *
 * Declare the case only once its adapter exists: a case with no adapter is selectable in a {@see Connection}
 * row the framework cannot service, which is why Airtable sat undeclared here from H15a until H16c.
 *
 * A case + a `config('connectors.providers')` entry + a CHECK-recreate migration (the `2026_07_26_000004`
 * pattern), and then every `default`-less `match` over this enum, because a missing arm there is an
 * `UnhandledMatchError` on a page rather than a compile-time complaint. This list is exhaustive as of H16c
 * and is FIVE, not the two it named until now — H16b added `providerNotice()` after this docblock was
 * written and did not come back to it, which is exactly the drift the list exists to prevent:
 *
 *   {@see label()} · {@see isTabular()} · {@see destinationNoun()} ·
 *   `ConnectionPresenter::providerNotice()` · `ConnectionPresenter::providerDescription()`
 *
 * `SubscriptionConfigRulesTest`'s "every provider declares a required destination key" case is the other
 * forcing device, and it fails loudly rather than at render time.
 */
enum ConnectorProviderKey: string
{
    case Slack = 'slack';

    case GoogleSheets = 'google_sheets';

    case Airtable = 'airtable';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** The human label used in flash/notification copy and on the H15b Integrations page. */
    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::GoogleSheets => 'Google Sheets',
            self::Airtable => 'Airtable',
        };
    }

    /**
     * Whether this provider's destination is a TABLE whose columns are bound to form fields by a stored
     * {@see ColumnMapping} (H16a), as opposed to a message destination like a Slack
     * channel.
     *
     * Asked by the shared request validation, which cannot otherwise know whether `config.channel_id` or
     * `config.spreadsheet_id` is the required key. It is a property of the DESTINATION rather than of the
     * adapter class, so it lives on the enum beside `label()` rather than becoming a fifth interface method
     * on {@see ConnectorProvider}, whose docblock pins it at four responsibilities.
     */
    public function isTabular(): bool
    {
        return match ($this) {
            self::Slack => false,
            self::GoogleSheets => true,
            self::Airtable => true,
        };
    }

    /**
     * What this provider calls the thing a rule delivers INTO, lower-case and singular, for interpolation
     * into validation messages and form copy (H16c).
     *
     * ── WHY A NOUN AND NOT ANOTHER BOOLEAN ───────────────────────────────────────────────────────────────
     *
     * {@see isTabular()} answers "which config keys are required", and H16a correctly used it for that. It
     * was then reused for COPY — "Choose a spreadsheet to write into", "A spreadsheet can only receive
     * submission events" — which silently made "tabular" and "spreadsheet" synonyms. They are not: Airtable
     * is tabular and has no spreadsheets, so every one of those strings would have shipped naming the wrong
     * product to the tenant. A boolean cannot carry a noun, and stretching one until it does is how the copy
     * got Sheets-specific in a shared layer in the first place.
     *
     * Deliberately not a label for the DESTINATION KIND ("channel or table") but for the provider's own word,
     * because the string is read by someone looking at that provider's own UI beside it.
     *
     * ⚠️ THIS IS THE INNER THING — the tab, the table, the channel — AND ITS FIRST DRAFT WAS WRONG. It
     * returned `spreadsheet` for Sheets, which quietly re-pointed copy that had always meant the TAB: "that
     * tab isn't in the spreadsheet any more" became "that spreadsheet isn't there any more", advice for a
     * different problem. A tabular provider has TWO nouns and one method cannot carry both — see
     * {@see containerNoun()}.
     */
    public function destinationNoun(): string
    {
        return match ($this) {
            self::Slack => 'channel',
            self::GoogleSheets => 'tab',
            self::Airtable => 'table',
        };
    }

    /**
     * The DOCUMENT a {@see destinationNoun()} lives inside, or null for a provider whose destination has no
     * container (H16c).
     *
     * Slack's channel is not "inside" anything a tenant picks, so null is the truthful answer rather than a
     * stretched word — and every caller of this pairs it with a provider that has a tabular destination.
     */
    public function containerNoun(): ?string
    {
        return match ($this) {
            self::Slack => null,
            self::GoogleSheets => 'spreadsheet',
            self::Airtable => 'base',
        };
    }
}
