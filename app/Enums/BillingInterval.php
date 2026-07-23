<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often a subscription bills (data-dictionary.md §17 `BillingInterval`, backs
 * `subscriptions.billing_interval` and the entries inside `plans.billing_interval_options`; ADR-0008).
 * The `subscriptions_billing_interval_check` CHECK is generated from {@see values()} so enum and
 * constraint cannot drift. The actual discount for `yearly` is a Phase-4 commercial decision, not an
 * architectural one (pricing-feature-gating-matrix.md §6).
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
