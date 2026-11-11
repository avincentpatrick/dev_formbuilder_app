<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Explorer\ExplorerQueryRunner;
use App\Services\Explorer\ExplorerResultShaper;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Explorer\ExplorerQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M235 — the data explorer's engine (ADR-0025 §D3–§D6).
|
| Every case here runs as `meridian_app` under the tenant GUC, which is the path the page uses. The numbers
| are small on purpose and written out in each case, so a wrong total cannot hide behind a seeder.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A published form whose questions cover every kind the answers source reads, plus two it must not offer.
 */
function explorerForm(Tenant $tenant, User $owner, string $title = 'Clinic intake'): Form
{
    $form = app(FormService::class)->create($tenant, $owner, $title);
    $version = $form->draftVersion;

    addFormField($version, $owner, 'age', FieldType::Integer, 0);
    addFormField($version, $owner, 'sex', FieldType::SingleSelect, 1, ['config' => ['options' => [
        ['value' => 'f', 'label' => 'Female'], ['value' => 'm', 'label' => 'Male'],
    ]]]);
    addFormField($version, $owner, 'symptoms', FieldType::MultiSelect, 2, ['config' => ['options' => [
        ['value' => 'fever', 'label' => 'Fever'], ['value' => 'cough', 'label' => 'Cough'],
    ]]]);
    addFormField($version, $owner, 'visit', FieldType::Date, 3);
    addFormField($version, $owner, 'consent', FieldType::YesNo, 4);
    addFormField($version, $owner, 'district', FieldType::ShortText, 5);
    addFormField($version, $owner, 'patient_name', FieldType::ShortText, 6, ['is_pii' => true]);
    addFormField($version, $owner, 'diagnosis', FieldType::ShortText, 7, ['is_sensitive' => true]);
    addFormField($version, $owner, 'notes', FieldType::LongText, 8);
    addFormField($version, $owner, 'photo', FieldType::FileUpload, 9);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/**
 * One countable response at a stated instant, with its answers.
 *
 * @param  array<string, mixed>  $answers
 */
function explorerResponse(
    Form $form,
    string $submittedAt,
    array $answers = [],
    SubmissionStatus $status = SubmissionStatus::Submitted,
    SubmissionSource $source = SubmissionSource::Guest,
): Submission {
    $version = FormVersion::findOrFail($form->current_published_version_id);

    $submission = Submission::factory()->forVersion($version)->create([
        'status' => $status,
        'source' => $source,
        'respondent_user_id' => null,
        'submitted_at' => CarbonImmutable::parse($submittedAt, 'UTC'),
    ]);

    SubmissionAnswer::factory()->forSubmission($submission)->create(['answers' => $answers]);

    return $submission;
}

/**
 * Run a declaration as a user. The range covers the whole of March 2026, in UTC, unless overridden.
 *
 * @param  array<string, mixed>  $definition
 * @return array<string, mixed>
 */
function explore(User $user, array $definition): array
{
    return app(ExplorerQueryRunner::class)->run(ExplorerQuery::fromArray([
        'chart' => 'table',
        'dimensions' => [],
        'measures' => [['aggregate' => 'count']],
        'filters' => [],
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'timezone' => 'UTC',
        ...$definition,
    ]), $user);
}

/**
 * A result's first series as label → value, which is how a person reads a one-value chart.
 *
 * @param  array<string, mixed>  $result
 * @return array<string, int|float|null>
 */
function explorerPairs(array $result, int $series = 0): array
{
    return array_combine(
        array_column($result['categories'], 'label'),
        $result['series'][$series]['values'],
    );
}

function explorerRunRefusal(User $user, array $definition): string
{
    try {
        explore($user, $definition);
    } catch (InvalidExplorerQueryException $e) {
        return $e->reason();
    }

    return 'ran';
}

// ── Responses ────────────────────────────────────────────────────────────────────────────────────────────

it('counts responses by status under the countable predicate: no draft, no deleted response', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', status: SubmissionStatus::Submitted);
    explorerResponse($form, '2026-03-03 10:00', status: SubmissionStatus::Submitted);
    explorerResponse($form, '2026-03-04 10:00', status: SubmissionStatus::Approved);
    explorerResponse($form, '2026-03-05 10:00', status: SubmissionStatus::Draft);
    explorerResponse($form, '2026-03-06 10:00', status: SubmissionStatus::Approved)->delete();

    $result = explore($this->owner, ['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'status']]]);

    expect(explorerPairs($result))->toBe(['Submitted' => 2, 'Approved' => 1])
        ->and($result['totals']['rows'])->toBe(3)
        ->and($result['measures'][0]['label'])->toBe('Count of responses');
});

it('names a form by its title, a deleted form included', function (): void {
    $kept = explorerForm($this->tenant, $this->owner, 'Kept');
    $gone = explorerForm($this->tenant, $this->owner, 'Gone');

    explorerResponse($kept, '2026-03-02 10:00');
    explorerResponse($gone, '2026-03-02 10:00');
    explorerResponse($gone, '2026-03-03 10:00');
    $gone->delete();

    $result = explore($this->owner, ['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'form']]]);

    expect(explorerPairs($result))->toBe(['Gone' => 2, 'Kept' => 1]);
});

it('applies the range as a half-open pair of instants in the declared zone', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    // Manila is UTC+8. Its 1 March opens at 28 Feb 16:00Z and its 31 March closes at 31 Mar 16:00Z.
    explorerResponse($form, '2026-02-28 15:59:59'); // 28 Feb in Manila: out
    explorerResponse($form, '2026-02-28 16:00:00'); // 1 Mar in Manila: in
    explorerResponse($form, '2026-03-31 15:59:59'); // 31 Mar in Manila: in
    explorerResponse($form, '2026-03-31 16:00:00'); // 1 Apr in Manila: out

    $rows = fn (string $from, string $to, string $timezone): int => explore($this->owner, [
        'source' => 'responses', 'from' => $from, 'to' => $to, 'timezone' => $timezone,
    ])['totals']['rows'];

    expect($rows('2026-03-01', '2026-03-31', 'Asia/Manila'))->toBe(2)
        // The same four instants, read in UTC: two fall on 28 February and two on 31 March.
        ->and($rows('2026-02-28', '2026-02-28', 'UTC'))->toBe(2)
        ->and($rows('2026-02-28', '2026-02-28', 'Asia/Manila'))->toBe(1)
        ->and($rows('2026-04-01', '2026-04-01', 'Asia/Manila'))->toBe(1)
        ->and($rows('2026-04-01', '2026-04-01', 'UTC'))->toBe(0);
});

it('groups a timeline by the reader\'s day, not the server\'s, and fills the days nothing happened', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-01 23:30'); // 2 March, 07:30 in Manila
    explorerResponse($form, '2026-03-02 01:00'); // 2 March, 09:00 in Manila
    explorerResponse($form, '2026-03-03 17:00'); // 4 March, 01:00 in Manila

    $result = explore($this->owner, [
        'source' => 'responses',
        'chart' => 'line',
        'dimensions' => [['field' => 'submitted_at', 'bucket' => 'day']],
        'from' => '2026-03-01',
        'to' => '2026-03-05',
        'timezone' => 'Asia/Manila',
    ]);

    expect(explorerPairs($result))->toBe([
        '1 Mar 2026' => 0,
        '2 Mar 2026' => 2,
        '3 Mar 2026' => 0,
        '4 Mar 2026' => 1,
        '5 Mar 2026' => 0,
    ]);
});

it('starts a week on Monday and labels a month, a quarter and a year', function (): void {
    $form = explorerForm($this->tenant, $this->owner);
    explorerResponse($form, '2026-03-04 10:00'); // a Wednesday

    // The periods that hold the response. Read off the two lists by index: a label such as "2026" would
    // become an integer if it were used as an array key.
    $by = function (string $bucket): array {
        $result = explore($this->owner, [
            'source' => 'responses',
            'chart' => 'column',
            'dimensions' => [['field' => 'submitted_at', 'bucket' => $bucket]],
        ]);

        $labels = [];
        foreach ($result['series'][0]['values'] as $index => $value) {
            if ($value > 0) {
                $labels[] = $result['categories'][$index]['label'];
            }
        }

        return $labels;
    };

    expect($by('week'))->toBe(['Week of 2 Mar 2026'])
        ->and($by('month'))->toBe(['Mar 2026'])
        ->and($by('quarter'))->toBe(['Q1 2026'])
        ->and($by('year'))->toBe(['2026'])
        ->and($by('weekday'))->toBe(['Wednesday'])
        ->and($by('hour'))->toBe(['10:00']);
});

it('always shows all seven weekdays and all twenty-four hours', function (): void {
    $form = explorerForm($this->tenant, $this->owner);
    explorerResponse($form, '2026-03-04 10:00');

    $weekdays = explore($this->owner, ['source' => 'responses', 'chart' => 'column', 'dimensions' => [['field' => 'submitted_at', 'bucket' => 'weekday']]]);
    $hours = explore($this->owner, ['source' => 'responses', 'chart' => 'column', 'dimensions' => [['field' => 'submitted_at', 'bucket' => 'hour']]]);

    expect(array_column($weekdays['categories'], 'label'))->toBe(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
        ->and($hours['categories'])->toHaveCount(24)
        ->and(array_sum($hours['series'][0]['values']))->toBe(1);
});

it('refuses a timeline with more periods than a chart can show, before running it', function (): void {
    expect(explorerRunRefusal($this->owner, [
        'source' => 'responses',
        'chart' => 'line',
        'dimensions' => [['field' => 'submitted_at', 'bucket' => 'day']],
        'from' => '2025-01-01',
        'to' => '2026-12-31',
    ]))->toBe('too_many_periods');
});

it('splits by a second field into series, with a zero where a pairing never happened', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', status: SubmissionStatus::Submitted, source: SubmissionSource::Guest);
    explorerResponse($form, '2026-03-02 11:00', status: SubmissionStatus::Submitted, source: SubmissionSource::Guest);
    explorerResponse($form, '2026-03-02 12:00', status: SubmissionStatus::Submitted, source: SubmissionSource::Manual);
    explorerResponse($form, '2026-03-02 13:00', status: SubmissionStatus::Approved, source: SubmissionSource::Manual);

    $result = explore($this->owner, [
        'source' => 'responses',
        'chart' => 'column',
        'dimensions' => [['field' => 'status'], ['field' => 'source']],
    ]);

    $series = array_column($result['series'], 'values', 'label');

    expect(array_column($result['categories'], 'label'))->toBe(['Submitted', 'Approved'])
        ->and($series)->toBe([
            SubmissionSource::Guest->label() => [2, 0],
            SubmissionSource::Manual->label() => [1, 1],
        ]);
});

it('folds the groups past the limit into one Other bucket that says how many it stands for', function (): void {
    foreach (['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'E' => 1] as $title => $count) {
        $form = explorerForm($this->tenant, $this->owner, "Form {$title}");

        for ($i = 0; $i < $count; $i++) {
            explorerResponse($form, '2026-03-02 10:00');
        }
    }

    $result = explore($this->owner, ['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'form']], 'limit' => 2]);

    expect(explorerPairs($result))->toBe(['Form A' => 5, 'Form B' => 4, 'Other (3)' => 6])
        ->and($result['categories'][2])->toBe(['key' => ExplorerResultShaper::OTHER_KEY, 'label' => 'Other (3)', 'other' => true])
        ->and($result['meta']['groups'])->toBe(5)
        ->and($result['meta']['hidden_groups'])->toBe(3);
});

it('gives the Other bucket no value where one cannot be computed from the groups it holds', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    foreach (['north' => [10, 20], 'south' => [30], 'east' => [50], 'west' => [70]] as $district => $ages) {
        foreach ($ages as $age) {
            explorerResponse($form, '2026-03-02 10:00', ['district' => $district, 'age' => $age]);
        }
    }

    $result = explore($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'chart' => 'column',
        'dimensions' => [['field' => 'answer:district']],
        'measures' => [['aggregate' => 'count'], ['aggregate' => 'avg', 'field' => 'answer:age']],
        'limit' => 1,
    ]);

    expect(explorerPairs($result, 0))->toBe(['north' => 2, 'Other (3)' => 3])
        // The average of the three folded districts is not derivable from their three averages.
        ->and(explorerPairs($result, 1))->toBe(['north' => 15.0, 'Other (3)' => null])
        ->and($result['measures'][1]['additive'])->toBeFalse();
});

it('sorts groups by value in either direction, or by name', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    foreach (['beta' => 3, 'alpha' => 1, 'gamma' => 2] as $district => $count) {
        for ($i = 0; $i < $count; $i++) {
            explorerResponse($form, '2026-03-02 10:00', ['district' => $district]);
        }
    }

    $labels = fn (string $sort): array => array_column(explore($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'chart' => 'bar',
        'dimensions' => [['field' => 'answer:district']],
        'sort' => $sort,
    ])['categories'], 'label');

    expect($labels('value_desc'))->toBe(['beta', 'gamma', 'alpha'])
        ->and($labels('value_asc'))->toBe(['alpha', 'gamma', 'beta'])
        ->and($labels('label'))->toBe(['alpha', 'beta', 'gamma']);
});

// ── Answers ──────────────────────────────────────────────────────────────────────────────────────────────

it('charts a choice question by the labels its author wrote, with the blank answers as their own group', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f']);
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f']);
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'm']);
    explorerResponse($form, '2026-03-02 10:00', []);

    $result = explore($this->owner, ['source' => 'answers', 'form_id' => $form->id, 'chart' => 'donut', 'dimensions' => [['field' => 'answer:sex']]]);

    expect(explorerPairs($result))->toBe(['Female' => 2, 'Male' => 1, '(empty)' => 1]);
});

