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
