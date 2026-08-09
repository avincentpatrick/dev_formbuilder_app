<?php

declare(strict_types=1);

use App\Enums\AnalyticsAxis;
use App\Enums\PlanTier;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Form;
use App\Models\User;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H24b2 — the Business-gated /analytics page renders every prop the charts, tiles and pickers bind to,
| resolved through AnalyticsPresenter over H24a's substrate.
|
| The assertions are key-by-key rather than `has('report')`, for the reason DashboardKpisTest records: a
| partial assertion passes against a prop that has silently lost half its shape, and this page's whole job
| is to carry that shape to a screen.
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
    $this->user = $this->owner; // formIn() reads test()->user

    // Business is the only tier carrying `advanced_analytics`. Not scene-setting: RequireFeature FAILS OPEN
    // on a null plan, so without this the gate would pass vacuously and prove nothing.
    assignPlanTier(PlanTier::Business);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function analyticsPageUrl(string $query = ''): string
{
    return 'http://acme.meridian.test/analytics'.($query === '' ? '' : '?'.$query);
}

it('renders every prop the page binds to, key by key', function (): void {
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedCountableAt($form, CarbonImmutable::now()->subDays(3));

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('analytics/Index', false)
            // The applied declaration — AnalyticsQuery::toArray() verbatim, `v` included, so the page and a
            // stored saved-view definition speak exactly one shape.
            ->where('applied.v', AnalyticsQuery::SCHEMA_VERSION)
            ->where('applied.timezone', 'UTC')
            ->where('applied.axis', AnalyticsAxis::Form->value)
            ->where('applied.top_n', AnalyticsQuery::DEFAULT_TOP_N)
            ->has('applied.from')->has('applied.to')
            ->where('refusal', null)
            ->where('can.createView', true)
            // Option catalogues.
            ->has('filters.forms')
            ->has('filters.scope_nodes')
            ->has('filters.axes', 5)
            ->has('filters.granularities', 3)
            ->has('filters.selections', 3)
            // `draft` is deliberately absent — it is never in a countable result, so offering it would offer
            // a filter that can only ever return nothing.
            ->has('filters.statuses', count(SubmissionStatus::cases()) - 1)
            ->has('filters.sources', count(SubmissionSource::cases()))
            ->has('filters.timezones')
            ->where('filters.limits.max_range_days', AnalyticsQuery::MAX_RANGE_DAYS)
            ->has('summary.exports.used')
            // The report.
            ->has('report.total.current')->has('report.total.prior')->has('report.total.change')
            ->has('report.prior_range.from')->has('report.prior_range.to')
            ->has('report.series', 30)
            ->has('report.breakdown.rows')
            ->where('report.breakdown.axis', AnalyticsAxis::Form->value)
            ->where('report.breakdown.unassigned', 0)
            ->where('report.breakdown.unassigned_label', 'Unassigned')
            ->where('report.breakdown.has_unassigned_bucket', false)
            ->has('report.drafts.suppressed')
            ->has('report.forms_accepting')
            ->where('report.week_starts_on', 'monday')
            ->has('questions')
            ->where('question', null)
            ->has('views', 0));
});

it('defaults to a range the draft metrics are NOT suppressed at', function (): void {
    // THE off-by-one catcher, and the only assertion that finds it. draftMetrics() suppresses when
    // `fromUtc() < now()->subDays(30)`; at 29 days the range opens at local midnight of today-29, which is
    // always inside the horizon, and at 30 it is always outside. Spelling the default "30 days" the obvious
    // way would suppress BOTH tiles on every request forever, with every gate green over a dead feature.
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedCountableAt(
        $form,
        CarbonImmutable::now()->subDays(3),
        SubmissionStatus::Submitted,
        SubmissionSource::Guest,
        CarbonImmutable::now()->subDays(4),
        CarbonImmutable::now()->subDays(4),
    );

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.drafts.suppressed', false)
            ->where('report.drafts.denominator', 1));
});