it('counts a multiple-choice answer once per chosen option, and says so', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', ['symptoms' => ['fever', 'cough']]);
    explorerResponse($form, '2026-03-02 10:00', ['symptoms' => ['fever']]);
    explorerResponse($form, '2026-03-02 10:00', ['symptoms' => []]);
    explorerResponse($form, '2026-03-02 10:00', ['symptoms' => 'fever']); // not a list: contributes nothing

    $result = explore($this->owner, ['source' => 'answers', 'form_id' => $form->id, 'chart' => 'bar', 'dimensions' => [['field' => 'answer:symptoms']]]);

    expect(explorerPairs($result))->toBe(['Fever' => 2, 'Cough' => 1])
        // The total is still responses, not selections.
        ->and($result['totals']['rows'])->toBe(4)
        ->and(implode(' ', $result['meta']['notes']))->toContain('counted once for every choice');
});

it('reads a number however it was stored, and counts the answers it could not read instead of failing', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', ['age' => 30]);
    explorerResponse($form, '2026-03-02 10:00', ['age' => '40']);
    explorerResponse($form, '2026-03-02 10:00', ['age' => '50.5']);
    explorerResponse($form, '2026-03-02 10:00', ['age' => 'forty']);
    explorerResponse($form, '2026-03-02 10:00', ['age' => '']);
    explorerResponse($form, '2026-03-02 10:00', ['age' => ['x']]);
    explorerResponse($form, '2026-03-02 10:00', []);

    $result = explore($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'chart' => 'number',
        'measures' => [
            ['aggregate' => 'sum', 'field' => 'answer:age'],
            ['aggregate' => 'avg', 'field' => 'answer:age'],
            ['aggregate' => 'median', 'field' => 'answer:age'],
        ],
    ]);

    expect($result['totals']['values'])->toBe([120.5, 40.1667, 40.0])
        ->and($result['totals']['rows'])->toBe(7)
        // Three of the seven responses held a number: that is the coverage the page prints.
        ->and($result['totals']['coverage'])->toBe([3, 3, 3]);
});

