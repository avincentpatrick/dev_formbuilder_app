<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\User;
use App\Services\Analytics\SavedReportViewService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H24b2 — the two gates on /analytics, and the exact shape each refusal takes on a WEB surface.
|
| `can:viewAny,SavedReportView` + `feature:advanced_analytics`, mirroring the /api/v1 twins MINUS
| `ability:` — Sanctum token-scope middleware a session request cannot carry.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function gateUrl(string $path = 'analytics'): string
{
    return 'http://acme.meridian.test/'.$path;
}

it('lets a Business-entitled Owner in', function (): void {
    $this->withoutVite();
    assignPlanTier(PlanTier::Business);

    $this->actingAs($this->owner)->get(gateUrl())->assertOk();
});

it('lets a Reviewer in, because dashboard.form.view is enough — and that is intended', function (): void {
    // SavedReportViewPolicy::viewAny is `dashboard.org.view || dashboard.form.view`. A Reviewer holds the
    // second, so they reach the page — and AnalyticsFormSet then scopes every number to the forms they hold
    // a grant on, which is the same boundary their inbox already uses. Pinned so nobody "tightens" the gate
    // to org-wide and silently removes a surface a Reviewer is meant to have.
    $this->withoutVite();
    assignPlanTier(PlanTier::Business);

    $reviewer = User::factory()->create();
    enterTenant($this->tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');

    $this->actingAs($reviewer)->get(gateUrl())->assertOk();
});

it('403s a member holding neither dashboard permission', function (): void {
    // EVERY seeded role holds at least `dashboard.form.view`, so a role-less active member is the ONLY
    // construction that exercises the `can:` arm at all. Without this test that middleware could be deleted
    // and every other gate here would stay green.
    $this->withoutVite();
    assignPlanTier(PlanTier::Business);

    $nobody = User::factory()->create();
    enterTenant($this->tenant->id, $nobody->id);
    makeActiveMember($nobody, 'viewer');
    $nobody->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($nobody)->get(gateUrl())->assertForbidden();
});

it('redirects a Professional tenant with an upgrade toast, NOT a 402', function (): void {
    // `bootstrap/app.php` keys its JSON branch on the `api/v1/*` PATH, so a `feature:` refusal on a web
    // route takes the `back()->with('toast')` arm. Asserting 402 here would assert the API's behaviour on a
    // surface that does not have it.
    $this->withoutVite();
    assignPlanTier(PlanTier::Professional);

    $this->actingAs($this->owner)
        ->get(gateUrl())
        ->assertRedirect()
        ->assertSessionHas('toast');
});

it('passes the feature gate VACUOUSLY with no plan at all, which is why every gate test seeds one', function (): void {
    // RequireFeature FAILS OPEN when currentPlan() is null ("no catalog to gate against"). Stated as its own
    // test so a future gate test that forgets assignPlanTier() cannot quietly prove nothing.
    $this->withoutVite();

    $this->actingAs($this->owner)->get(gateUrl())->assertOk();
});

it('gates the export and all three saved-view verbs on the same feature', function (): void {
    $this->withoutVite();
    assignPlanTier(PlanTier::Professional);

    $this->actingAs($this->owner)->get(gateUrl('analytics/export'))->assertRedirect();
    $this->actingAs($this->owner)->post(gateUrl('analytics/views'), ['name' => 'X'])->assertRedirect();

    // The tenant GUC is torn down after every HTTP request, so any write from here on needs it back — a
    // row-invisible insert reads as an RLS violation, not as a test bug.
    enterTenant($this->tenant->id, $this->owner->id);
    assignPlanTier(PlanTier::Business);
    $view = app(SavedReportViewService::class)->create(
        $this->owner,
        'Mine',
        (new AnalyticsQuery(
            from: CarbonImmutable::now()->subDays(5),
            to: CarbonImmutable::now(),
        ))->toArray(),
    );

    enterTenant($this->tenant->id, $this->owner->id);
    assignPlanTier(PlanTier::Professional);
    $this->actingAs($this->owner)->patch(gateUrl("analytics/views/{$view->id}"), ['name' => 'Y'])->assertRedirect();
    $this->actingAs($this->owner)->delete(gateUrl("analytics/views/{$view->id}"))->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);
    expect($view->refresh()->name)->toBe('Mine'); // neither verb reached the service
});

it('shares auth.can.viewAnalytics, and withholds it fail-closed from a member with neither permission', function (): void {
    // Asserted on the SHARE OUTPUT rather than on the model, and the negative half is read off /dashboard —
    // which is ungated, so a user who would 403 on /analytics can still be observed being told the
    // destination is not theirs. That is the whole contract the nav item and the switcher consume.
    $this->withoutVite();
    assignPlanTier(PlanTier::Business);

    $this->actingAs($this->owner)
        ->get(gateUrl())
        ->assertInertia(fn ($page) => $page->where('auth.can.viewAnalytics', true));

    enterTenant($this->tenant->id, $this->owner->id);
    $nobody = User::factory()->create();
    enterTenant($this->tenant->id, $nobody->id);
    makeActiveMember($nobody, 'viewer');
    $nobody->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($nobody)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can.viewAnalytics', false));
});

it('exposes advanced_analytics on Business and withholds it on Professional', function (): void {
    // The server half of the hidden-surface decision (§D9). The component half — that the switcher and the
    // nav item render NOTHING rather than a locked control — is Vitest's.
    $this->withoutVite();
    assignPlanTier(PlanTier::Business);

    $this->actingAs($this->owner)
        ->get(gateUrl())
        ->assertInertia(fn ($page) => $page->where('entitlements.features.advanced_analytics', true));

    enterTenant($this->tenant->id, $this->owner->id);
    assignPlanTier(PlanTier::Professional);

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertInertia(fn ($page) => $page->where('entitlements.features.advanced_analytics', false));
});