it('round-trips every filter through the query string into the resolved declaration', function (): void {
    $this->withoutVite();

    // ⚠️ THE CLOCK IS PINNED BECAUSE THE WEEKLY-BUCKET ASSERTION BELOW IS CALENDAR-DEPENDENT, and this
    // test failed in CI on 2026-08-09 having passed every day before it. 28 days is EXACTLY four weeks, so
    // `date_trunc('week', …)` (Monday-start) returns FIVE partial weeks on six weekdays out of seven and
    // exactly FOUR when the window opens on a Monday — which `now()->subDays(27)` did that morning. The
    // assertion hard-coded the maximum while the comment beside it correctly said "at most 5", so the test
    // was green by calendar luck one day in seven away from being red.
    //
    // Pinned rather than computed: deriving the expected count here would re-implement the very bucketing
    // this case exists to prove reached the database. 2026-03-18 opens the window on a Thursday.
    Carbon::setTestNow(Carbon::parse('2026-03-18T09:00:00+00:00'));

    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');

    $from = CarbonImmutable::now()->subDays(27)->toDateString();
    $to = CarbonImmutable::now()->toDateString();

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl(http_build_query([
            'from' => $from,
            'to' => $to,
            'timezone' => 'Asia/Manila',
            'granularity' => 'week',
            'axis' => 'source',
            'statuses' => ['approved'],
            'sources' => ['guest'],
            'locales' => ['es'],
            'top_n' => 3,
        ])))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('applied.from', $from)
            ->where('applied.to', $to)
            ->where('applied.timezone', 'Asia/Manila')
            ->where('applied.granularity', 'week')
            ->where('applied.axis', 'source')
            ->where('applied.statuses', ['approved'])
            ->where('applied.sources', ['guest'])
            ->where('applied.locales', ['es'])
            ->where('applied.top_n', 3)
            // The proof that `granularity` reached date_trunc and is not merely echoed: a 28-day window is
            // 28 daily buckets but at most 5 weekly ones — 5 exactly, given the pinned Thursday open above.
            ->where('report.range.granularity', 'week')
            ->has('report.series', 5));

    Carbon::setTestNow();
});

it('labels each breakdown row rather than serving a bare uuid', function (): void {
    // Resolved in the PRESENTER, never inside AnalyticsMetricsService::breakdown() — that method's shape is
    // the /api/v1 response byte-diffed against openapi.json. Pinned here so nobody "fixes" it in the wrong
    // file later.
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedCountableAt($form, CarbonImmutable::now()->subDays(2));

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('report.breakdown.rows', 1)
            ->where('report.breakdown.rows.0.key', (string) $form->id)
            ->where('report.breakdown.rows.0.label', 'Clinic Intake')
            ->where('report.breakdown.rows.0.count', 1));
});

it('names a soft-deleted form rather than leaving a bar with a number and no name', function (): void {
    // withTrashed() is load-bearing: AnalyticsFormSet roots an org-wide reader's set on it, so a
    // soft-deleted form's submissions stay countable and legitimately appear in the breakdown.
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Retired Survey');
    seedCountableAt($form, CarbonImmutable::now()->subDays(2));
    $form->delete();

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.breakdown.rows.0.label', 'Retired Survey'));
});

it('translates an enum axis key into its label, and leaves a locale tag alone', function (): void {
    // A locale label must be the raw BCP-47 tag: the same label appears in the CSV export, and a
    // viewer-localised display name would make the chart and the file disagree.
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    seedCountableAt(
        $form, CarbonImmutable::now()->subDays(2), SubmissionStatus::Submitted,
        SubmissionSource::Guest, null, null, 'es',
    );

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl('axis=source'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.breakdown.rows.0.label', SubmissionSource::Guest->label()));

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl('axis=locale'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.breakdown.rows.0.label', 'es')
            ->where('report.breakdown.unassigned_label', 'Not recorded')
            ->where('report.breakdown.has_unassigned_bucket', true));
});

it('keeps sum(rows) + other + unassigned equal to the total ON THE PAGE PROP', function (): void {
    // AnalyticsMetricsServiceTest asserts this on the SERVICE. The presenter re-maps `rows` to attach
    // labels, and a re-map that dropped or filtered a row would break §D11's reconciliation with every
    // existing test still green — which is exactly why the invariant is asserted twice, at two layers.
    $this->withoutVite();

    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $i => $title) {
        $form = publishedInboxForm($this->tenant, $this->owner, "Form {$title}");

        for ($n = 0; $n <= $i; $n++) {
            seedCountableAt($form, CarbonImmutable::now()->subDays(2));
        }
    }

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(function ($page) {
            $breakdown = $page->toArray()['props']['report']['breakdown'];
            $total = $page->toArray()['props']['report']['total']['current'];

            $sum = array_sum(array_column($breakdown['rows'], 'count'))
                + ($breakdown['other']['count'] ?? 0)
                + $breakdown['unassigned'];

            expect($sum)->toBe($total);
            // Seven forms over a top-5 → the overflow bucket holds two categories, the PLURAL branch.
            expect($breakdown['other']['categories'])->toBe(2);
        });
});