it('groups a date question by period and survives a date that does not exist', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', ['visit' => '2026-01-15']);
    explorerResponse($form, '2026-03-02 10:00', ['visit' => '2026-01-20']);
    explorerResponse($form, '2026-03-02 10:00', ['visit' => '2026-03-01']);
    explorerResponse($form, '2026-03-02 10:00', ['visit' => '2026-02-30']); // passes the pattern, is not a date
    explorerResponse($form, '2026-03-02 10:00', ['visit' => 'yesterday']);

    $result = explore($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'chart' => 'column',
        'dimensions' => [['field' => 'answer:visit', 'bucket' => 'month']],
    ]);

    // A date a question collected can fall outside the chart's range, so its timeline runs from the first
    // month seen to the last — February is filled in — and the two unreadable answers sit after it.
    expect(explorerPairs($result))->toBe(['Jan 2026' => 2, 'Feb 2026' => 0, 'Mar 2026' => 1, '(empty)' => 2]);
});

it('filters on a choice, a yes/no, a number and a multiple choice together', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f', 'consent' => true, 'age' => 45, 'symptoms' => ['fever']]);  // matches
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f', 'consent' => true, 'age' => 45, 'symptoms' => ['cough']]);  // wrong symptom
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f', 'consent' => true, 'age' => 30, 'symptoms' => ['fever']]);  // too young
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f', 'consent' => false, 'age' => 45, 'symptoms' => ['fever']]); // no consent
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'm', 'consent' => true, 'age' => 45, 'symptoms' => ['fever']]);  // wrong sex

    $result = explore($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'filters' => [
            ['field' => 'answer:sex', 'operator' => 'in', 'values' => ['f']],
            ['field' => 'answer:consent', 'operator' => 'in', 'values' => ['true']],
            ['field' => 'answer:age', 'operator' => 'gte', 'values' => ['40']],
            ['field' => 'answer:symptoms', 'operator' => 'in', 'values' => ['fever']],
        ],
    ]);

    expect($result['totals']['rows'])->toBe(1);
});

