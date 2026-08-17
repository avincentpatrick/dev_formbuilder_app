<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Entitlements\ToggleableModules;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H11 — the dashboard page renders real KPI props from DashboardController → DashboardMetricsService,
| visibility-scoped (org-wide vs own-forms) with the Members tile withheld from form-scoped roles.
|--------------------------------------------------------------------------
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

it('renders org-wide KPI props for a Viewer', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->where('kpis.forms', 2)
            ->where('kpis.submissions', 2)
            ->where('kpis.members', 2));
});

it('omits the Members KPI (null) for a form-scoped role', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    $mine = publishedInboxForm($tenant, $editor, 'Mine');
    makeActiveMember($editor, 'form_editor');
    seedInboxSubmission($mine, $editor, SubmissionStatus::Submitted, ['full_name' => 'Mine']);

    $this->actingAs($editor)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->where('kpis.forms', 1)
            ->where('kpis.submissions', 1)
            ->where('kpis.members', null));
});

// H24a — the `trends` prop is inert until H24b renders it, which is exactly why it needs pinning HERE and
// not only at the service layer: DashboardMetricsServiceTest proves the numbers, this proves they reach
// the page. Nothing in `resources/js` reads `trends` yet, so a controller-side regression would otherwise
// be invisible to every gate until the increment that depends on it.
//
// The tenant carries no subscription, so this also holds the ADR-0011 §D5 line that the four PRD.md:197
// criteria are ungated Phase-1 acceptance criteria — they ship on FREE, and `advanced_analytics` gates
// only the genuinely Phase-3 surface on /api/v1.
it('serves the ungated trend props alongside the KPI tiles', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Trend Form');
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->has('kpis')
            // The whole contract H24b will bind to, key by key — a partial `has('trends')` would pass
            // against a prop that had silently lost half its shape.
            ->where('trends.range.timezone', 'UTC')
            ->has('trends.range.from')
            ->has('trends.range.to')
            ->has('trends.total.current')
            ->has('trends.total.prior')
            ->has('trends.total.change')
            // Zero-filled to one bucket per day across the 30-day window, not just the populated ones.
            ->has('trends.series', 30)
            ->has('trends.top_forms.rows')
            ->has('trends.top_forms.unassigned')
            // I10c — docs/PRD.md:198's channel breakdown, shaped identically to top_forms so the page reads
            // both through one client-side builder.
            ->has('trends.channels.rows')
            ->has('trends.channels.other')
            ->has('trends.channels.unassigned')
            ->has('trends.forms_accepting')
            ->has('trends.drafts'));
});

it('serves the channel breakdown on a FREE plan, because PRD:198 is a Phase-1 criterion', function (): void {
    // assignPlanTier is not decoration: RequireFeature FAILS OPEN on a tenant with no plan, so a test that
    // skipped it would pass even if the route grew a `feature:advanced_analytics` middleware.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    assignPlanTier(PlanTier::Free);

    $form = publishedInboxForm($tenant, $owner, 'Channel Form');
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            // A real label on the lowest tier — not a 402, and not a null-shaped "upgrade to see this".
            ->has('trends.channels.rows.0.label')
            ->has('trends.channels.rows.0.count'));
});

// H24b1 — the aggregate row carries a form UUID and nothing else, so the page had no name to put on a
// bar. The title is resolved in DashboardMetricsService rather than in AnalyticsMetricsService::breakdown(),
// whose shape is the /api/v1 response byte-diffed against openapi.json; pinning it here is what keeps the
// two from being "fixed" in the wrong file later.
it('labels each top-forms row with its title, so a bar is never a bare uuid', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Clinic Intake');
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('trends.top_forms.rows', 1)
            ->where('trends.top_forms.rows.0.key', (string) $form->id)
            ->where('trends.top_forms.rows.0.label', 'Clinic Intake')
            ->where('trends.top_forms.rows.0.count', 1));
});

/*
|--------------------------------------------------------------------------
| J5c — onboarding §2's two first-run choices, resolved SERVER-side.
|--------------------------------------------------------------------------
| The template arm is a paid destination (`forms.templates.index` carries `feature:form_templates` on top
| of `can:create,Form`), so which choices a reader may be offered is an entitlement question — and the
| client cannot answer it correctly, which is the whole reason this prop exists. See the null-plan case.
*/