it('reports the draft denominator over respondent channels only', function (): void {
    // §D5's `source IN (guest, manual)`: an OCR- or offline-staged draft is encoder review latency, not
    // respondent behaviour. The offline_sync row below carries `last_saved_at` and must stay OUT.
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $at = CarbonImmutable::now()->subDays(5);

    seedCountableAt($form, $at, SubmissionStatus::Submitted, SubmissionSource::Guest, $at->subMinutes(10), $at->subMinutes(10));
    seedCountableAt($form, $at, SubmissionStatus::Submitted, SubmissionSource::Manual, $at->subMinutes(20), $at->subMinutes(20));
    seedCountableAt($form, $at, SubmissionStatus::Submitted, SubmissionSource::OfflineSync, $at->subMinutes(30), $at->subMinutes(30));

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.drafts.denominator', 2)
            ->where('report.drafts.converted', 2)
            ->where('report.drafts.conversion_rate', 100));
});

it('serves the question catalogue with its refusals ENCODED, never discovered', function (): void {
    $this->withoutVite();
    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(function ($page) {
            $questions = $page->toArray()['props']['questions'];
            expect($questions)->not->toBeEmpty();

            foreach ($questions as $question) {
                expect($question)->toHaveKeys(['key', 'label', 'reportable', 'refusal', 'refusal_label']);
                // The invariant the picker relies on: an unavailable question ALWAYS carries a sentence
                // explaining why. A refusal with no label would render as a bare disabled row.
                if (! $question['reportable']) {
                    expect($question['refusal_label'])->toBeString()->not->toBeEmpty();
                }
            }

            // publishedInboxForm() flags nothing for reporting, so this is the all-refused state — the one
            // a fresh tenant actually sees.
            expect(array_filter($questions, fn (array $q): bool => $q['reportable']))->toBeEmpty();
        });
});

it('returns the selected question through a partial reload without recomputing the report', function (): void {
    $this->withoutVite();
    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');

    // The asset version has to match or Inertia answers 409 (its reload-the-page handshake) before the
    // controller is ever reached — and the partial would go untested while the test passed on the 409.
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    // Asserted on the JSON BODY, not through assertInertia(): that helper reads the Blade view's `page`
    // data, which only a full HTML visit produces. A partial reload is an XHR returning JSON, so the body
    // is the only place its shape is visible — and the shape is the whole point of this test.
    $response = $this->actingAs($this->owner)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'analytics/Index',
            'X-Inertia-Partial-Data' => 'applied,question',
        ])
        ->get(analyticsPageUrl('question=full_name'))
        ->assertOk();

    expect($response->json('component'))->toBe('analytics/Index');
    expect($response->json('props.applied.v'))->toBe(AnalyticsQuery::SCHEMA_VERSION);
    expect($response->json('props.question.key'))->toBe('full_name');
    // `full_name` is not flagged for reporting, so §D4's refusal is what the explorer must render — and it
    // must carry no number beside it.
    expect($response->json('props.question.refused'))->toBeTrue();
    expect($response->json('props.question.reason'))->toBe('not_indexed');
    expect($response->json('props.question.summary'))->toBeNull();
    expect($response->json('props.question.rows'))->toBeNull();
    // The one derivation the aggregate does not carry: its own name. `aggregate()` returns `key` only, so
    // without the presenter resolving it the chart title would read "full_name" instead of "Full name" —
    // and it is resolved even on a REFUSED question, which is the case most likely to be left unlabelled.
    expect($response->json('props.question.label'))->toBe('Full name');

    // The reason the heavy groups are Closure props at all: a question selection must not re-run the
    // report, the question catalogue, the saved views or the option catalogues.
    $props = $response->json('props');
    expect($props)->not->toHaveKey('report');
    expect($props)->not->toHaveKey('filters');
    expect($props)->not->toHaveKey('questions');
    expect($props)->not->toHaveKey('views');
});

it('scopes a form-scoped role to their own forms, so the page can never exceed their inbox', function (): void {
    $this->withoutVite();
    $mine = publishedInboxForm($this->tenant, $this->owner, 'Mine');
    seedCountableAt($mine, CarbonImmutable::now()->subDays(2));

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    $this->actingAs($editor)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.total.current', 0)
            ->has('report.breakdown.rows', 0));
});

