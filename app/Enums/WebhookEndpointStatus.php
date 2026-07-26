<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookEventDispatcher;

/**
 * The lifecycle state of a {@see WebhookEndpoint} (data-dictionary §14 `WebhookEndpointStatus`,
 * H13a). The `webhook_endpoints_status_check` CHECK is generated from {@see values()} so enum and constraint
 * cannot drift.
 *
 *   - {@see Active}   — deliveries fan out to it.
 *   - {@see Paused}   — temporarily off: the per-endpoint circuit breaker auto-pauses at
 *     `config('webhooks.breaker_threshold')` consecutive failures (`disabled_reason = 'too_many_failures'`);
 *     re-enabling is a manual admin action that resets the breaker.
 *   - {@see Disabled} — turned off by an admin (`disabled_reason = 'manual'`) or the platform.
 *
 * Only {@see Active} endpoints receive deliveries; the fan-out ({@see WebhookEventDispatcher})
 * filters on it.
 */
enum WebhookEndpointStatus: string
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
