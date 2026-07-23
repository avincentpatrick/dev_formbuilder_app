<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\LegacyOverride;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The per-month api_requests quota 429 (H5c / ADR-0008 §D4) on the /api/v1 surface. EnforceApiRequestQuota
// sits after the api_access gate + burst throttle and before the meter, so a request rejected at the quota is
// not itself counted. A null/0 quota never 429s — the grandfathered-Free case (api_access via override, Free
// api_requests=0) must NOT be throttled at 0.

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** An acme tenant + admin + read token. Context left on the tenant so the caller can seed a plan/usage. */
function apiQuotaTenant(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    return [$tenant, $admin, $token];
}

/** A plan carrying api_access + a specific api_requests quota, plus a seeded current-period usage row. */
function apiQuotaPlan(int $limit, int $used): void
{
    $plan = Plan::factory()->tier(PlanTier::Starter)
        ->withFeatures(['api_access' => true])
        ->withQuotas(['api_requests' => $limit])
        ->create();
    Subscription::factory()->forPlan($plan)->create();
    UsageCounter::factory()->forMetric(UsageMetric::ApiRequests)->withValue($used)->create();
    app(EntitlementService::class)->forget();
}

it('429s once the metered api_requests usage reaches the plan quota', function (): void {
    [, , $token] = apiQuotaTenant();
    apiQuotaPlan(limit: 2, used: 2);

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limit_exceeded_api_requests')
        ->assertJsonPath('error.details.limit', 2)
        ->assertHeader('Retry-After');
});

it('serves a request that is still under the quota', function (): void {
    [, , $token] = apiQuotaTenant();
    apiQuotaPlan(limit: 2, used: 1);

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertOk();
});

it('does not meter a request it rejects with a 429', function (): void {
    [$tenant, $admin, $token] = apiQuotaTenant();
    apiQuotaPlan(limit: 2, used: 2);

    $this->withToken($token)->getJson('http://acme.meridian.test/api/v1/tenant')->assertStatus(429);

    enterTenant($tenant->id, $admin->id);
    // The rejected request threw before MeterApiUsage ran, so usage stays at the limit, not limit + 1.
    expect((int) UsageCounter::query()->where('metric', UsageMetric::ApiRequests->value)->value('value'))->toBe(2);
});

it('never 429s a grandfathered Free tenant whose api_requests quota is 0', function (): void {
    [$tenant, $admin] = apiQuotaTenant();
    assignPlanTier(PlanTier::Free); // api_requests = 0
    LegacyOverride::create(['feature_flags' => ['api_access' => true]]); // grandfathered API access
    UsageCounter::factory()->forMetric(UsageMetric::ApiRequests)->withValue(50)->create();
    app(EntitlementService::class)->forget();
    $token = $admin->createToken('ci2', [ApiAbilities::READ_FORMS])->plainTextToken;

    // api_access passes via the override; the 0 quota is the "no access" sentinel, treated as unbounded, so no
    // 429 — the grandfather is not hollow.
    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertOk();
});
