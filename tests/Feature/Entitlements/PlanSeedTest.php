<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// PlanSeeder writes the global catalog on the DEFAULT connection (plans has no RLS), so — unlike the
// privileged-connection platform seeders — its rows live inside the RefreshDatabase transaction and need
// no manual cleanup. These pin the catalog shape + idempotency (ADR-0008).

it('seeds all five tiers', function (): void {
    $this->seed(PlanSeeder::class);

    expect(Plan::count())->toBe(5);

    foreach (PlanTier::cases() as $tier) {
        expect(Plan::where('code', $tier->value)->exists())->toBeTrue();
    }
});

it('seeds only valid feature-flag and quota keys', function (): void {
    $this->seed(PlanSeeder::class);

    foreach (Plan::all() as $plan) {
        // Quota keys must all be real UsageMetric values (the CHECK-less jsonb is validated here).
        foreach (array_keys($plan->quotas) as $metricKey) {
            expect(UsageMetric::tryFrom((string) $metricKey))->not->toBeNull();
        }

        // Feature flag values are all booleans.
        foreach ($plan->feature_flags as $value) {
            expect($value)->toBeBool();
        }
    }
});

it('holds business and enterprise from sale but keeps free/starter/professional active', function (): void {
    $this->seed(PlanSeeder::class);

    expect(Plan::where('code', PlanTier::Business->value)->value('is_active'))->toBeFalse();
    expect(Plan::where('code', PlanTier::Enterprise->value)->value('is_active'))->toBeFalse();

    foreach ([PlanTier::Free, PlanTier::Starter, PlanTier::Professional] as $tier) {
        expect(Plan::where('code', $tier->value)->value('is_active'))->toBeTrue();
    }
});

it('reflects the ADR-0008 §D7 tier decisions in the seeded flags', function (): void {
    $this->seed(PlanSeeder::class);

    $free = Plan::where('code', PlanTier::Free->value)->firstOrFail();
    $starter = Plan::where('code', PlanTier::Starter->value)->firstOrFail();
    $business = Plan::where('code', PlanTier::Business->value)->firstOrFail();

    // native_connectors + branding are Starter+ (not Free); custom_domain stays Business (not Starter).
    expect($starter->featureEnabled('native_connectors'))->toBeTrue();
    expect($starter->featureEnabled('branding'))->toBeTrue();
    expect($starter->featureEnabled('custom_domain'))->toBeFalse();
    expect($business->featureEnabled('custom_domain'))->toBeTrue();
    expect($free->featureEnabled('native_connectors'))->toBeFalse();
});

it('is idempotent on re-seed', function (): void {
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    expect(Plan::count())->toBe(5);
});
