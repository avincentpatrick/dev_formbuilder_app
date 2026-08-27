<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\UsageMetric;
use App\Models\User;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H24b2 — the WEB export route, over the same AnalyticsExporter the /api/v1 twin streams (§D13).
|
| ASSERT ON ROWS, NEVER ON STATUS. The stream closure fires during Response::send(), AFTER
| EstablishTenantDatabaseContext::terminate() has torn the GUC down — so a missing applyLocal() produces an
| EMPTY FILE AT HTTP 200, which every status-only test passes. That is the doctrine AnalyticsExportTest
| records, and it binds here for the same reason: this route is a second caller of the same closure.
|
| Deliberately NOT a second copy of the API export suite. The exporter is shared and already pinned; what
| is new is the route, its gates, and the refusal arm an exporter cannot render into a page.
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
    assignPlanTier(PlanTier::Business);

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedCountableAt($this->form, CarbonImmutable::now()->subDays(3));
    seedCountableAt($this->form, CarbonImmutable::now()->subDays(2), SubmissionStatus::Submitted, SubmissionSource::Manual);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function webExportUrl(array $params = []): string
{
    return 'http://acme.meridian.test/analytics/export'.($params === [] ? '' : '?'.http_build_query($params));
}

/** Parse openspout CSV, stripping its UTF-8 BOM. @return list<list<string>> */
function webExportRows(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_values(array_filter(array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        explode("\n", trim($csv))
    )));
}

it('streams a NON-EMPTY body, which is the only assertion that catches the context teardown', function (): void {
    $response = $this->actingAs($this->owner)->get(webExportUrl());

    $response->assertOk();

    $rows = webExportRows($response->streamedContent());
    $flat = array_map(static fn (array $r): string => implode('|', $r), $rows);

    expect($rows[0])->toBe(['Section', 'Key', 'Value']);
    // Header-only — or zero bytes — is exactly what a missing applyLocal() produces, at HTTP 200.
    expect(count($rows))->toBeGreaterThan(1);
    expect($flat)->toContain('Total|Current period|2');
    // The un-collapsed breakdown: §D13 keeps Unassigned in the file even at zero, so the export and the
    // page's data table can never disagree about what the total is made of.
    expect($flat)->toContain('Breakdown|Unassigned|0');
});

it('meters the export BEFORE the stream, not on completion', function (): void {
    // Asserting WITHOUT touching streamedContent() is what proves the ordering — a counter incremented in
    // the closure would still be zero here.
    $this->actingAs($this->owner)->get(webExportUrl())->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(DB::table('usage_counters')->where('metric', UsageMetric::ExportsCount->value)->sum('value'))
        ->toBeGreaterThan(0);
});

it('names the file for the range it actually exported', function (): void {
    $from = CarbonImmutable::now()->subDays(6)->toDateString();
    $to = CarbonImmutable::now()->toDateString();

    $this->actingAs($this->owner)
        ->get(webExportUrl(['from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertHeader('content-disposition', "attachment; filename=analytics-{$from}-to-{$to}.csv");
});

it('honours the xlsx format on the web route too', function (): void {
    $this->actingAs($this->owner)
        ->get(webExportUrl(['format' => 'xlsx']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('applies the page’s filters to the file, so what is downloaded is what is on screen', function (): void {
    $response = $this->actingAs($this->owner)->get(webExportUrl(['sources' => ['manual']]));

    $response->assertOk();

    $flat = array_map(
        static fn (array $r): string => implode('|', $r),
        webExportRows($response->streamedContent()),
    );

    expect($flat)->toContain('Total|Current period|1');
});

it('refuses rather than 404s when the selection names a deleted area', function (): void {
    // The one guard the controller keeps. An exporter has no page to render a refusal into, and streaming a
    // file whose scope-node selection cannot be resolved would 404 mid-download.
    $node = makeScopeNode(null, 'Region I');
    $missingId = (string) $node->id;
    $node->forceDelete();

    $this->actingAs($this->owner)
        ->get(webExportUrl(['selection' => 'scope_node', 'scope_node_id' => $missingId]))
        ->assertRedirect()
        ->assertSessionHas('toast');
});

it('defaults the export window to the same range the page opens on', function (): void {
    // A download that covered a different period from the screen it was launched from would be the worst
    // kind of wrong: plausible, and only detectable by counting.
    $to = CarbonImmutable::now()->toDateString();
    $from = CarbonImmutable::now()->subDays(AnalyticsQuery::DEFAULT_RANGE_DAYS)->toDateString();

    $this->actingAs($this->owner)
        ->get(webExportUrl())
        ->assertOk()
        ->assertHeader('content-disposition', "attachment; filename=analytics-{$from}-to-{$to}.csv");
});

/*
|--------------------------------------------------------------------------
| M34 — the authorization gate on this route, which nothing asserted until now.
|
| Nine requests in THIS file already drove /analytics/export; not one of them was about its GATE. The only
| assertion anywhere that touched the gate's own arm is AnalyticsPageGateTest.php:115, which drives the route
| as an OWNER on a Professional plan and asserts a REDIRECT — `feature:advanced_analytics` answering, with the
| Owner passing `can:` on the way to it. Deleting `can:viewAny,SavedReportView` from routes/tenant.php:894
| left the whole suite green; M34's mutation harness proved exactly that, and reddened only the case below.
|--------------------------------------------------------------------------
*/

it('403s the export for a member holding neither dashboard permission', function (): void {
    // WHY THIS IS A PERMISSION ASSERTION AND NOT AN ENTITLEMENT ONE, which is the trap the row this closes
    // was filed about: `route:list` resolves this route as `Authorize:viewAny,SavedReportView` BEFORE
    // `RequireFeature:advanced_analytics` — measured, not assumed. beforeEach() already assigns Business, so
    // the feature gate cannot answer, and the only middleware left that can produce a 403 is the `can:` one.
    //
    // A role-less active member is the ONLY construction that reaches that arm. Every seeded role holds at
    // least `dashboard.form.view` and SavedReportViewPolicy::viewAny is
    // `dashboard.org.view || dashboard.form.view`; AnalyticsPageGateTest.php:69-72 records the same
    // reasoning for the /analytics page, and this is that argument carried to the route that streams the
    // numbers rather than renders them.
    //
    // POSITIVE CONTROL: the Owner's 200 on this exact URI is this file's first test (:71), which asserts
    // ROWS rather than status per the header doctrine above — so this 403 cannot be a route refusing
    // everybody.
    //
    // THERE IS DELIBERATELY NO SCOPE-DENIAL HALF, and that is a correction to the row rather than an
    // omission. GET /forms/{form}/submissions/export can assert one because `can:view,form` binds an
    // instance; this gate is class-level `viewAny` with no instance to be out of scope of. The per-form
    // narrowing for analytics is AnalyticsFormSet's, applied to the ROWS inside the exporter, and it is
    // pinned as a layer by FormAnalyticsPageTest.php:177 and DashboardMetricsServiceTest.php:154-172.
    $nobody = User::factory()->create();
    enterTenant($this->tenant->id, $nobody->id);
    makeActiveMember($nobody, 'viewer');
    $nobody->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($nobody)->get(webExportUrl())->assertForbidden();
});
