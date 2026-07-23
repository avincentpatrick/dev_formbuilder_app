<?php

declare(strict_types=1);

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Enums\UsageMetric;

// The H5a enums back DB CHECK constraints generated from values(), so their contents and ORDER are pinned
// here — a reorder or a dropped case would silently change the generated constraint (data-dictionary §16-18).

it('exposes the five plan tiers', function (): void {
    expect(PlanTier::values())->toBe(['free', 'starter', 'professional', 'business', 'enterprise']);
});

it('exposes both billing intervals', function (): void {
    expect(BillingInterval::values())->toBe(['monthly', 'yearly']);
});

it('exposes the seven usage metrics in catalog order', function (): void {
    expect(UsageMetric::values())->toBe([
        'submissions_count',
        'storage_bytes',
        'api_requests',
        'webhook_deliveries',
        'active_seats',
        'forms_count',
        'exports_count',
    ]);
});
