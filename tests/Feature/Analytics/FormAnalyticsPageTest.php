<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionSource;
use App\Models\User;
use App\Services\Analytics\FormAnalyticsPresenter;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I10c — GET /forms/{form}/analytics, docs/PRD.md:198's Form Owner/Editor view.
|
| UNGATED by plan and scoped to ONE form. The refusals live in FormAnalyticsGateTest; this file pins the
| shape and the boundary — that the page counts only this form, and that it cannot become a second
| /analytics.
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

it('renders every prop the page binds to, key by key', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedCountableAt($form, CarbonImmutable::now()->subDays(2), source: SubmissionSource::Guest);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formAnalyticsUrl((string) $form->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('forms/Analytics', false)
            ->where('form.id', (string) $form->id)
            ->where('form.title', 'Clinic Intake')
            // The literals that ARE the gate boundary: no request input reaches any of them.
            ->where('report.range.granularity', 'day')
            ->where('report.range.timezone', 'UTC')
            ->where('report.breakdown.axis', 'source')
            ->where('report.breakdown.unassigned_label', 'Unassigned')
            ->where('report.breakdown.has_unassigned_bucket', false)
            // Zero-filled to one bucket per day, not just the populated ones.
            ->has('report.series', 30)
            ->has('report.total.current')
            ->has('report.total.prior')
            ->has('report.total.change')
            ->has('report.prior_range.from')
            ->has('report.prior_range.to')
            // All FIVE draft keys, not just `suppressed`: forms/Analytics.vue renders two of its three
            // tiles from conversionTile()/medianTile(), which read denominator, converted, conversion_rate
            // and median_seconds. Asserting only `suppressed` would let the two tiles PRD:198 explicitly
            // requires render NaN with this gate green.
            ->has('report.drafts.suppressed')
            ->has('report.drafts.reason')
            ->has('report.drafts.denominator')
            ->has('report.drafts.converted')
            ->has('report.drafts.conversion_rate')
            ->has('report.drafts.median_seconds')
            ->where('report.week_starts_on', 'monday'));
});

it('counts only THIS form, never the tenant', function (): void {
    $mine = publishedInboxForm($this->tenant, $this->owner, 'Mine');
    $other = publishedInboxForm($this->tenant, $this->owner, 'Other');

    seedCountableAt($mine, CarbonImmutable::now()->subDay(), source: SubmissionSource::Guest);
    seedCountableAt($other, CarbonImmutable::now()->subDay(), source: SubmissionSource::Manual);
    seedCountableAt($other, CarbonImmutable::now()->subDay(), source: SubmissionSource::Manual);

    $response = $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formAnalyticsUrl((string) $mine->id))
        ->assertOk()
        // Dropping `selection: Forms` / `formIds` would silently degrade the query to `All` and serve the
        // TENANT total under this form's title — a number that looks plausible and is wrong.
        ->assertInertia(fn ($page) => $page
            ->where('report.total.current', 1)
            ->has('report.breakdown.rows', 1)
            ->where('report.breakdown.rows.0.label', 'Guest link')
            ->where('report.drafts.denominator', 0));

    // The SERIES travels through the same AnalyticsQuery and must be scoped too, but it is asserted OUT
    // HERE rather than through `where()` with a closure: Inertia hands a Collection to that callback, not
    // an array, so a typed `array` parameter is a TypeError and an untyped one silently accepts anything.
    // `AnalyticsFormSelection::Forms` appears nowhere else in tests/ outside AnalyticsQuery's own
    // constructor-validation cases, so without this the form-selection path is unexercised for the series
    // and a regression would surface as a plausible wrong number rather than as a failure.
    $series = $response->viewData('page')['props']['report']['series'];

    expect(array_sum(array_column($series, 'count')))->toBe(1);
});

