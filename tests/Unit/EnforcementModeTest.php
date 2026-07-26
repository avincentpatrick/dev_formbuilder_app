<?php

declare(strict_types=1);

use App\Enums\EnforcementMode;
use App\Enums\UsageMetric;

// The H5b enforcement classification (ADR-0008 §D4), promoted from the enum docblock into code. These pin
// the split the QuotaGuard / UsageMeter read, so a future edit that mis-buckets a metric fails loudly.

it('classifies each metric into its ADR-0008 §D4 enforcement mode', function (UsageMetric $metric, EnforcementMode $mode): void {
    expect($metric->enforcementMode())->toBe($mode);
})->with([
    'forms_count → hard-block' => [UsageMetric::FormsCount, EnforcementMode::HardBlock],
    'storage_bytes → hard-block' => [UsageMetric::StorageBytes, EnforcementMode::HardBlock],
    'active_seats → hard-block' => [UsageMetric::ActiveSeats, EnforcementMode::HardBlock],
    'webhook_endpoints_count → hard-block' => [UsageMetric::WebhookEndpointsCount, EnforcementMode::HardBlock],
    'submissions_count → never-block' => [UsageMetric::SubmissionsCount, EnforcementMode::NeverBlock],
    'api_requests → rate-limit' => [UsageMetric::ApiRequests, EnforcementMode::RateLimit],
    'webhook_deliveries → rate-limit' => [UsageMetric::WebhookDeliveries, EnforcementMode::RateLimit],
    'exports_count → unclassified' => [UsageMetric::ExportsCount, EnforcementMode::Unclassified],
]);

it('marks exactly the provisioning metrics as gauges', function (): void {
    $gauges = array_values(array_filter(
        UsageMetric::cases(),
        static fn (UsageMetric $m): bool => $m->isGauge(),
    ));

    // In catalog order: storage_bytes, active_seats, forms_count, webhook_endpoints_count (H13a).
    expect($gauges)->toBe([UsageMetric::StorageBytes, UsageMetric::ActiveSeats, UsageMetric::FormsCount, UsageMetric::WebhookEndpointsCount]);
});

it('keeps every hard-block metric a gauge and every gauge hard-blocked', function (): void {
    foreach (UsageMetric::cases() as $metric) {
        expect($metric->isGauge())->toBe($metric->enforcementMode() === EnforcementMode::HardBlock);
    }
});