it('keeps a blank answer under "is none of", which SQL alone would drop', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'f']);
    explorerResponse($form, '2026-03-02 10:00', ['sex' => 'm']);
    explorerResponse($form, '2026-03-02 10:00', []);

    $count = fn (array $filter): int => explore($this->owner, ['source' => 'answers', 'form_id' => $form->id, 'filters' => [$filter]])['totals']['rows'];

    expect($count(['field' => 'answer:sex', 'operator' => 'not_in', 'values' => ['f']]))->toBe(2)
        ->and($count(['field' => 'answer:sex', 'operator' => 'is_empty']))->toBe(1)
        ->and($count(['field' => 'answer:sex', 'operator' => 'not_empty']))->toBe(2);
});

it('never offers a question marked personal or sensitive, and refuses a chart that names one', function (): void {
    $form = explorerForm($this->tenant, $this->owner);
    explorerResponse($form, '2026-03-02 10:00', ['patient_name' => 'Ana Reyes', 'diagnosis' => 'flu', 'district' => 'north']);

    $result = explore($this->owner, ['source' => 'answers', 'form_id' => $form->id]);

    foreach (['answer:patient_name', 'answer:diagnosis'] as $field) {
        expect(explorerRunRefusal($this->owner, [
            'source' => 'answers',
            'form_id' => $form->id,
            'chart' => 'bar',
            'dimensions' => [['field' => $field]],
        ]))->toBe('unknown_field');
    }

    expect(implode(' ', $result['meta']['notes']))
        ->toContain('2 questions marked personal or sensitive are not offered here.')
        // The long-text and file questions are the two that carry an answer no chart can read.
        ->toContain('2 questions cannot be charted yet');
});

