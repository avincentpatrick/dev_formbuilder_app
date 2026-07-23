<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\LegacyOverride;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The api_access feature-gate (H5c / ADR-0008 §D5) on the whole /api/v1 Group-B surface. A plan without
// api_access 402s every token-consumed route (before the per-route ability/feature gates); Starter passes; a
// grandfathered Free tenant passes via its legacy override. Minting a key is gated; listing/revoking is not.

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** An acme tenant + active admin on `$tier`; returns [$tenant, $admin, $readToken]. Context left on the tenant. */
function apiAccessTenant(PlanTier $tier): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    assignPlanTier($tier);
    $token = $admin->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    return [$tenant, $admin, $token];
}

it('402s a token-consumed request for a plan without api_access', function (): void {
    [, , $token] = apiAccessTenant(PlanTier::Free);

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'feature_not_available')
        ->assertJsonPath('error.details.feature', 'api_access');
});

it('lets a Starter tenant through the api_access gate', function (): void {
    [, , $token] = apiAccessTenant(PlanTier::Starter);

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertOk();
});

it('grandfathers a Free tenant through the api_access gate via a legacy override', function (): void {
    [$tenant, $admin] = apiAccessTenant(PlanTier::Free);
    LegacyOverride::create(['feature_flags' => ['api_access' => true]]);
    app(EntitlementService::class)->forget();
    $token = $admin->createToken('ci2', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->getJson('http://acme.meridian.test/api/v1/tenant')
        ->assertOk();
});

it('gates minting a key behind api_access, but not listing keys', function (): void {
    [, $admin] = apiAccessTenant(PlanTier::Free);

    // Mint is session-authed (Group A) and feature-gated — 402 before the controller.
    $this->actingAs($admin)
        ->postJson('http://acme.meridian.test/api/v1/auth/tokens', ['name' => 'k', 'abilities' => [ApiAbilities::READ_FORMS]])
        ->assertStatus(402)
        ->assertJsonPath('error.details.feature', 'api_access');

    // Listing existing keys stays ungated so a downgraded tenant can still see/revoke them.
    $this->actingAs($admin)
        ->getJson('http://acme.meridian.test/api/v1/auth/tokens')
        ->assertOk();
});
