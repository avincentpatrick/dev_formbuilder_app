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
 *
 * Airtable (§4 row 5) is H16c and is deliberately still NOT declared: a case whose adapter does not exist
 * would be selectable in a {@see Connection} row the framework cannot service. Adding one is a case + a
 * config entry + a CHECK-recreate migration (the `2026_07_26_000004` pattern) — and, as H16a discovered, a
 * {@see label()} arm and a `ConnectionPresenter::providerDescription()` arm, because both are `default`-less
 * `match`es and a missing arm is an `UnhandledMatchError` on a page rather than a compile-time complaint.
 */
enum ConnectorProviderKey: string
{
    case Slack = 'slack';

    case GoogleSheets = 'google_sheets';

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
        };
    }
}
