<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Models\User;
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
