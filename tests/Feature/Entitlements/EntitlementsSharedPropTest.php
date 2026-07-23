<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The `entitlements` shared Inertia prop (H5a / ADR-0008) — one read-model both H5b's gated UI and any
// future Plan & Usage panel consume. It reaches every tenant page through HandleInertiaRequests::share(),
// fail-closed off-tenant (EntitlementService::snapshot() returns null with no tenant context — covered in
// EntitlementServiceTest). This proves it is wired and carries the snapshot on a real tenant route.

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('shares the entitlement snapshot on a tenant route', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');
    Plan::factory()->tier(PlanTier::Free)->create(); // an unsubscribed tenant resolves to free

    $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index', false)
            ->has('entitlements')
            ->where('entitlements.plan.code', 'free')
            ->has('entitlements.features')
            ->has('entitlements.quotas'));
});