it('offers both first-run choices on a plan that includes templates', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    assignPlanTier(PlanTier::Starter);

    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('start.can_create', true)
            ->where('start.can_use_templates', true));
});

it('withholds the template choice on Free, where the gallery is not included', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    assignPlanTier(PlanTier::Free);

    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('start.can_create', true)
            ->where('start.can_use_templates', false));
});

it('offers the template choice when there is NO plan catalog, exactly as the route would', function (): void {
    // ⭐ THE CASE THAT WOULD REGRESS SILENTLY, AND THE REASON `FeatureAdmission` WAS EXTRACTED IN J5a.
    // `feature:` middleware ADMITS a request when there is no plan at all — its own docblock calls that a
    // "dev/test has no plans" pass-through — while `EntitlementService::feature()` returns FALSE in the
    // same state, because `currentPlan()?->featureEnabled() ?? false` cannot tell *denied* from *nothing to
    // ask*. So a prop built on `feature()` would hide a card the server would happily have served: J4b2's
    // stranded-reader defect, one surface over. Reachable on a deploy that migrates before it seeds, on a
    // restored database, and in every feature test that seeds no plan.
    //
    // ⚠️ NOTE WHAT THIS TEST OMITS ON PURPOSE: no `assignPlanTier`. Adding one would make it pass against
    // the broken implementation too.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('start.can_use_templates', true));

    // And the mirror it is a mirror OF: the gallery really does answer on the same unseeded catalog.
    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertOk();
});

it('reads the form_templates key specifically, not merely "some Starter feature"', function (): void {
    /*
     * ⭐ THE COPY-PASTE CASE, AND THE MUTATION PASS IS WHY IT EXISTS. Swapping `form_templates` for
     * `field_library` in the controller SURVIVED every other case in this file, because every seeded plan
     * that grants one grants the other — so nothing here discriminated the key, only whether the tier was
     * Starter. That is J4b2's recorded hole verbatim: it varied the OPERATOR and never the OPERANDS.
     *
     * The module toggle is the one seam in the product that separates two keys on one tier: it is ANDed
     * with the plan flag inside `feature()` and can only ever subtract. `CrumbTrailGateTest` pins the same
     * property for `webhooks` vs `native_connectors` the same way.
     */
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    assignPlanTier(PlanTier::Starter);

    app(TenantSettingRegistry::class)->put($tenant, [
        ToggleableModules::settingKey('form_templates') => false,
    ]);
    app(EntitlementService::class)->forget();

    expect(app(EntitlementService::class)->feature('form_templates'))->toBeFalse()
        ->and(app(EntitlementService::class)->feature('field_library'))->toBeTrue();

    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('start.can_create', true)
            ->where('start.can_use_templates', false));
});

it('offers neither choice to a role that cannot author, and still renders the page', function (): void {
    // A Viewer lands on the dashboard like everyone else. The page must degrade to an explanation rather
    // than a button that 403s — §3.10's extended governing rule, held at the prop layer here and at the
    // markup layer in `dashboard.test.ts`.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    assignPlanTier(PlanTier::Starter);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->withoutVite()
        ->actingAs($viewer)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('start.can_create', false)
            // ⭐ ANDed with the policy, not read alone: an entitled plan does not make a Viewer an author,
            // and `/forms/templates` carries BOTH gates.
            ->where('start.can_use_templates', false));
});

it('serves the getting-started checklist to the page, and null once it is done', function (): void {
    // The rows themselves are `GettingStartedChecklistTest`'s subject. What is pinned here is that they
    // reach the page at all — the H24a lesson: a prop nothing renders yet is exactly the prop that gets
    // dropped by a later controller edit with no test to notice.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    app(FormService::class)->create($tenant, $owner, 'Draft');

    $this->withoutVite()
        ->actingAs($owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('checklist', 4)
            ->where('checklist.0.key', 'create_form')
            ->where('checklist.0.done', true));
});
