<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\LegacyOverride;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The plan feature-gate (H5c / ADR-0008 §D5) on the tenant WEB surface — the RequireFeature middleware plus
// the 402 web render (a redirect-back with an upgrade toast). A Free tenant is blocked; Starter passes; a
// grandfathered Free tenant (legacy override) passes — the zero-merge-day-regression acceptance, at the HTTP
// level. The middleware is shared with the /api/v1 surface (exercised in the Api gate tests).

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** An acme tenant with an active admin, on `$tier` (Free unless overridden). Context left on the tenant. */
function featureGateTenant(PlanTier $tier = PlanTier::Free): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    assignPlanTier($tier);

    return [$tenant, $admin];
}

it('redirects a Free tenant away from the template gallery with an upgrade toast', function (): void {
    $this->withoutVite();
    [, $admin] = featureGateTenant(PlanTier::Free);

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertRedirect()
        ->assertSessionHas('toast');
});

it('lets a Starter tenant into the template gallery', function (): void {
    $this->withoutVite();
    [, $admin] = featureGateTenant(PlanTier::Starter);

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertOk();
});

it('grandfathers a Free tenant into the template gallery via a legacy override', function (): void {
    $this->withoutVite();
    [, $admin] = featureGateTenant(PlanTier::Free);
    LegacyOverride::create(['feature_flags' => ['form_templates' => true]]);
    app(EntitlementService::class)->forget();

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertOk();
});

it('blocks a Free tenant from the XLSForm export and library routes, but not a Starter tenant', function (): void {
    // Build a published form so the export route resolves a real {form}/{version}; the gate is what we assert.
    [$tenant, $admin] = featureGateTenant(PlanTier::Free);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    enterTenant($tenant->id, $admin->id);
    $draftId = $form->draft_version_id;

    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/versions/{$draftId}/xlsform")
        ->assertRedirect(); // feature:xlsform_export → 402 web render → back()

    // Free → field_library also gated (the library picker refetch route).
    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/library-items")
        ->assertRedirect();
});

it('lets a Starter tenant reach the XLSForm export and library routes', function (): void {
    [$tenant, $admin] = featureGateTenant(PlanTier::Starter);
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    enterTenant($tenant->id, $admin->id);
    $draftId = $form->draft_version_id;

    // Not a 402 redirect — the export streams (200) and the library list returns JSON (200).
    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/versions/{$draftId}/xlsform")
        ->assertOk();
    $this->actingAs($admin)
        ->get("http://acme.meridian.test/forms/{$form->id}/library-items")
        ->assertOk();
});