it('keeps a question withheld when ANY published version marked it personal', function (): void {
    $form = explorerForm($this->tenant, $this->owner);

    // Version 2 clears the flag on `patient_name`. The answers version 1 collected are still personal data.
    $draft = $form->refresh()->draftVersion;
    expect($draft)->not->toBeNull()->and($draft->version_number)->toBe(2);

    FormField::query()->where('form_version_id', $draft->id)->where('key', 'patient_name')->update(['is_pii' => false]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $latest = FormField::query()
        ->where('form_version_id', $form->refresh()->current_published_version_id)
        ->where('key', 'patient_name')
        ->firstOrFail();
    expect($latest->is_pii)->toBeFalse();

    expect(explorerRunRefusal($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'chart' => 'bar',
        'dimensions' => [['field' => 'answer:patient_name']],
    ]))->toBe('unknown_field');
});

it('reads only the named form\'s answers', function (): void {
    $one = explorerForm($this->tenant, $this->owner, 'One');
    $two = explorerForm($this->tenant, $this->owner, 'Two');

    explorerResponse($one, '2026-03-02 10:00', ['district' => 'north']);
    explorerResponse($two, '2026-03-02 10:00', ['district' => 'south']);
    explorerResponse($two, '2026-03-02 10:00', ['district' => 'south']);

    $result = explore($this->owner, ['source' => 'answers', 'form_id' => $two->id, 'chart' => 'bar', 'dimensions' => [['field' => 'answer:district']]]);

    expect(explorerPairs($result))->toBe(['south' => 2])
        ->and($result['meta']['form'])->toBe(['id' => $two->id, 'title' => 'Two']);
});

