<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Enums\PlanTier;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Dashboard\DashboardMetricsService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H11 — the dashboard KPI aggregator. Live COUNT-under-RLS, visibility-scoped: an org viewer
| (dashboard.org.view) gets tenant-wide totals + a Members count; a Form Editor/Reviewer gets
| own-forms counts and a null Members tile. Archived forms and draft submissions are excluded.
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

it('gives an org-wide viewer tenant-wide counts, excluding archived forms and draft submissions', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    $retired = publishedInboxForm($tenant, $owner, 'Retired');
    $retired->update(['status' => FormStatus::Archived]); // excluded from the forms count

    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);
    seedInboxSubmission($formA, $owner, SubmissionStatus::Draft, ['full_name' => 'Partial']); // excluded

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // holds dashboard.org.view

    $kpis = app(DashboardMetricsService::class)->forUser($viewer);

    expect($kpis['forms'])->toBe(2)          // two active; the archived one excluded
        ->and($kpis['submissions'])->toBe(2) // two submitted; the draft excluded
        ->and($kpis['members'])->toBe(2);    // owner + viewer, both active
});

it('scopes a Form Editor to their own forms and withholds the Members count', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    $mine = publishedInboxForm($tenant, $editor, 'Mine'); // creator → editor grant
    makeActiveMember($editor, 'form_editor');

    enterTenant($tenant->id, $owner->id);
    $theirs = publishedInboxForm($tenant, $owner, 'Theirs'); // editor holds no grant
    seedInboxSubmission($mine, $editor, SubmissionStatus::Submitted, ['full_name' => 'Mine']);
    seedInboxSubmission($theirs, $owner, SubmissionStatus::Submitted, ['full_name' => 'Theirs']);
    seedInboxSubmission($mine, $editor, SubmissionStatus::Draft, ['full_name' => 'Partial']); // excluded

    enterTenant($tenant->id, $editor->id); // run the aggregator AS the editor
    $kpis = app(DashboardMetricsService::class)->forUser($editor);

    expect($kpis['forms'])->toBe(1)          // only 'Mine'
        ->and($kpis['submissions'])->toBe(1) // only Mine's submitted; 'Theirs' hidden, draft excluded
        ->and($kpis['members'])->toBeNull(); // no dashboard.org.view
});

/*
|--------------------------------------------------------------------------
| H24a -- the four docs/PRD.md:197 criteria H11 left "partially shipped".
|
| UNGATED, for every tier. These are Phase-1 acceptance criteria, and the Phase-3 decomposition's own
| "H11 vs H24" note unbundles the basic dashboard from the advanced_analytics gate. What that gate covers is
| the Phase-3 surface: arbitrary cross-form grouping, saved views, answer-value aggregation, the export.
*/

/** @return array{owner: User, tenant: Tenant} */
function trendFixture(): array
{
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Intake');
    seedCountableAt($form, CarbonImmutable::now()->subDays(2));

    return ['owner' => $owner, 'tenant' => $tenant];
}

it('serves the trend props on a FREE plan, because they are Phase-1 criteria', function (): void {
    ['owner' => $owner] = trendFixture();
    assignPlanTier(PlanTier::Free);

    $trends = app(DashboardMetricsService::class)->trendsForUser($owner);

    // No 402, no null-shaped "upgrade to see this" -- real numbers on the lowest tier.
    expect($trends['total'])->toHaveKeys(['current', 'prior', 'change'])
        ->and($trends['total']['current'])->toBe(1)
        ->and($trends['top_forms'])->toHaveKeys(['rows', 'other', 'unassigned'])
        ->and($trends['drafts'])->toHaveKey('suppressed')
        ->and($trends['forms_accepting'])->toBe(1);
});

it('states the range it covers, so a tile cannot be labelled as an all-time total', function (): void {
    ['owner' => $owner] = trendFixture();

    $trends = app(DashboardMetricsService::class)->trendsForUser($owner);

    // The window is echoed rather than implied -- "last 30 days" is a different claim from "total", and the
    // bucketing timezone is visible rather than assumed to be the server's.
    expect($trends['range']['timezone'])->toBe('UTC')
        ->and($trends['series'])->toHaveCount(30)
        ->and($trends['range']['from'])->toBe(CarbonImmutable::now()->subDays(29)->toDateString())
        ->and($trends['range']['to'])->toBe(CarbonImmutable::now()->toDateString());
});

// H24b1 -- the aggregate row is a form UUID and a count; the page needs a name for the bar.
it('labels every top-forms row with its title', function (): void {
    ['owner' => $owner] = trendFixture();

    $trends = app(DashboardMetricsService::class)->trendsForUser($owner);

    expect($trends['top_forms']['rows'])->toHaveCount(1)
        ->and($trends['top_forms']['rows'][0]['label'])->toBe('Intake')
        // The API-shaped keys survive alongside it -- the label is added, nothing is replaced.
        ->and($trends['top_forms']['rows'][0])->toHaveKeys(['key', 'label', 'count']);
});

