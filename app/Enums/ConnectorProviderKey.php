<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Connection;
use App\Support\Connectors\ConnectorProvider;

/**
 * The third-party services the native-connector framework can hold an OAuth grant for (ADR-0009, H15a).
 * The `connections_provider_check` CHECK is generated from {@see values()} so enum and constraint cannot
 * drift, and `config('connectors.providers')` maps each case to its {@see ConnectorProvider} adapter.
 *
 *   - {@see Slack} — the only provider built in H15a (webhook-integration-design.md §4 row 3).
 *
 * Google Sheets and Airtable (§4 rows 4–5) are H16 and are deliberately NOT declared here: a case whose
 * adapter does not exist would be selectable in a {@see Connection} row the framework cannot service.
 * Adding one is a case + a config entry + a CHECK-recreate migration (the `2026_07_26_000004` pattern).
 */
enum ConnectorProviderKey: string
{
    case Slack = 'slack';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** The human label used in flash/notification copy (the connector has no UI until H15b). */
    public function label(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
        };
    }
}
