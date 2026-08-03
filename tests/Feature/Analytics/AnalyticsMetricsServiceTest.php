<?php

declare(strict_types=1);

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFormSelection;
use App\Enums\AnalyticsGranularity;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Analytics\AnalyticsMetricsService;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Forms\FormSchedule;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ADR-0011's cross-form aggregates. The tests are ordered by how QUIETLY each failure would ship: a
| breakdown that drops a population, a series that omits an empty bucket and a draft rate over a deleted
| denominator all return plausible numbers, render as convincing charts, and are wrong.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    // `formIn()` reads test()->user, so the two names must point at the same person.
    $this->user = $this->owner;
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->metrics = app(AnalyticsMetricsService::class);
    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Assign an ALREADY-PUBLISHED form to a scope node.
 *
 * Not `formIn()`: that helper CREATES a form (unpublished, via FormService) and assigns that one, so pairing
 * it with `publishedInboxForm()` silently produces two forms and leaves the published one unassigned. These
 * tests need submissions against a published version AND a node, which is only this shape.
 */
function assignToNode(Form $form, ScopeNode $node): Form
{
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $node->id]);

    return $form->refresh();
}

/** A UTC day-granularity query over an explicit window, with everything else defaulted. */
function analyticsRange(string $from, string $to, string $timezone = 'UTC'): AnalyticsQuery
{
    return new AnalyticsQuery(
        from: CarbonImmutable::parse($from, $timezone),
        to: CarbonImmutable::parse($to, $timezone),
        timezone: $timezone,
    );
}

/*
|--------------------------------------------------------------------------
| Series
*/

it('returns a zero for every empty bucket, not fewer buckets', function (): void {
    // A series that returned only non-empty buckets renders as a plausible LINE rather than as a floor, so
    // the omission is invisible in the chart it feeds. Seed the ends of a 7-day range and assert the middle.
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-01 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-05 09:00', 'UTC'));

    $series = $this->metrics->series(analyticsRange('2026-03-01', '2026-03-07'), $this->owner);

    expect($series)->toHaveCount(7)
        ->and(array_column($series, 'count'))->toBe([1, 0, 0, 0, 1, 0, 0])
        ->and($series[0]['bucket'])->toBe('2026-03-01')
        ->and($series[6]['bucket'])->toBe('2026-03-07');
});

it('buckets by the query timezone, not by the server session zone', function (): void {
    // The defect this guards is silent and environment-dependent: `date_trunc('day', ts)` uses the session
    // TimeZone GUC, and config/database.php sets none — so the same row buckets differently on the container
    // and on ADR-0005's self-hosted box. 22:00 UTC on the 1st is 06:00 on the 2nd in Manila (UTC+8).
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-01 22:00', 'UTC'));

    $utc = $this->metrics->series(analyticsRange('2026-03-01', '2026-03-02'), $this->owner);
    $manila = $this->metrics->series(analyticsRange('2026-03-01', '2026-03-02', 'Asia/Manila'), $this->owner);

    expect(array_column($utc, 'count'))->toBe([1, 0])
        ->and(array_column($manila, 'count'))->toBe([0, 1]);
});

it('reconciles: the series sums to the range total', function (): void {
    // Catches a silently dropped row from any cause at once -- a null submitted_at, a bucket-edge timezone
    // shift, a join that multiplies rows. Cheap, and it fails loudly for reasons a targeted test would miss.
    foreach (['2026-03-01 00:00', '2026-03-01 23:59', '2026-03-04 12:00', '2026-03-07 23:59:59'] as $at) {
        seedCountableAt($this->form, CarbonImmutable::parse($at, 'UTC'));
    }

    $query = analyticsRange('2026-03-01', '2026-03-07');
    $series = $this->metrics->series($query, $this->owner);

    expect(array_sum(array_column($series, 'count')))->toBe(4)
        ->and($this->metrics->total($query, $this->owner)['current'])->toBe(4);
});