it('is reachable with advanced_analytics OFF, because PRD:198 is a Phase-1 criterion', function (): void {
    // assignPlanTier is load-bearing, not decoration: RequireFeature FAILS OPEN on a tenant with no plan, so
    // without it this would pass even if the route grew the gate.
    assignPlanTier(PlanTier::Free);

    $form = publishedInboxForm($this->tenant, $this->owner, 'Free Tier Form');
    seedCountableAt($form, CarbonImmutable::now()->subDay(), source: SubmissionSource::Guest);

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formAnalyticsUrl((string) $form->id))
        ->assertOk()
        // Real numbers on the lowest tier — not a 402, and not a null-shaped "upgrade to see this".
        ->assertInertia(fn ($page) => $page
            ->where('report.total.current', 1)
            ->where('report.breakdown.rows.0.label', 'Guest link'));
});

it('offers no axis picker, no saved views, no export and no question explorer', function (): void {
    // The gate boundary as a PROP assertion. FormAnalyticsPresenter takes one dependency, so every key below
    // is unreachable by construction; injecting AnalyticsPresenter to "reuse" it would reintroduce all of
    // them at once, and this is what would notice.
    $form = publishedInboxForm($this->tenant, $this->owner, 'Bounded');
    seedCountableAt($form, CarbonImmutable::now()->subDay());

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formAnalyticsUrl((string) $form->id))
        ->assertOk()
        // An ALLOWLIST, not a blocklist. Enumerating the keys AnalyticsPresenter happens to emit today
        // would let any NEWLY-named prop through the very boundary this case exists to hold — and mechanism
        // (1) is that adding a dependency is supposed to be visible. Asserting the prop set EQUALS
        // {form, report} is what makes that true rather than aspirational.
        ->assertInertia(fn ($page) => $page->has('form')->has('report')->etc());
});

it('ignores query parameters that would widen it', function (): void {
    // There is no FormRequest and no query read, so this GET must produce byte-identical output to a bare
    // one. Swapping the controller's `Request` for an AnalyticsFilterRequest is what this catches.
    $mine = publishedInboxForm($this->tenant, $this->owner, 'Mine');
    $other = publishedInboxForm($this->tenant, $this->owner, 'Other');
    seedCountableAt($mine, CarbonImmutable::now()->subDay(), source: SubmissionSource::Guest);
    seedCountableAt($other, CarbonImmutable::now()->subDay(), source: SubmissionSource::Manual);

    // Every knob AnalyticsQuery carries, including the three FILTER arrays. `sources[]` is the dangerous
    // one on a page whose axis IS source: plumbing it in would silently turn the breakdown into a subset
    // while every other assertion here stayed green.
    $widening = '?axis=scope_node&granularity=month&from=2020-01-01&to=2026-12-31'
        .'&selection=all&form_ids[]='.$other->id.'&top_n=20&timezone=Asia/Manila'
        .'&sources[]=manual&statuses[]=approved&locales[]=fil';

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get(formAnalyticsUrl((string) $mine->id).$widening)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.breakdown.axis', 'source')
            ->where('report.range.granularity', 'day')
            ->where('report.range.timezone', 'UTC')
            ->has('report.series', 30)
            ->where('report.total.current', 1));
});

it('zeroes the aggregates rather than leaking them, even with the policy bypassed', function (): void {
    // The only test IN THIS FILE that proves AnalyticsFormSet is a real second layer rather than decoration:
    // it calls the presenter DIRECTLY, so `can:view,form` never runs, and replacing resolve() with a bare
    // `where('form_id', $form->id)` would pass everything else here and fail only this. (Service-level twins
    // live in AnalyticsMetricsServiceTest and DashboardMetricsServiceTest; what this adds is the
    // FORM-SELECTION path, which neither of those exercises.)
    $form = publishedInboxForm($this->tenant, $this->owner, 'Not Yours');
    seedCountableAt($form, CarbonImmutable::now()->subDay(), source: SubmissionSource::Guest);

    $stranger = User::factory()->create();
    makeActiveMember($stranger, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $bag = app(FormAnalyticsPresenter::class)->show($form, $stranger);

    expect($bag['report']['total']['current'])->toBe(0)
        ->and($bag['report']['breakdown']['rows'])->toBe([])
        ->and(array_sum(array_column($bag['report']['series'], 'count')))->toBe(0);
});