// ── What a declaration may not do ────────────────────────────────────────────────────────────────────────

it('refuses what the catalogue does not offer', function (array $definition, string $reason): void {
    $form = explorerForm($this->tenant, $this->owner);

    if (($definition['form_id'] ?? null) === 'FORM') {
        $definition['form_id'] = $form->id;
    }

    expect(explorerRunRefusal($this->owner, $definition))->toBe($reason);
})->with([
    'a source that does not exist' => [['source' => 'submissions'], 'unknown_source'],
    'a table name in place of a source' => [['source' => 'users'], 'unknown_source'],
    'a field that is not one' => [['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'guest_ip']]], 'unknown_field'],
    'a column smuggled in as a field' => [['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'submissions.guest_contact_email']]], 'unknown_field'],
    'SQL in a field key' => [['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'status) from submissions; --']]], 'unknown_field'],
    'an answer key that was never a question' => [['source' => 'answers', 'form_id' => 'FORM', 'chart' => 'bar', 'dimensions' => [['field' => "answer:x' or '1'='1"]]], 'unknown_field'],
    'a filter on a field that is not one' => [['source' => 'responses', 'filters' => [['field' => 'guest_token', 'operator' => 'not_empty']]], 'unknown_field'],
    'a sum of a field that is not one' => [['source' => 'responses', 'measures' => [['aggregate' => 'sum', 'field' => 'guest_ip']]], 'unknown_field'],
    'a date with no period' => [['source' => 'responses', 'chart' => 'line', 'dimensions' => [['field' => 'submitted_at']]], 'bucket_required'],
    'a period on something that is not a date' => [['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'status', 'bucket' => 'day']]], 'bucket_not_allowed'],
    'a group-by on a number' => [['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'completeness']]], 'field_not_groupable'],
    'a sum of a status' => [['source' => 'responses', 'measures' => [['aggregate' => 'sum', 'field' => 'status']]], 'field_not_aggregable'],
    'a number comparison on a status' => [['source' => 'responses', 'filters' => [['field' => 'status', 'operator' => 'gte', 'values' => ['1']]]], 'operator_not_allowed'],
    'a comparison with a word' => [['source' => 'responses', 'filters' => [['field' => 'completeness', 'operator' => 'gte', 'values' => ['lots']]]], 'filter_needs_value'],
    'a membership filter with no values' => [['source' => 'responses', 'filters' => [['field' => 'status', 'operator' => 'in', 'values' => []]]], 'filter_needs_value'],
    'answers with no form' => [['source' => 'answers'], 'form_required'],
    'answers of a form that does not exist' => [['source' => 'answers', 'form_id' => '019a0000-0000-7000-8000-000000000000'], 'form_unavailable'],
    'answers of something that is not an id' => [['source' => 'answers', 'form_id' => "x' or true --"], 'form_unavailable'],
    'the hour of a date with no time' => [['source' => 'answers', 'form_id' => 'FORM', 'chart' => 'column', 'dimensions' => [['field' => 'answer:visit', 'bucket' => 'hour']]], 'bucket_not_allowed'],
]);

it('binds a filter\'s values: a quote in one is a value, never SQL', function (): void {
    $form = explorerForm($this->tenant, $this->owner);
    explorerResponse($form, '2026-03-02 10:00', ['district' => "O'Brien"]);
    explorerResponse($form, '2026-03-02 10:00', ['district' => 'north']);

    $result = explore($this->owner, [
        'source' => 'answers',
        'form_id' => $form->id,
        'filters' => [['field' => 'answer:district', 'operator' => 'in', 'values' => ["O'Brien", "x') or true --"]]],
    ]);

    expect($result['totals']['rows'])->toBe(1);
});

// ── Whose rows ───────────────────────────────────────────────────────────────────────────────────────────

it('shows a member without org-wide access only the forms they hold a grant on', function (): void {
    $granted = explorerForm($this->tenant, $this->owner, 'Granted');
    $other = explorerForm($this->tenant, $this->owner, 'Other');

    explorerResponse($granted, '2026-03-02 10:00', ['district' => 'north']);
    explorerResponse($other, '2026-03-02 10:00', ['district' => 'south']);
    explorerResponse($other, '2026-03-03 10:00', ['district' => 'south']);

    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($granted, $reviewer, ResourceCapacity::Reviewer);

    $responses = explore($reviewer, ['source' => 'responses', 'chart' => 'bar', 'dimensions' => [['field' => 'form']]]);
    $forms = explore($reviewer, ['source' => 'forms', 'from' => '2024-04-01', 'to' => '2026-12-31']);

    expect(explorerPairs($responses))->toBe(['Granted' => 1])
        ->and($forms['totals']['rows'])->toBe(1)
        ->and(explore($reviewer, ['source' => 'answers', 'form_id' => $granted->id])['totals']['rows'])->toBe(1)
        // The form exists and holds two responses. To this reader it is not there.
        ->and(explorerRunRefusal($reviewer, ['source' => 'answers', 'form_id' => $other->id]))->toBe('form_unavailable');
});

it('shows a member with no grants nothing at all, never everything', function (): void {
    $form = explorerForm($this->tenant, $this->owner);
    explorerResponse($form, '2026-03-02 10:00');

    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    expect(explore($editor, ['source' => 'responses'])['totals']['rows'])->toBe(0)
        ->and(explore($editor, ['source' => 'forms', 'from' => '2024-04-01', 'to' => '2026-12-31'])['totals']['rows'])->toBe(0);
});

it('never reads another workspace\'s rows', function (): void {
    $form = explorerForm($this->tenant, $this->owner);
    explorerResponse($form, '2026-03-02 10:00', ['district' => 'north']);

    $elsewhere = inboxTenant('globex');
    $stranger = User::factory()->create();
    enterTenant($elsewhere->id, $stranger->id);
    makeActiveMember($stranger, 'owner');

    expect(explore($stranger, ['source' => 'responses'])['totals']['rows'])->toBe(0)
        ->and(explore($stranger, ['source' => 'activity', 'from' => '2024-04-01', 'to' => '2026-12-31', 'chart' => 'bar', 'dimensions' => [['field' => 'actor']]])['categories'])
        ->each(fn ($category) => $category->label->not->toBe($this->owner->name))
        ->and(explorerRunRefusal($stranger, ['source' => 'answers', 'form_id' => $form->id]))->toBe('form_unavailable');
});
