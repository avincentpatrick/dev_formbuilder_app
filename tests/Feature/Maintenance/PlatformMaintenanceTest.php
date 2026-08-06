<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Settings\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Platform maintenance mode (Increment I5, PRD Feature #10) — the whole product, with three exemptions.
|
| THE EXEMPTIONS ARE THE POINT OF THIS FILE. `EnforcePlatformMaintenance` is a GLOBAL middleware, and
| global middleware runs BEFORE routing — so `$request->route()` is null and any route-NAME-based exemption
| list silently exempts nothing. The first casualty would be `/admin/*`: the operator who switched
| maintenance on would be locked out of the only page that can switch it off, and the only way back would
| be a database edit. Every exemption below is asserted for exactly that reason.
|
| The platform row is written on the privileged connection because it must be visible to the app
| connection's own reads inside the request; it is cleaned in both hooks.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PlatformSettings::class)->forget();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function pausePlatform(string $message = 'Back at 03:00 UTC.'): void
{
    foreach (['maintenance.enabled' => true, 'maintenance.message' => $message] as $key => $value) {
        DB::connection('pgsql_privileged')->table('settings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'tenant_id' => null,
            'key' => $key,
            'value' => json_encode($value),
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    app(PlatformSettings::class)->forget();
}

it('503s the tenant app with the operator\'s message', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    pausePlatform();
    $this->withoutVite();

    $this->actingAs($owner)->get('http://acme.meridian.test/dashboard')
        ->assertStatus(503)
        ->assertSee('Back at 03:00 UTC.');
});

it('keeps the admin console reachable — without this the operator is locked out', function (): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();
    pausePlatform();
    $this->withoutVite();

    $this->actingAs($admin)->get('http://meridian.test/admin/settings')->assertOk();
    $this->actingAs($admin)->get('http://meridian.test/admin/tenants')->assertOk();
});

it('keeps sign-in reachable, so an operator with an expired session can still get in', function (): void {
    pausePlatform();
    $this->withoutVite();

    $this->get('http://meridian.test/login')->assertOk();
});

it('keeps the health check reachable, so a balancer can tell "paused" from "dead"', function (): void {
    pausePlatform();

    $this->get('http://meridian.test/up')->assertOk();
});

it('answers the /api/v1 surface inside its own error envelope', function (): void {
    pausePlatform('Paused.');

    $this->getJson('http://acme.meridian.test/api/v1/forms')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'maintenance_mode')
        ->assertJsonPath('error.message', 'Paused.');
});

it('sends an Inertia XHR to a hard navigation rather than into its error modal', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    pausePlatform();

    // 409 + X-Inertia-Location is the protocol's own "leave the SPA" instruction. A plain 503 body here
    // would be rendered by Inertia's client as a debug modal on top of the app — see MaintenanceResponse.
    $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'x'])
        ->get('http://acme.meridian.test/dashboard')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://acme.meridian.test/dashboard');
});

it('serves everything normally when maintenance is off', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->withoutVite();

    $this->actingAs($owner)->get('http://acme.meridian.test/dashboard')->assertOk();
});
