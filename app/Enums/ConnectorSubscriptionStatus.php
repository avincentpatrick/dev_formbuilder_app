<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ConnectionSubscription;
use App\Services\Connectors\ConnectorEventDispatcher;

/**
 * The lifecycle state of a {@see ConnectionSubscription} — one "send these events to this destination"
 * rule on a connection (ADR-0009, H15a). The `connection_subscriptions_status_check` CHECK is generated
 * from {@see values()}.
 *
 *   - {@see Active}   — the fan-out ({@see ConnectorEventDispatcher}) creates deliveries for it.
 *   - {@see Paused}   — temporarily off: the per-subscription circuit breaker auto-pauses at
 *     `config('webhooks.breaker_threshold')` consecutive failures; re-enabling resets the breaker.
 *   - {@see Disabled} — turned off by an admin.
 *
 * Deliberately its own enum rather than a reuse of {@see WebhookEndpointStatus}: the three values coincide
 * today, but a subscription's pause semantics are also driven by its CONNECTION dying (a credential the
 * tenant cannot fix by editing the rule), which a webhook endpoint has no analogue for.
 */
enum ConnectorSubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
