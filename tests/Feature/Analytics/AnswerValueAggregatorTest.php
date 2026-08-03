<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Analytics\AnswerValueAggregator;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ADR-0011 §D3/§D4 -- answer-value aggregation over the typed index.
|
| EVERY TEST HERE DRIVES THE REAL PIPELINE. `seedInboxSubmission()` factory-creates a Submission and its
| answer document directly, so it produces NO submission_answer_index rows at all -- an aggregation test
| built on it would assert against an empty table and pass for the wrong reason. The index-producing path is
| SubmissionPipeline -> SubmissionFinalizer::projectIndex(), and that is what these use.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    makeActiveMember($this->user, 'owner');

    $this->aggregator = app(AnswerValueAggregator::class);
    $this->pipeline = app(SubmissionPipeline::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A published form carrying one queryable field of the given type.
 *
 * `FormService::create()`, not `makeForm()`: the latter inserts a bare `forms` row with no draft version, so
 * `$form->draftVersion` is null and there is nothing to add a field to.
 */
function indexedForm(string $key, FieldType $type, IndexedDataType $indexed, string $title = 'Survey'): FormVersion
{
    $form = app(FormService::class)->create(test()->tenant, test()->user, $title);
    addFormField($form->draftVersion, test()->user, $key, $type, 0, [
        'is_queryable' => true,
        'indexed_data_type' => $indexed,
    ]);

    return app(PublishService::class)->publish($form->refresh(), test()->user);
}

/**
 * Publish a further version of the same form, re-declaring one field's index type.
 *
 * No version service is needed: `PublishService::publish()` clones the next draft forward automatically, so
 * the v2 draft already exists with the same field keys and only the flag has to move.
 */
function republishWithType(Form $form, string $key, ?IndexedDataType $indexed): FormVersion
{
    $form = $form->refresh();

    FormField::query()
        ->where('form_version_id', $form->draft_version_id)
        ->where('key', $key)
        ->update(['indexed_data_type' => $indexed?->value, 'is_queryable' => $indexed !== null]);

    return app(PublishService::class)->publish($form, test()->user);
}

function submitIndexed(FormVersion $version, array $answers): Submission
{
    return test()->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Manual,
        respondentUserId: test()->user->id,
    ))->submission;
}

function wideRange(): AnalyticsQuery
{
    return new AnalyticsQuery(
        from: CarbonImmutable::now()->subDays(30),
        to: CarbonImmutable::now()->addDay(),
    );
}

/*
|--------------------------------------------------------------------------
| §D2 -- the rule that makes this class's every query start from `submissions`
*/

it('drops a soft-deleted submission from the aggregate, because index rows outlive it', function (): void {
    // THE test this class exists to pass. submission_answer_index's FK cascade fires on HARD delete only, so
    // a query rooted on the index table returns the right SHAPE and the wrong NUMBER -- and nothing else in
    // the suite would notice.
    $version = indexedForm('colour', FieldType::ShortText, IndexedDataType::Text);

    submitIndexed($version, ['colour' => 'red']);
    submitIndexed($version, ['colour' => 'red']);
    $doomed = submitIndexed($version, ['colour' => 'blue']);

    // The projection rows are really there for all three.
    expect(SubmissionAnswerIndex::query()->where('field_key', 'colour')->count())->toBe(3);

    $doomed->delete();

    // ...and still there after the soft delete. That is the trap.
    expect(SubmissionAnswerIndex::query()->where('field_key', 'colour')->count())->toBe(3);

    $result = $this->aggregator->aggregate(wideRange(), $this->user, 'colour');

    expect($result['refused'])->toBeFalse()
        ->and($result['rows'])->toBe([['key' => 'red', 'count' => 2]])
        ->and($result['coverage'])->toBe(['indexed' => 2, 'countable' => 2]);
});

it('excludes drafts from both halves of the coverage figure', function (): void {
    $version = indexedForm('colour', FieldType::ShortText, IndexedDataType::Text);
    submitIndexed($version, ['colour' => 'red']);

    // A draft never reaches the finalizer, so it has no index row either -- but it must also not inflate the
    // countable denominator, or coverage would read as a gap that does not exist.
    Submission::factory()->forVersion($version)->create([
        'status' => SubmissionStatus::Draft,
        'submitted_at' => null,
    ]);

    expect($this->aggregator->aggregate(wideRange(), $this->user, 'colour')['coverage'])
        ->toBe(['indexed' => 1, 'countable' => 1]);
});

