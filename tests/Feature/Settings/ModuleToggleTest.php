<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Module toggles as the THIRD layer of EntitlementService::feature() (Increment I5, PRD Feature #10).
|
| PRD #10 requires the module panel to reuse "the same capability-flag mechanism… not a second flagging
| system", which is why the toggle composes INTO feature() rather than sitting beside the surfaces it
| governs — every existing `feature:<key>` route gate then honours it with no further change. The
| composition is one-directional AND, and the second test is the one that matters: a tenant may silence
| what its plan grants, and may never grant itself what its plan denies.
|
| A plan MUST be seeded here. RequireFeature passes through when currentPlan() is null (the "no catalog ⇒
| no enforcement" stance), so a test written without assignPlanTier() would pass whatever the toggle did.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** Re-resolve both scoped services, as a fresh request would. */
function forgetEntitlementMemos(string $tenantId): void
{
    app(TenantSettingRegistry::class)->forget();
    app(EntitlementService::class)->forget($tenantId);
}

it('subtracts a tenant-disabled module from a plan-granted feature', function (): void {
    assignPlanTier(PlanTier::Business);

    expect(app(EntitlementService::class)->feature('webhooks'))->toBeTrue();

    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.webhooks' => false], $this->owner);
    forgetEntitlementMemos($this->tenant->id);

    expect(app(EntitlementService::class)->feature('webhooks'))->toBeFalse();
    // The shared Inertia prop is built by snapshot(), which calls feature() per key — so the whole app
    // gates on the composed answer with no other change. That is the point of composing here.
    expect(app(EntitlementService::class)->snapshot()['features']['webhooks'])->toBeFalse();
});

it('never lets a tenant enable a module its plan denies', function (): void {
    assignPlanTier(PlanTier::Free);

    expect(app(EntitlementService::class)->feature('ocr_single'))->toBeFalse();

    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => true], $this->owner);
    forgetEntitlementMemos($this->tenant->id);

    // The AND is one-directional by construction: a false from the plan is returned before the toggle is
    // even consulted. An Owner writing their own settings row cannot become an escalation path.
    expect(app(EntitlementService::class)->feature('ocr_single'))->toBeFalse();
});

it('leaves a feature outside the toggleable set untouched by a settings row', function (): void {
    assignPlanTier(PlanTier::Business);

    // `custom_domain` is deliberately NOT in ToggleableModules::KEYS (ADR-0012 §D9 — switching it off
    // would hide the escape hatch while a hostname is still resolving). A hand-crafted row must not work.
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.custom_domain' => false], $this->owner);
    forgetEntitlementMemos($this->tenant->id);

    expect(app(EntitlementService::class)->feature('custom_domain'))->toBeTrue();
});

it('402s a gated route once the module is switched off, exactly as a plan denial does', function (): void {
    assignPlanTier(PlanTier::Business);

    $this->withoutVite();
    $this->actingAs($this->owner)->get('http://acme.meridian.test/webhooks')->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.webhooks' => false], $this->owner);

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/webhooks')
        ->assertRedirect(); // FeatureGateException → back()-with-toast on the web surface
});

it('does not let a PLATFORM modules row disable a module for every tenant', function (): void {
    assignPlanTier(PlanTier::Business);

    // The whereNotNull('tenant_id') guard in TenantSettingRegistry::all(). Without it, nullable_global's
    // widened SELECT would hand this row to every tenant as if they had written it themselves.
    DB::connection('pgsql_privileged')->table('settings')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => null,
        'key' => 'modules.webhooks',
        'value' => json_encode(false),
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    forgetEntitlementMemos($this->tenant->id);

    expect(app(EntitlementService::class)->feature('webhooks'))->toBeTrue();

    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
});
