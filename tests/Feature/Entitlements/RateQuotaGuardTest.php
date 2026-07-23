<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Exceptions\Entitlements\RateLimitExceededException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The rate-limit half of QuotaGuard (H5c / ADR-0008 §D4): assertWithinRateQuota 429s a metered flow metric
// (api_requests, webhook_deliveries) whose current-period usage has reached the plan's monthly quota. A
// null quota (unlimited) or a 0 quota (the Free "no access" sentinel, reached only via a grandfather
// override) never 429s. The HTTP wiring is exercised in ApiRequestQuotaTest.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->guard = app(QuotaGuard::class);
});

/** Assign a plan carrying one rate-limit quota, seed the current-period usage, and reset the entitlement memo. */
function h5cRateFixture(UsageMetric $metric, int $limit, int $used): void
{
    $plan = Plan::factory()->tier(PlanTier::Starter)->withQuotas([$metric->value => $limit])->create();
    Subscription::factory()->forPlan($plan)->create();
    UsageCounter::factory()->forMetric($metric)->withValue($used)->create();
    app(EntitlementService::class)->forget();
}

it('does not throw when usage is below the quota', function (): void {
    h5cRateFixture(UsageMetric::ApiRequests, limit: 10, used: 9);

    expect(fn () => $this->guard->assertWithinRateQuota(UsageMetric::ApiRequests))
        ->not->toThrow(RateLimitExceededException::class);
});

it('429s once usage has reached the quota', function (): void {
    h5cRateFixture(UsageMetric::ApiRequests, limit: 10, used: 10);

    try {
        $this->guard->assertWithinRateQuota(UsageMetric::ApiRequests);
        expect()->fail('expected a RateLimitExceededException');
    } catch (RateLimitExceededException $e) {
        expect($e->status())->toBe(429)
            ->and($e->code())->toBe('rate_limit_exceeded_api_requests')
            ->and($e->metric())->toBe(UsageMetric::ApiRequests)
            ->and($e->limit())->toBe(10)
            ->and($e->used())->toBe(10)
            ->and($e->details())->toBe(['metric' => 'api_requests', 'limit' => 10, 'used' => 10])
            ->and($e->headers())->toHaveKey('Retry-After')
            ->and((int) $e->headers()['Retry-After'])->toBeGreaterThan(0);
    }
});

it('never 429s on an unlimited (null) quota', function (): void {
    assignUnlimitedPlan(); // Enterprise — api_requests null
    UsageCounter::factory()->forMetric(UsageMetric::ApiRequests)->withValue(999_999)->create();
    app(EntitlementService::class)->forget();

    expect(fn () => $this->guard->assertWithinRateQuota(UsageMetric::ApiRequests))
        ->not->toThrow(RateLimitExceededException::class);
});

it('never 429s on a 0 quota — the Free "no access" sentinel governed by the feature gate, not a throttle', function (): void {
    assignPlanTier(PlanTier::Free); // api_requests = 0
    UsageCounter::factory()->forMetric(UsageMetric::ApiRequests)->withValue(5)->create();
    app(EntitlementService::class)->forget();

    // A grandfathered Free tenant has api_access=true but this 0 quota; it must read as unbounded, not a
    // 0-request throttle, or the grandfather would be hollow.
    expect(fn () => $this->guard->assertWithinRateQuota(UsageMetric::ApiRequests))
        ->not->toThrow(RateLimitExceededException::class);
});

it('rejects a non-rate-limit metric as a programming error', function (): void {
    expect(fn () => $this->guard->assertWithinRateQuota(UsageMetric::FormsCount))
        ->toThrow(LogicException::class);
});

it('is reusable for webhook_deliveries (the H13 seam)', function (): void {
    h5cRateFixture(UsageMetric::WebhookDeliveries, limit: 3, used: 3);

    expect(fn () => $this->guard->assertWithinRateQuota(UsageMetric::WebhookDeliveries))
        ->toThrow(RateLimitExceededException::class, 'webhook deliveries');
});