it('states the prior window rather than leaving the delta with no referent', function (): void {
    $this->withoutVite();
    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');

    $from = CarbonImmutable::now()->subDays(9)->toDateString();
    $to = CarbonImmutable::now()->toDateString();

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl(http_build_query(['from' => $from, 'to' => $to])))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.prior_range.from', CarbonImmutable::now()->subDays(19)->toDateString())
            ->where('report.prior_range.to', CarbonImmutable::now()->subDays(10)->toDateString()));
});

it('lists forms the aggregates actually cover, marking an archived one as archived', function (): void {
    $this->withoutVite();
    $live = publishedInboxForm($this->tenant, $this->owner, 'Live Form');
    $gone = publishedInboxForm($this->tenant, $this->owner, 'Archived Form');
    $gone->delete();

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(function ($page) use ($live, $gone) {
            $forms = collect($page->toArray()['props']['filters']['forms'])->keyBy('value');

            expect($forms[(string) $live->id]['label'])->toBe('Live Form');
            expect($forms[(string) $gone->id]['label'])->toBe('Archived Form (archived)');
        });
});

it('derives the locale filter from what the FORMS declare, not from a scan of submissions', function (): void {
    // A DISTINCT over `submissions.locale` is unbounded and free-text on the ingest paths, so it can both
    // be slow and offer junk as a filter.
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $form->forceFill(['default_locale' => 'en', 'supported_locales' => ['en', 'tl']])->save();
    // `zz` is a real submission locale that no form declares — the case a DISTINCT scan would surface as a
    // filter option and this derivation deliberately does not. (`submissions.locale` is varchar(10).)
    seedCountableAt(
        $form, CarbonImmutable::now()->subDays(2), SubmissionStatus::Submitted,
        SubmissionSource::Guest, null, null, 'zz',
    );

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.locales', [
                ['value' => 'en', 'label' => 'en'],
                ['value' => 'tl', 'label' => 'tl'],
            ]));
});

it('degrades a selection naming a deleted area to a stated refusal, not a 404', function (): void {
    // AnalyticsFormSet::node() is findOrFail — correct, because returning "all forms" would silently widen
    // a scoped report. The presenter pre-empts it so §D8's promise ("a stated refusal") is kept on the page
    // rather than being a bare 404, and every heavy prop goes null rather than half-computed.
    $this->withoutVite();
    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $node = makeScopeNode(null, 'Region I');
    $missingId = $node->id;
    $node->forceDelete();

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl(http_build_query(['selection' => 'scope_node', 'scope_node_id' => $missingId])))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('refusal.reason', 'scope_node_missing')
            ->where('report', null)
            ->where('question', null)
            ->has('questions', 0)
            // The filter rail still renders, so the user can change the selection rather than being stuck.
            ->has('filters.forms'));
});

it('does not refuse a DEACTIVATED area — that is an honest zero, not a broken report', function (): void {
    $this->withoutVite();
    $node = makeScopeNode(null, 'Region II');
    // formIn() leaves the form a DRAFT, and seedCountableAt() needs a published version — so publish here
    // and attach the node the same way formIn() does.
    $form = publishedInboxForm($this->tenant, $this->owner, 'Regional Survey');
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $node->id]);
    seedCountableAt($form->refresh(), CarbonImmutable::now()->subDays(2));
    $node->forceFill(['is_active' => false])->save();

    $this->actingAs($this->owner)
        ->get(analyticsPageUrl(http_build_query(['selection' => 'scope_node', 'scope_node_id' => $node->id])))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('refusal', null)
            ->where('report.total.current', 0));
});

it('rejects a form id from another tenant at the validator, never at the aggregate', function (): void {
    $this->withoutVite();
    $otherTenant = inboxTenant('beta');
    $otherOwner = User::factory()->create();
    enterTenant($otherTenant->id, $otherOwner->id);
    makeActiveMember($otherOwner, 'owner');
    $foreign = publishedInboxForm($otherTenant, $otherOwner, 'Foreign');

    enterTenant($this->tenant->id, $this->owner->id);

    // `exists:forms,id` runs on the RLS-scoped connection, so the row simply is not there.
    $this->actingAs($this->owner)
        ->get(analyticsPageUrl(http_build_query([
            'selection' => 'forms',
            'form_ids' => [(string) $foreign->id],
        ])))
        ->assertSessionHasErrors('form_ids.0');

    expect(Form::withTrashed()->find($foreign->id))->toBeNull();
});
