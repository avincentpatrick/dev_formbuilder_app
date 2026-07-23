<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// EntitlementService is the single interpreter of a tenant's plan (ADR-0008 §D3). These pin the read
// contract H5b's enforcement will consume: current plan resolution, the free fallback, feature/quota
// lookups, and the fail-closed off-tenant behaviour of the shared snapshot.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = inboxTenant('acme');
    enterTenant($this->tenant->id);
});

it('falls back to the free tier when the tenant has no subscription', function (): void {
    Plan::factory()->tier(PlanTier::Free)->create();

    $svc = app(EntitlementService::class);

    expect($svc->currentPlan()?->code)->toBe(PlanTier::Free);
    expect($svc->tier())->toBe(PlanTier::Free);
});

it('resolves the active subscription\'s plan', function (): void {
    $starter = Plan::factory()->tier(PlanTier::Starter)
        ->withFeatures(['xlsform_export' => true, 'custom_domain' => false])
        ->withQuotas([UsageMetric::FormsCount->value => 20, UsageMetric::StorageBytes->value => null])
        ->create();
    Subscription::factory()->forPlan($starter)->create();

    $svc = app(EntitlementService::class);

    expect($svc->tier())->toBe(PlanTier::Starter);
    expect($svc->feature('xlsform_export'))->toBeTrue();
    expect($svc->feature('custom_domain'))->toBeFalse();
    expect($svc->feature('unknown_key'))->toBeFalse(); // absent key ⇒ disabled
    expect($svc->quota(UsageMetric::FormsCount))->toBe(20);
    expect($svc->quota(UsageMetric::StorageBytes))->toBeNull(); // null ⇒ unlimited
});

it('ignores an ended subscription and falls back to free', function (): void {
    Plan::factory()->tier(PlanTier::Free)->create();
    $pro = Plan::factory()->tier(PlanTier::Professional)->create();
    Subscription::factory()->forPlan($pro)->ended()->create();

    expect(app(EntitlementService::class)->tier())->toBe(PlanTier::Free);
});

it('reads usage as zero when nothing is metered (H5a ships no increments)', function (): void {
    $starter = Plan::factory()->tier(PlanTier::Starter)->create();
    Subscription::factory()->forPlan($starter)->create();

    expect(app(EntitlementService::class)->usage(UsageMetric::SubmissionsCount))->toBe(0);
});

it('builds a snapshot of plan, features and quota-vs-usage on a tenant route', function (): void {
    $starter = Plan::factory()->tier(PlanTier::Starter)
        ->withFeatures(['xlsform_export' => true, 'custom_domain' => false])
        ->withQuotas([UsageMetric::FormsCount->value => 20])
        ->create();
    Subscription::factory()->forPlan($starter)->create();

    $snapshot = app(EntitlementService::class)->snapshot();

    expect($snapshot)->not->toBeNull();
    expect($snapshot['plan'])->toBe(['code' => 'starter', 'name' => 'Starter']);
    expect($snapshot['features']['xlsform_export'])->toBeTrue();
    expect($snapshot['features']['custom_domain'])->toBeFalse();
    expect($snapshot['quotas'][UsageMetric::FormsCount->value])->toBe(['limit' => 20, 'used' => 0]);
});

it('returns a null snapshot off-tenant (fail-closed)', function (): void {
    TenantContext::flush(); // central/guest route — no tenant context

    expect(app(EntitlementService::class)->snapshot())->toBeNull();
});

it('counts a gauge live and non-memoized for the guard (H5b)', function (): void {
    $owner = User::factory()->create();
    $svc = app(EntitlementService::class);

    expect($svc->countGauge(UsageMetric::FormsCount))->toBe(0);

    app(FormService::class)->create($this->tenant, $owner, 'Fresh');

    // countGauge is the authoritative pre-write read the QuotaGuard uses — never memoized, so a create is
    // reflected immediately (no stale slot).
    expect($svc->countGauge(UsageMetric::FormsCount))->toBe(1);
});

it('reads a gauge through usage() live, but a flow metric from the counter (H5b)', function (): void {
    $owner = User::factory()->create();
    app(FormService::class)->create($this->tenant, $owner, 'One');

    $svc = app(EntitlementService::class);

    // forms_count is a gauge → computed live (the just-created form shows); submissions_count is a flow
    // metric → read from usage_counters (0, nothing metered here).
    expect($svc->usage(UsageMetric::FormsCount))->toBe(1)
        ->and($svc->usage(UsageMetric::SubmissionsCount))->toBe(0);
});
