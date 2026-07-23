<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\LegacyOverride;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Migrations\LegacyOverrideBackfill;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// The grandfather storage (ADR-0008 §D5, H5c) — legacy_overrides consulted by EntitlementService::feature()
// AHEAD of the plan flags. A grandfathered tenant reads `true` for a feature its plan would deny; a tenant
// with no override row falls through to the plan. Proven at the service level here (the merge-day backfill
// mechanism itself is pure-unit-tested in tests/Unit/LegacyOverrideBackfillTest.php).

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->entitlements = app(EntitlementService::class);
});

it('lets an override win over the plan flags', function (): void {
    assignPlanTier(PlanTier::Free); // xlsform_export = false on Free
    LegacyOverride::create(['feature_flags' => ['xlsform_export' => true]]);
    $this->entitlements->forget();

    expect($this->entitlements->feature('xlsform_export'))->toBeTrue();
});

it('grandfathers all five ungated Phase-2 features for a Free tenant', function (): void {
    assignPlanTier(PlanTier::Free); // every gated feature false on Free
    LegacyOverride::create(['feature_flags' => LegacyOverrideBackfill::overrideFlags()]);
    $this->entitlements->forget();

    foreach (LegacyOverrideBackfill::GRANDFATHERED_KEYS as $key) {
        expect($this->entitlements->feature($key))->toBeTrue("expected grandfathered {$key} to be enabled");
    }

    // A feature NOT in the override set still resolves from the plan (Free → false), so the override is a
    // grant, not a blanket unlock.
    expect($this->entitlements->feature('custom_domain'))->toBeFalse();
});

it('falls through to the plan when there is no override row', function (): void {
    assignPlanTier(PlanTier::Free);

    expect($this->entitlements->feature('xlsform_export'))->toBeFalse()
        ->and($this->entitlements->feature('api_access'))->toBeFalse();
});

it('reads the override once per request and memoizes it', function (): void {
    assignPlanTier(PlanTier::Starter);
    LegacyOverride::create(['feature_flags' => ['xlsform_export' => true]]);
    $this->entitlements->forget();

    DB::enableQueryLog();
    // snapshot() calls feature() once per flag key (15 keys) — an unmemoized read would be 15 queries.
    $this->entitlements->snapshot();
    $overrideReads = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'legacy_overrides'))
        ->count();
    DB::disableQueryLog();

    expect($overrideReads)->toBe(1);
});

it('drops the memoized override on forget', function (): void {
    assignPlanTier(PlanTier::Free);
    expect($this->entitlements->feature('xlsform_export'))->toBeFalse(); // memoizes an empty override map

    LegacyOverride::create(['feature_flags' => ['xlsform_export' => true]]);
    // Without forget the stale (empty) memo would still say false; after forget the new row is read.
    $this->entitlements->forget();

    expect($this->entitlements->feature('xlsform_export'))->toBeTrue();
});

it('does not leak one tenant\'s override to another under RLS', function (): void {
    assignPlanTier(PlanTier::Free);
    LegacyOverride::create(['feature_flags' => ['xlsform_export' => true]]);

    $other = inboxTenant('beta');
    $otherOwner = User::factory()->create();
    enterTenant($other->id, $otherOwner->id);
    assignPlanTier(PlanTier::Free);
    app(EntitlementService::class)->forget();

    // Beta has no override row of its own; acme's must not be visible to it.
    expect(app(EntitlementService::class)->feature('xlsform_export'))->toBeFalse();
});
