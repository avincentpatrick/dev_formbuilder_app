<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The /settings signpost to /settings/sso (P1a, ADR-0016 §D6).
|
| ⚠️ THIS FILE EXISTS FOR ONE CONDITION, AND IT IS THE ONE THING THAT DIFFERS FROM THE CUSTOM-DOMAINS CARD
| IT IS OTHERWISE A COPY OF. That card renders on `count > 0`; this one renders on **entitled OR
| configured**. The difference is not cosmetic: /domains also has a sidebar entry, so its card only has to
| cover the downgrade case, whereas SSO has NO nav entry at all (an Enterprise-gated row would be invisible
| in every environment, since Enterprise is seeded is_active:false) — so this card is the only way in and
| must also cover the entitled-but-empty case. Narrow it to `configured` and an entitled tenant can never
| reach the page to configure anything.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');

    $this->viewer = User::factory()->create();
    makeActiveMember($this->viewer, 'viewer');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function settingsPage(): string
{
    return 'http://acme.meridian.test/settings';
}

it('links the page for an entitled tenant that has configured nothing yet', function (): void {
    // The case the custom-domains card's `count > 0` cannot express, and without which SSO is unreachable.
    $this->withoutVite();
    assignPlanTier(PlanTier::Enterprise);

    $this->actingAs($this->admin)
        ->get(settingsPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sso.visible', true)
            ->where('sso.configured', false)
            ->where('sso.entitled', true));
});

it('keeps linking the page for a downgraded tenant that still has a connection', function (): void {
    // The escape hatch: they must be able to reach the page to switch it off or remove it.
    $this->withoutVite();
    assignPlanTier(PlanTier::Enterprise);
    SsoConnection::factory()->active()->create();

    assignPlanTier(PlanTier::Business);
    app()->forgetInstance(EntitlementService::class);
    enterTenant($this->tenant->id, $this->admin->id);

    $this->actingAs($this->admin)
        ->get(settingsPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sso.visible', true)
            ->where('sso.configured', true)
            ->where('sso.entitled', false)
            ->where('sso.status', 'active'));
});

it('shows nothing to a tenant that is neither entitled nor configured', function (): void {
    // And this is what keeps the card from advertising a plan nobody can buy (Enterprise is is_active:false).
    $this->withoutVite();
    assignPlanTier(PlanTier::Business);

    $this->actingAs($this->admin)
        ->get(settingsPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sso.visible', false)
            ->where('sso.configured', false));
});

it('shows nothing to a member who could not act on it anyway', function (): void {
    // And the presenter returns before touching sso_connections at all, rather than reading a row it will
    // then decline to render.
    $this->withoutVite();
    assignPlanTier(PlanTier::Enterprise);
    SsoConnection::factory()->active()->create();
    enterTenant($this->tenant->id, $this->viewer->id);

    $this->actingAs($this->viewer)
        ->get(settingsPage())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sso.visible', false)
            ->where('sso.entitled', false));
});