it('still names a SOFT-DELETED form, which an org-wide breakdown legitimately still counts', function (): void {
    // AnalyticsFormSet::visible() roots an org-wide reader on Form::withTrashed(), so a deleted form's
    // countable submissions stay in the total. A lookup without withTrashed() returns no row for it and the
    // bar renders with a number and no name -- a hole nobody would think to test for.
    ['owner' => $owner, 'tenant' => $tenant] = trendFixture();

    $second = publishedInboxForm($tenant, $owner, 'Retired Intake');
    seedCountableAt($second, CarbonImmutable::now()->subDay());
    $second->delete();

    $rows = app(DashboardMetricsService::class)->trendsForUser($owner)['top_forms']['rows'];
    $labels = array_column($rows, 'label');

    expect($rows)->toHaveCount(2)
        ->and($labels)->toContain('Retired Intake')
        ->and($labels)->not->toContain('Deleted form');
});

it('scopes the trends to the same forms the KPI tiles count', function (): void {
    // One shared query layer, two entry points: the trend goes through AnalyticsFormSet, which applies the
    // same dashboard.org.view split scopeVisibleTo() does. A trend wider than the tiles beside it would be
    // the D2 disagreement in miniature.
    ['owner' => $owner] = trendFixture();

    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $metrics = app(DashboardMetricsService::class);

    expect($metrics->trendsForUser($editor)['total']['current'])->toBe(0)
        ->and($metrics->trendsForUser($owner)['total']['current'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Increment I10c -- the submission-channel breakdown
|--------------------------------------------------------------------------
|
| docs/PRD.md:198's last unbuilt Phase-1 clause. Ungated, for the same reason every other trend prop above
| is: `advanced_analytics` gates the Phase-3 surface, not a Phase-1 acceptance criterion.
*/

it('breaks the period down by submission channel, labelled from the enum', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = trendFixture();
    $form = Form::query()->where('title', 'Intake')->firstOrFail();

    // trendFixture() already seeded ONE guest row; add a second guest, a manual and an offline-sync.
    seedCountableAt($form, CarbonImmutable::now()->subDay(), source: SubmissionSource::Guest);
    seedCountableAt($form, CarbonImmutable::now()->subDay(), source: SubmissionSource::Manual);
    seedCountableAt($form, CarbonImmutable::now()->subDay(), source: SubmissionSource::OfflineSync);

    $channels = app(DashboardMetricsService::class)->trendsForUser($owner)['channels'];

    expect($channels)->toHaveKeys(['rows', 'other', 'unassigned']);

    $byLabel = array_column($channels['rows'], 'count', 'label');

    // Labels from SubmissionSource::label(), not raw enum values -- the same call site AnalyticsPresenter
    // uses, so the dashboard and /analytics cannot disagree about what a channel is called.
    expect($byLabel)->toBe(['Guest link' => 2, 'Manual entry' => 1, 'Offline sync' => 1]);
});

it('falls back to the raw value for a channel this build does not know', function (): void {
    // `submissions.source` is varchar(20) NOT NULL with NO DB CHECK constraint, and the aggregate reads it
    // off a selectRaw alias so the Eloquent cast never runs -- an unknown string genuinely reaches the
    // labeller. `from()` here would be an uncaught ValueError on the page every role lands on after login.
    ['owner' => $owner, 'tenant' => $tenant] = trendFixture();
    $form = Form::query()->where('title', 'Intake')->firstOrFail();
    $stray = seedCountableAt($form, CarbonImmutable::now()->subDay());

    DB::table('submissions')->where('id', $stray->id)->update(['source' => 'sms_gateway']);

    $rows = app(DashboardMetricsService::class)->trendsForUser($owner)['channels']['rows'];
    $byLabel = array_column($rows, 'count', 'label');

    // Counted, and shown VERBATIM -- a placeholder would hide the string an operator needs to find whatever
    // wrote it.
    expect($byLabel)->toHaveKey('sms_gateway')
        ->and($byLabel['sms_gateway'])->toBe(1);
});

it('never invents a zero bar for a channel nobody has used', function (): void {
    // A GROUP BY returns only what occurred. Zero-filling the six cases would invent categories AND
    // advertise OCR / API import -- which are unbuilt -- as available and unused.
    ['owner' => $owner] = trendFixture();

    $rows = app(DashboardMetricsService::class)->trendsForUser($owner)['channels']['rows'];
    $keys = array_column($rows, 'key');

    expect($rows)->toHaveCount(1)
        ->and($keys)->toBe(['guest'])
        ->and($keys)->not->toContain('ocr_single')
        ->and($keys)->not->toContain('api_import');
});

it('scopes the channel breakdown to the same forms as the tiles', function (): void {
    ['owner' => $owner] = trendFixture();

    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $metrics = app(DashboardMetricsService::class);

    expect($metrics->trendsForUser($editor)['channels']['rows'])->toBe([])
        ->and($metrics->trendsForUser($owner)['channels']['rows'])->not->toBe([]);
});

it('keeps rows + other + unassigned equal to the period total on the channel axis', function (): void {
    // ADR-0011 section D6/D11's conservation property: nothing may be silently dropped from a grouped result.
    ['owner' => $owner] = trendFixture();
    $form = Form::query()->where('title', 'Intake')->firstOrFail();

    foreach ([SubmissionSource::Manual, SubmissionSource::OfflineSync] as $source) {
        seedCountableAt($form, CarbonImmutable::now()->subDay(), source: $source);
    }

    $trends = app(DashboardMetricsService::class)->trendsForUser($owner);
    $channels = $trends['channels'];

    $summed = array_sum(array_column($channels['rows'], 'count'))
        + ($channels['other']['count'] ?? 0)
        + $channels['unassigned'];

    expect($summed)->toBe($trends['total']['current']);
});