/*
|--------------------------------------------------------------------------
| §D4 -- the mixed-type refusal
*/

it('refuses a key whose indexed type changed, and names the version it changed at', function (): void {
    // docs/form-versioning-schema-migration.md §7 warned that a naive AVG(value_number) SILENTLY DROPS the
    // rows stored under the other type -- it does not error. So the failure mode is a plausible number, and
    // an implementation that just filtered `value_number IS NOT NULL` would pass every happy-path test.
    $v1 = indexedForm('score', FieldType::ShortText, IndexedDataType::Number);
    submitIndexed($v1, ['score' => '10']);

    $form = Form::findOrFail($v1->form_id);
    $v2 = republishWithType($form, 'score', IndexedDataType::Text);
    submitIndexed($v2, ['score' => 'ten']);

    $result = $this->aggregator->aggregate(wideRange(), $this->user, 'score');

    expect($result['refused'])->toBeTrue()
        ->and($result['reason'])->toBe('type_changed')
        ->and($result['type_changed_at_version'])->toBe($v2->version_number)
        ->and($result['message'])->toContain("version {$v2->version_number}")
        // No number is offered alongside the refusal -- coercion across the partition is forbidden.
        ->and($result['rows'])->toBeNull()
        ->and($result['summary'])->toBeNull();
});

it('detects the change from the DECLARED type, not from which value columns hold data', function (): void {
    // The distinction that keeps the refusal honest. A `number`-indexed field silently skips any answer that
    // is not numeric-like, so if mixed-type were inferred from populated columns, ONE unparseable answer
    // would masquerade as a schema change and refuse a perfectly good aggregate.
    $version = indexedForm('score', FieldType::ShortText, IndexedDataType::Number);

    submitIndexed($version, ['score' => '10']);
    submitIndexed($version, ['score' => '20']);
    submitIndexed($version, ['score' => 'not a number']); // projects NOTHING, by design

    $result = $this->aggregator->aggregate(wideRange(), $this->user, 'score');

    expect($result['refused'])->toBeFalse()
        ->and($result['summary']['count'])->toBe(2)
        ->and($result['summary']['average'])->toBe(15.0)
        // ...and the dropped row shows up as COVERAGE, which is exactly where §D3(iii) says it belongs.
        ->and($result['coverage'])->toBe(['indexed' => 2, 'countable' => 3]);
});

/*
|--------------------------------------------------------------------------
| §D3 -- the disclosures
*/

it('says plainly that a question was never indexed, rather than drawing an empty chart', function (): void {
    // The ordinary state of essentially every form alive today: is_queryable defaults false in the migration,
    // in FormBuilderService, in every seeder, and is hard-coded false by FieldLibrary. There is no backfill.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Plain');
    addFormField($form->draftVersion, $this->user, 'colour', FieldType::ShortText, 0);
    $version = app(PublishService::class)->publish($form->refresh(), $this->user);

    submitIndexed($version, ['colour' => 'red']);

    $result = $this->aggregator->aggregate(wideRange(), $this->user, 'colour');

    expect($result['refused'])->toBeTrue()
        ->and($result['reason'])->toBe('not_indexed')
        ->and($result['first_indexed_version'])->toBeNull()
        // Coverage is still reported: it is what tells an author there IS data, just not indexed data.
        ->and($result['coverage']['countable'])->toBe(1);
});

it('discloses the version a question became reportable from', function (): void {
    // §D3: turning the flag on indexes FUTURE submissions only, so the disclosure is the difference between
    // "this chart covers your data" and "this chart covers your data since version 2".
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Late');
    addFormField($form->draftVersion, $this->user, 'colour', FieldType::ShortText, 0);
    $v1 = app(PublishService::class)->publish($form->refresh(), $this->user);
    submitIndexed($v1, ['colour' => 'red']);

    $v2 = republishWithType($form, 'colour', IndexedDataType::Text);
    submitIndexed($v2, ['colour' => 'blue']);

    $result = $this->aggregator->aggregate(wideRange(), $this->user, 'colour');

    expect($result['refused'])->toBeFalse()
        ->and($result['first_indexed_version'])->toBe($v2->version_number)
        ->and($result['rows'])->toBe([['key' => 'blue', 'count' => 1]])
        // Two countable submissions, one indexed. The gap IS the point of the disclosure.
        ->and($result['coverage'])->toBe(['indexed' => 1, 'countable' => 2]);
});