it('excludes drafts and soft-deleted submissions from every number', function (): void {
    // ADR-0011 §D2. The draft is the easy half; the soft-deleted row is the one that matters, because
    // submission_answer_index's cascade is hard-delete only and the same carelessness there over-counts.
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'), SubmissionStatus::Draft);
    $trashed = seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 11:00', 'UTC'));
    $trashed->delete();

    $query = analyticsRange('2026-03-01', '2026-03-07');

    expect($this->metrics->total($query, $this->owner)['current'])->toBe(1)
        ->and(array_sum(array_column($this->metrics->series($query, $this->owner), 'count')))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Period comparison
*/

it('compares against the equal-length range immediately before, and refuses to divide by zero', function (): void {
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-05 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-06 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-02-27 09:00', 'UTC')); // prior period

    // 2026-03-01..07 is 7 days, so the prior period is 2026-02-22..28.
    $result = $this->metrics->total(analyticsRange('2026-03-01', '2026-03-07'), $this->owner);

    expect($result['current'])->toBe(2)
        ->and($result['prior'])->toBe(1)
        ->and($result['change'])->toBe(100.0);

    // An empty prior period yields null, never "+100%" -- a percentage change from zero is undefined, and
    // rendering it as a number is the same confident-wrong shape §D5 refuses elsewhere.
    $isolated = $this->metrics->total(analyticsRange('2026-06-01', '2026-06-07'), $this->owner);

    expect($isolated['prior'])->toBe(0)
        ->and($isolated['change'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Breakdown -- the reconciliation invariant
*/

it('keeps sum(rows) + other + unassigned equal to the total, across the top-N collapse', function (): void {
    // §D7 caps the result cardinality and §D11 folds the overflow into "Other (N)". The invariant is that
    // nothing is LOST in that fold -- a breakdown that silently truncated would look perfectly correct.
    $forms = [$this->form];
    foreach (range(1, 6) as $n) {
        $forms[] = publishedInboxForm($this->tenant, $this->owner, "Extra {$n}");
    }

    foreach ($forms as $i => $form) {
        foreach (range(0, $i) as $ignored) {
            seedCountableAt($form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
        }
    }

    $query = new AnalyticsQuery(
        from: CarbonImmutable::parse('2026-03-01', 'UTC'),
        to: CarbonImmutable::parse('2026-03-07', 'UTC'),
        axis: AnalyticsAxis::Form,
        topN: 3,
    );

    $breakdown = $this->metrics->breakdown($query, $this->owner);
    $total = $this->metrics->total($query, $this->owner)['current'];

    expect($breakdown['rows'])->toHaveCount(3)
        ->and($breakdown['other'])->not->toBeNull()
        ->and($breakdown['other']['categories'])->toBe(4);

    $summed = array_sum(array_column($breakdown['rows'], 'count'))
        + $breakdown['other']['count']
        + $breakdown['unassigned'];

    expect($summed)->toBe($total)->and($total)->toBe(28);
});

it('puts unassigned forms in an explicit bucket rather than a short total', function (): void {
    // §D6: forms.scope_node_id is NULL for EVERY form until explicitly assigned -- which is every form's
    // state after the G10a backfill -- so a scope-node breakdown with no Unassigned bucket would report a
    // total smaller than the overview's, on the same screen.
    $node = makeScopeNode(name: 'Region I');
    $assigned = assignToNode(publishedInboxForm($this->tenant, $this->owner, 'Assigned'), $node);

    seedCountableAt($assigned, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC')); // no node

    $query = (new AnalyticsQuery(
        from: CarbonImmutable::parse('2026-03-01', 'UTC'),
        to: CarbonImmutable::parse('2026-03-07', 'UTC'),
    ))->withAxis(AnalyticsAxis::ScopeNode);

    $breakdown = $this->metrics->breakdown($query, $this->owner);

    expect($breakdown['unassigned'])->toBe(1)
        ->and($breakdown['rows'])->toHaveCount(1)
        ->and($breakdown['rows'][0]['key'])->toBe($node->id)
        ->and(array_sum(array_column($breakdown['rows'], 'count')) + $breakdown['unassigned'])
        ->toBe($this->metrics->total($query, $this->owner)['current']);
});

it('folds forms on a deactivated node into unassigned rather than dropping them', function (): void {
    // The population a careful implementation still loses. §D6 adopts nodeReach()'s semantics, so a
    // deactivated node must not appear as its own bucket -- but excluding its forms outright would make
    // sum(buckets) < total, which is exactly the self-disagreement §D2 exists to prevent.
    $live = makeScopeNode(name: 'Live');
    $dead = makeScopeNode(name: 'Dead');

    $onLive = assignToNode(publishedInboxForm($this->tenant, $this->owner, 'On live'), $live);
    $onDead = assignToNode(publishedInboxForm($this->tenant, $this->owner, 'On dead'), $dead);

    seedCountableAt($onLive, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
    seedCountableAt($onDead, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));

    app(ScopeNodeService::class)->setActive($dead, false);
    app(ResourceGrantResolver::class)->forget();

    $query = (new AnalyticsQuery(
        from: CarbonImmutable::parse('2026-03-01', 'UTC'),
        to: CarbonImmutable::parse('2026-03-07', 'UTC'),
    ))->withAxis(AnalyticsAxis::ScopeNode);

    $breakdown = $this->metrics->breakdown($query, $this->owner);

    expect($breakdown['rows'])->toHaveCount(1)
        ->and($breakdown['rows'][0]['key'])->toBe($live->id)
        ->and($breakdown['unassigned'])->toBe(1)
        ->and(array_sum(array_column($breakdown['rows'], 'count')) + $breakdown['unassigned'])
        ->toBe($this->metrics->total($query, $this->owner)['current']);
});

it('breaks down by submission channel without a forms join', function (): void {
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'), source: SubmissionSource::Guest);
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 10:00', 'UTC'), source: SubmissionSource::Guest);
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 11:00', 'UTC'), source: SubmissionSource::Manual);

    $breakdown = $this->metrics->breakdown(
        (analyticsRange('2026-03-01', '2026-03-07'))->withAxis(AnalyticsAxis::Source),
        $this->owner
    );

    expect($breakdown['rows'])->toBe([
        ['key' => 'guest', 'count' => 2],
        ['key' => 'manual', 'count' => 1],
    ])->and($breakdown['unassigned'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Visibility
*/

it('scopes every aggregate to what the user may see, and gives an ungranted user zero', function (): void {
    // The analytics total must never exceed the inbox for the same user. A Form Editor with no grants gets
    // the whereRaw('false') path -- NOT everything, which is what an unconstrained whereIn would mean.
    $other = publishedInboxForm($this->tenant, $this->owner, 'Other');
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
    seedCountableAt($other, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));

    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    $query = analyticsRange('2026-03-01', '2026-03-07');

    expect($this->metrics->total($query, $editor)['current'])->toBe(0);

    makeCollaborator($this->form, $editor, ResourceCapacity::Editor);
    app(ResourceGrantResolver::class)->forget();

    expect($this->metrics->total($query, $editor)['current'])->toBe(1)
        ->and($this->metrics->total($query, $this->owner)['current'])->toBe(2);
});

it('intersects a scope-subtree selection with visibility rather than widening it', function (): void {
    $region = makeScopeNode(name: 'Region I');
    $inRegion = assignToNode(publishedInboxForm($this->tenant, $this->owner, 'In region'), $region);
    seedCountableAt($inRegion, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC')); // outside the region

    $query = new AnalyticsQuery(
        from: CarbonImmutable::parse('2026-03-01', 'UTC'),
        to: CarbonImmutable::parse('2026-03-07', 'UTC'),
        selection: AnalyticsFormSelection::ScopeNode,
        scopeNodeId: $region->id,
    );

    expect($this->metrics->total($query, $this->owner)['current'])->toBe(1);

    // An editor holding no grant inside the region sees nothing, even though the subtree itself has data.
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    expect($this->metrics->total($query, $editor)['current'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| §D5 -- the two amended metrics
*/

it('reports draft conversion over explicitly-saved rows only, never over every submission', function (): void {
    // The denominator is `last_saved_at IS NOT NULL`, which survives promotion because promote() rewrites
    // only status/submitted_at/draft_expires_at. A direct submit is excluded -- which is the point, since
    // submitted_at - created_at spans a single server request for one of those.
    $base = CarbonImmutable::now()->subDays(3);

    // Two saved drafts that converted, one still open, one direct submit that must not count at all.
    seedCountableAt($this->form, $base->addHours(4), createdAt: $base, lastSavedAt: $base->addHour());
    seedCountableAt($this->form, $base->addHours(6), createdAt: $base, lastSavedAt: $base->addHour());
    seedCountableAt($this->form, $base, SubmissionStatus::Draft, createdAt: $base, lastSavedAt: $base->addHour());
    seedCountableAt($this->form, $base->addHours(2));

    $query = analyticsRange(
        CarbonImmutable::now()->subDays(5)->toDateString(),
        CarbonImmutable::now()->toDateString(),
    );

    $result = $this->metrics->draftMetrics($query, $this->owner);

    expect($result['suppressed'])->toBeFalse()
        ->and($result['denominator'])->toBe(3)
        ->and($result['converted'])->toBe(2)
        ->and($result['conversion_rate'])->toBe(66.7);
});

it('excludes OCR-staged drafts from the denominator, so the median cannot drift into encoder latency', function (): void {
    // The forward-dated defect §D5's channel restriction exists for. SubmissionDraftService is a SERVICE
    // seam, not only an HTTP route, and every createDraft()/updateDraft() path stamps last_saved_at
    // unconditionally -- so the moment H18/H19 land, an OCR-staged draft would join this denominator and the
    // median would silently start measuring review latency instead of respondent fill time.
    $base = CarbonImmutable::now()->subDays(3);

    seedCountableAt($this->form, $base->addHour(), createdAt: $base, lastSavedAt: $base);
    seedCountableAt($this->form, $base->addDay(), source: SubmissionSource::OcrSingle, createdAt: $base, lastSavedAt: $base);

    $result = $this->metrics->draftMetrics(
        analyticsRange(CarbonImmutable::now()->subDays(5)->toDateString(), CarbonImmutable::now()->toDateString()),
        $this->owner
    );

    expect($result['denominator'])->toBe(1)
        ->and($result['median_seconds'])->toBe(3600);
});

it('suppresses rather than estimates beyond the draft-retention window, and says which it is', function (): void {
    // The number this refuses to print is the one that RISES every night as ReapTenantDraftsJob deletes its
    // own denominator. Three states must stay distinguishable: suppressed, no-qualifying-rows, and a number
    // -- a tile rendering "0%" for a suppressed metric is §D5's failure wearing a different hat.
    $this->tenant->forceFill(['draft_ttl_days' => 7])->save();

    $suppressed = $this->metrics->draftMetrics(
        analyticsRange(CarbonImmutable::now()->subDays(30)->toDateString(), CarbonImmutable::now()->toDateString()),
        $this->owner
    );

    expect($suppressed['suppressed'])->toBeTrue()
        ->and($suppressed['reason'])->toBe('beyond_draft_retention')
        ->and($suppressed['conversion_rate'])->toBeNull()
        ->and($suppressed['median_seconds'])->toBeNull();

    // Inside the window with nothing saved is a DIFFERENT answer, and must not read as suppression.
    $empty = $this->metrics->draftMetrics(
        analyticsRange(CarbonImmutable::now()->subDays(2)->toDateString(), CarbonImmutable::now()->toDateString()),
        $this->owner
    );

    expect($empty['suppressed'])->toBeFalse()
        ->and($empty['reason'])->toBe('no_saved_drafts')
        ->and($empty['conversion_rate'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Accepting-forms count -- the parity obligation
*/

it('agrees with FormSchedule::acceptance() on every form', function (): void {
    // The one place H24a builds a SECOND implementation of an existing rule, so it is pinned against the
    // first. An unpinned copy is how a dashboard tile comes to contradict the public form's own banner.
    $now = CarbonImmutable::now();

    $open = $this->form;                                                   // published, no schedule
    // scheduledForm() returns the published FormVersion, not the Form -- its own docblock says to read the
    // form back through the version when the schedule columns are what you need.
    $soon = Form::findOrFail(scheduledForm($this->tenant, $this->owner, ['opens_at' => $now->addDays(2)], 'Opens soon')->form_id);
    $closed = Form::findOrFail(scheduledForm($this->tenant, $this->owner, ['closes_at' => $now->subDay()], 'Closed')->form_id);
    $capped = publishedInboxForm($this->tenant, $this->owner, 'Capped');
    $capped->forceFill(['max_responses' => 1])->save();
    seedCountableAt($capped, $now->subHour());

    $expected = collect([$open, $soon, $closed, $capped])
        ->filter(function (Form $form) use ($now): bool {
            $form = $form->refresh();
            $count = $form->max_responses === null
                ? null
                : Submission::query()->countable()->where('form_id', $form->id)->count();

            return FormSchedule::acceptance($form, $count, $now) === 'open';
        })
        ->count();

    expect($this->metrics->acceptingFormsCount($this->owner))->toBe($expected)
        ->and($expected)->toBe(1); // only $open; soon/closed/capped are each excluded for a different reason
});

/*
|--------------------------------------------------------------------------
| Granularity
*/

it('buckets by ISO week, Monday-start, matching date_trunc', function (): void {
    // Carbon's startOfWeek() honours the app locale while date_trunc('week') is always ISO. The PHP
    // gap-filler and the SQL aggregate must agree on where a bucket starts or a filled zero lands on a label
    // the aggregate never emits. 2026-03-02 is a Monday.
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-04 09:00', 'UTC')); // Wednesday
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-09 09:00', 'UTC')); // next Monday

    $series = $this->metrics->series(
        new AnalyticsQuery(
            from: CarbonImmutable::parse('2026-03-02', 'UTC'),
            to: CarbonImmutable::parse('2026-03-15', 'UTC'),
            granularity: AnalyticsGranularity::Week,
        ),
        $this->owner
    );

    expect($series)->toBe([
        ['bucket' => '2026-03-02', 'count' => 1],
        ['bucket' => '2026-03-09', 'count' => 1],
    ]);
});
