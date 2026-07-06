<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment C3 — Settings page (Profile / Appearance / Security).
|--------------------------------------------------------------------------
| The page now renders through PreferencesController@show, which passes the current 2FA enrolment state
| the Security section needs. Profile/password/2FA drive Fortify's own endpoints (covered by Fortify);
| this asserts the page renders with the 2FA state prop for a user who has not enrolled.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders settings with the two-factor enrolment state', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // false: skip the page-file-exists check — the tests job doesn't build the frontend.
            ->component('Settings/Index', false)
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.confirmed', false));
});