it('declares a boolean breakdown as running on an unindexed column', function (): void {
    // value_boolean is the one value column with no B-tree (ADR-0011 Consequences). §D7 authorises ONE index
    // addendum and it was spent on the time-ordered index, so the cost is DECLARED rather than hidden.
    $version = indexedForm('agreed', FieldType::YesNo, IndexedDataType::Boolean);
    submitIndexed($version, ['agreed' => 'yes']);

    $result = $this->aggregator->aggregate(wideRange(), $this->user, 'agreed');

    expect($result['refused'])->toBeFalse()
        ->and($result['indexed_column'])->toBeFalse();

    // A text question, by contrast, runs on an indexed column.
    $text = indexedForm('colour', FieldType::ShortText, IndexedDataType::Text, 'Other');
    submitIndexed($text, ['colour' => 'red']);

    expect($this->aggregator->aggregate(wideRange(), $this->user, 'colour')['indexed_column'])->toBeTrue();
});

it('summarises a numeric question with a median the plain aggregates cannot express', function (): void {
    $version = indexedForm('score', FieldType::Integer, IndexedDataType::Number);

    foreach (['1', '2', '3', '100'] as $score) {
        submitIndexed($version, ['score' => $score]);
    }

    $summary = $this->aggregator->aggregate(wideRange(), $this->user, 'score')['summary'];

    expect($summary['count'])->toBe(4)
        ->and($summary['min'])->toBe(1.0)
        ->and($summary['max'])->toBe(100.0)
        ->and($summary['average'])->toBe(26.5)
        // The median is the reason percentile_cont is here: it is unmoved by the outlier the mean chases.
        ->and($summary['median'])->toBe(2.5);
});

/*
|--------------------------------------------------------------------------
| The question catalogue -- H24b's picker source
*/

it('lists reportable questions and refuses the rest with a reason each', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Mixed');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'colour', FieldType::ShortText, 0, [
        'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text,
    ]);
    addFormField($draft, $this->user, 'plain', FieldType::ShortText, 1);
    // Flagged, but the type can never project -- the false promise §D3(ii) requires the surface to refuse.
    // A media field rather than a grid: same StructuredAnswer refusal, and it needs no row/column config to
    // get past StructuralValidationGate, so the test exercises the refusal rather than the publish gate.
    addFormField($draft, $this->user, 'scan', FieldType::FileUpload, 2, [
        'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text,
    ]);
    addFormField($draft, $this->user, 'where', FieldType::Geopoint, 3);
    app(PublishService::class)->publish($form->refresh(), $this->user);

    $questions = collect($this->aggregator->questions(wideRange(), $this->user))->keyBy('key');

    expect($questions['colour']['reportable'])->toBeTrue()
        ->and($questions['colour']['first_indexed_version'])->toBe(1)
        ->and($questions['plain']['reportable'])->toBeFalse()
        ->and($questions['plain']['refusal'])->toBe('not_flagged')
        ->and($questions['scan']['reportable'])->toBeFalse()
        ->and($questions['scan']['refusal'])->toBe('structured_answer')
        // Geo is its own refusal: excluded from THIS index but not unindexed, so the copy must not say
        // "cannot be reported on".
        ->and($questions['where']['refusal'])->toBe('geospatial_answer');
});

it('scopes the catalogue and the aggregate to the user visible forms', function (): void {
    $mine = indexedForm('colour', FieldType::ShortText, IndexedDataType::Text, 'Mine');
    submitIndexed($mine, ['colour' => 'red']);

    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');
    app(ResourceGrantResolver::class)->forget();

    // Authorization is a SEPARATE rule from §D2's countable predicate, and just as easy to drop when the
    // query root is not Submission.
    expect($this->aggregator->questions(wideRange(), $editor))->toBe([])
        ->and($this->aggregator->aggregate(wideRange(), $editor, 'colour')['coverage'])
        ->toBe(['indexed' => 0, 'countable' => 0]);
});
