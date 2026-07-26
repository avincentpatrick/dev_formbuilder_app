<?php

declare(strict_types=1);

namespace App\Enums;

use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\WebhookDelivery;

/**
 * The state of a single {@see WebhookDelivery} attempt-record (data-dictionary §15
 * `WebhookDeliveryStatus`, H13a). The `webhook_deliveries_status_check` CHECK is generated from
 * {@see values()} so enum and constraint cannot drift.
 *
 *   - {@see Pending}      — created, not yet attempted (or waiting on `next_retry_at`).
 *   - {@see Delivering}   — an attempt is in flight (set at the top of {@see DeliverWebhookJob}).
 *   - {@see Succeeded}    — a 2xx was received within the delivery timeout.
 *   - {@see Failed}       — the last attempt failed (non-2xx / timeout / SSRF re-block) and retries remain;
 *     the `next_retry_at` sweep will re-attempt it per the retry ladder.
 *   - {@see DeadLettered} — retries are exhausted (`attempt_count >= max_attempts`) or the delivery was
 *     refused before sending (monthly quota exceeded). Terminal until a manual redeliver (H13b).
 *
 * The design doc's `dns_rebinding_blocked`/`quota_exceeded` outcomes are NOT distinct statuses (the schema
 * pins these five) — they are recorded as a `[marker]` prefix in `response_body_excerpt` (H13a); a dedicated
 * status is a H14 option if the delivery-log UI needs one.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
