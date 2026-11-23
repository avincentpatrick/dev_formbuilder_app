<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Explorer\ExplorerPresenter;
use App\Services\Explorer\ExplorerQueryRunner;
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
| M250 (`agreement-report`, `D234`) — the agreement list (ADR-0025 §D10).
|
| Run as `meridian_app` under the tenant GUC, the page's own path. Every count is small and written out in its case,
| so a wrong share cannot hide behind a seeder. Helpers carry an `agreement` prefix: Pest's helpers are global, and a
| name another file declares fatals the full suite.
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

/** A published form with every kind of question an agreement list reads, and the kinds it must not offer. */
function agreementForm(Tenant $tenant, User $owner, string $title = 'Consultation', array $extra = []): Form
{
    $form = app(FormService::class)->create($tenant, $owner, $title);
    $version = $form->draftVersion;
    $view = [['value' => 'agree', 'label' => 'Agree'], ['value' => 'agree_changes', 'label' => 'Agree with changes'], ['value' => 'disagree', 'label' => 'Disagree'], ['value' => 'noview', 'label' => 'No view or opinion']];

    addFormField($version, $owner, 'q_view', FieldType::SingleSelect, 0, ['label' => 'Do you agree?', 'config' => ['options' => $view]]);
    addFormField($version, $owner, 'q_choice', FieldType::Dropdown, 1, ['label' => 'Which measure?', 'config' => ['options' => [
        ['value' => 'a', 'label' => 'Measure A'], ['value' => 'b', 'label' => 'Measure B'], ['value' => 'c', 'label' => 'Measure C'], ['value' => 'noview', 'label' => 'No view or opinion'],
    ]]]);
    addFormField($version, $owner, 'q_multi', FieldType::MultiSelect, 2, ['label' => 'Which matter most?', 'config' => ['options' => [
        ['value' => 'x', 'label' => 'Equal care'], ['value' => 'y', 'label' => 'Need'], ['value' => 'z', 'label' => 'Fair chance'], ['value' => 'noview', 'label' => 'No view or opinion'],
    ]]]);
    addFormField($version, $owner, 'q_scale', FieldType::LikertScale, 3, ['label' => 'How strongly?', 'config' => ['options' => [
        ['value' => '1', 'label' => 'Strongly disagree'], ['value' => '2', 'label' => 'Disagree'], ['value' => '3', 'label' => 'Neither'], ['value' => '4', 'label' => 'Agree'], ['value' => '5', 'label' => 'Strongly agree'],
    ]]]);
    addFormField($version, $owner, 'q_yes', FieldType::YesNo, 4, ['label' => 'Keep it?']);
    addFormField($version, $owner, 'q_text', FieldType::ShortText, 5);
    addFormField($version, $owner, 'q_private', FieldType::SingleSelect, 6, ['is_pii' => true, 'config' => ['options' => [['value' => 'a', 'label' => 'A']]]]);
    addFormField($version, $owner, 'table', FieldType::Matrix, 7, ['label' => 'Indicators', 'config' => [
        'rows' => [
            ['value' => 'r1', 'label' => 'Row one'],
            ['value' => 'r2', 'label' => 'Row two', 'not_needed' => ['cut']],
            ['value' => 'r3', 'label' => 'Row three'],
        ],
        'columns' => [
            ['value' => 'view', 'label' => 'Your view', 'type' => 'single_select', 'options' => array_slice($view, 0, 4)],
            ['value' => 'cut', 'label' => 'Cut-off', 'type' => 'single_select', 'options' => [['value' => 'use', 'label' => 'Use it'], ['value' => 'change', 'label' => 'Change it'], ['value' => 'noview', 'label' => 'No view or opinion']]],
            ['value' => 'why', 'label' => 'Why', 'type' => 'long_text'],
            ['value' => 'svc', 'label' => 'Services', 'type' => 'multi_select', 'options' => [['value' => 'lab', 'label' => 'Lab'], ['value' => 'xray', 'label' => 'X-ray']]],
        ],
    ]]);
    addFormField($version, $owner, 'lk', FieldType::LikertMatrix, 8, ['label' => 'Satisfaction', 'config' => [
        'rows' => [['value' => 'clean', 'label' => 'Cleanliness'], ['value' => 'staff', 'label' => 'Staff']],
        'columns' => [['value' => '1', 'label' => 'Poor'], ['value' => '4', 'label' => 'Good'], ['value' => '5', 'label' => 'Excellent']],
    ]]);

    foreach ($extra as $sequence => [$key, $type, $fieldExtra]) {
        addFormField($version, $owner, $key, $type, 20 + $sequence, $fieldExtra);
    }

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/**
 * One countable response in March 2026 (unless stated), with its answers.
 *
 * @param  array<string, mixed>  $answers
 */
function agreementResponse(Form $form, array $answers, string $at = '2026-03-10 09:00:00', SubmissionStatus $status = SubmissionStatus::Submitted): Submission
{
    $submission = Submission::factory()->forVersion(FormVersion::findOrFail($form->current_published_version_id))->create([
        'status' => $status,
        'respondent_user_id' => null,
        'submitted_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);

    SubmissionAnswer::factory()->forSubmission($submission)->create(['answers' => $answers]);

    return $submission;
}

/**
 * Count an agreement list over March 2026, leaving out "No view or opinion" at 75% and six views unless overridden.
 *
 * @param  array<string, mixed>  $agreement
 * @param  array<string, mixed>  $definition
 * @return array<string, mixed>
 */
function agreementList(User $user, Form $form, array $agreement, array $definition = []): array
{
    return app(ExplorerQueryRunner::class)->run(ExplorerQuery::fromArray([
        'source' => 'agreement',
        'form_id' => $form->id,
        'chart' => 'agreement',
        'dimensions' => [],
        'measures' => [],
        'filters' => [],
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'timezone' => 'UTC',
        'agreement' => ['leave_out' => ['noview'], 'agreeing' => [], 'threshold' => 75, 'min_views' => 6, ...$agreement],
        ...$definition,
    ]), $user);
}

/**
 * A list's lines by key, in the list's own order.
 *
 * @param  array<string, mixed>  $result
 * @return array<string, array<string, mixed>>
 */
function agreementLines(array $result): array
{
    return array_column($result['agreement']['items'], null, 'key');
}

/** @param  array<string, mixed>  $agreement */
function agreementRefusal(User $user, Form $form, array $agreement, array $definition = []): string
{
    try {
        agreementList($user, $form, $agreement, $definition);
    } catch (InvalidExplorerQueryException $e) {
        return $e->reason();
    }

    return 'ran';
}

// ── The share, the views and the status ───────────────────────────────────────────────────────────────────

it('leaves "No view" out of the views and short-lists an item at exactly 75% of at least six views', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    foreach ([...array_fill(0, 6, 'agree'), 'disagree', 'disagree', 'noview', 'noview'] as $answer) {
        agreementResponse($form, ['q_view' => $answer]);
    }

    $line = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_view']]))['q_view'];

    expect($line)->toMatchArray([
        'responses' => 10, 'views' => 8, 'left_out' => 2, 'agreeing' => 6, 'share' => 75, 'status' => 'short_list', 'basis' => 'most_common',
    ])->and($line['counted'])->toBe([['value' => 'agree', 'label' => 'Agree']])
        ->and(array_column($line['answers'], 'responses', 'value'))->toBe(['agree' => 6, 'agree_changes' => 0, 'disagree' => 2, 'noview' => 2]);

    // One point higher and the same six of eight is to be discussed — decided in whole numbers, never by a float.
    $higher = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_view'], 'threshold' => 76]))['q_view'];
    expect($higher['status'])->toBe('discuss')->and($higher['share'])->toBe(75);
});

it('says too few views below the minimum, and rounds a share down so it never reads at a threshold it missed', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    foreach (['a', 'a', 'b'] as $answer) {
        agreementResponse($form, ['q_choice' => $answer]);
    }

    $few = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_choice']]))['q_choice'];
    $enough = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_choice'], 'min_views' => 3, 'threshold' => 66]))['q_choice'];

    expect($few)->toMatchArray(['views' => 3, 'agreeing' => 2, 'share' => 66.6, 'status' => 'too_few'])
        ->and($enough)->toMatchArray(['share' => 66.6, 'status' => 'short_list']);
});

it('counts a response that left one question out as a view on another', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    agreementResponse($form, ['q_view' => 'noview', 'q_choice' => 'a']);

    $lines = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_view', 'answer:q_choice'], 'min_views' => 1]));

    expect($lines['q_view'])->toMatchArray(['responses' => 1, 'views' => 0, 'left_out' => 1, 'share' => null, 'status' => 'too_few'])
        ->and($lines['q_choice'])->toMatchArray(['responses' => 1, 'views' => 1, 'share' => 100, 'status' => 'short_list']);
});

it('reads a several-answer question: "No view" alone is no view, and the share is of those who ticked its most common choice', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    foreach ([['x', 'y'], ['x'], ['noview'], ['y'], ['x'], ['z']] as $answer) {
        agreementResponse($form, ['q_multi' => $answer]);
    }

    $result = agreementList($this->owner, $form, ['items' => ['answer:q_multi'], 'min_views' => 1, 'threshold' => 60]);
    $line = agreementLines($result)['q_multi'];

    expect($line)->toMatchArray(['multiple' => true, 'responses' => 6, 'views' => 5, 'left_out' => 1, 'agreeing' => 3, 'share' => 60, 'status' => 'short_list'])
        ->and(array_column($line['answers'], 'responses', 'value'))->toBe(['x' => 3, 'y' => 2, 'z' => 1, 'noview' => 1])
        ->and($result['meta']['notes'])->toContain('A question that takes several answers counts the people who ticked its most common choice, out of those who gave a view.');
});

it('counts the answers a list marks as agreeing together, only on an item that offers one of them', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    foreach ([['5', 'a'], ['5', 'a'], ['4', 'b'], ['4', 'b'], ['3', 'c'], ['2', 'a']] as [$scale, $choice]) {
        agreementResponse($form, ['q_scale' => $scale, 'q_choice' => $choice]);
    }

    $lines = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_scale', 'answer:q_choice'], 'agreeing' => ['4', '5'], 'threshold' => 60]));

    expect($lines['q_scale'])->toMatchArray(['basis' => 'agreeing', 'agreeing' => 4, 'views' => 6, 'share' => 66.6, 'status' => 'short_list'])
        ->and($lines['q_scale']['counted'])->toBe([['value' => '4', 'label' => 'Agree'], ['value' => '5', 'label' => 'Strongly agree']])
        // The choice question offers neither 4 nor 5, so its share is its most common answer's.
        ->and($lines['q_choice'])->toMatchArray(['basis' => 'most_common', 'agreeing' => 3, 'share' => 50, 'status' => 'discuss'])
        ->and($lines['q_choice']['counted'])->toBe([['value' => 'a', 'label' => 'Measure A']]);
});

it('breaks a tie for most common in favour of the answer the author listed first', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    foreach (['c', 'b', 'c', 'b'] as $answer) {
        agreementResponse($form, ['q_choice' => $answer]);
    }

    $line = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_choice'], 'min_views' => 1]))['q_choice'];

    expect($line['counted'])->toBe([['value' => 'b', 'label' => 'Measure B']])->and($line['share'])->toBe(50);
});

it('reads a yes/no as Yes and No', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    foreach ([true, true, false] as $answer) {
        agreementResponse($form, ['q_yes' => $answer]);
    }

    $line = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_yes'], 'min_views' => 1]))['q_yes'];

    expect($line['counted'])->toBe([['value' => 'true', 'label' => 'Yes']])->and($line['share'])->toBe(66.6);
});

// ── Question tables and Likert grids ─────────────────────────────────────────────────────────────────────

it('puts every row of a question table column on the list in row order, never a "Not needed" row, and never counts a blank cell', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    agreementResponse($form, ['table' => ['r1' => ['view' => 'agree', 'cut' => 'use', 'why' => 'fine'], 'r2' => ['view' => 'disagree'], 'r3' => ['view' => 'agree']]]);
    agreementResponse($form, ['table' => ['r1' => ['view' => 'agree', 'cut' => 'change', 'svc' => ['lab', 'xray']], 'r3' => ['view' => 'noview']]]);

    $result = agreementList($this->owner, $form, ['items' => ['grid:table:cut', 'grid:table:view', 'grid:table:svc'], 'min_views' => 1]);
    $lines = agreementLines($result);

    expect(collect($result['agreement']['items'])->sortBy('position')->pluck('key')->all())->toBe([
        'table.r1.view', 'table.r2.view', 'table.r3.view', 'table.r1.cut', 'table.r3.cut', 'table.r1.svc', 'table.r2.svc', 'table.r3.svc',
    ])
        ->and($lines)->not->toHaveKey('table.r2.cut')
        ->and($lines['table.r1.view'])->toMatchArray(['label' => 'Row one', 'question' => 'Indicators', 'column' => 'Your view', 'views' => 2, 'share' => 100])
        ->and($lines['table.r3.view'])->toMatchArray(['responses' => 2, 'views' => 1, 'left_out' => 1])
        ->and($lines['table.r1.cut'])->toMatchArray(['views' => 2, 'share' => 50, 'status' => 'discuss'])
        ->and($lines['table.r3.cut'])->toMatchArray(['responses' => 0, 'views' => 0, 'status' => 'too_few'])
        ->and($lines['table.r1.svc'])->toMatchArray(['multiple' => true, 'views' => 1, 'agreeing' => 1]);
});

it('reads a Likert grid row by row, whether a score was stored as text or as a number', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    agreementResponse($form, ['lk' => ['clean' => '5', 'staff' => '4']]);
    agreementResponse($form, ['lk' => ['clean' => 5, 'staff' => '1']]);
    agreementResponse($form, ['lk' => ['clean' => '4']]);

    $lines = agreementLines(agreementList($this->owner, $form, ['items' => ['grid:lk'], 'min_views' => 1, 'agreeing' => ['4', '5']]));

    expect($lines['lk.clean'])->toMatchArray(['label' => 'Cleanliness', 'views' => 3, 'agreeing' => 3, 'share' => 100])
        ->and(array_column($lines['lk.clean']['answers'], 'responses', 'value'))->toBe(['1' => 0, '4' => 1, '5' => 2])
        ->and($lines['lk.staff'])->toMatchArray(['views' => 2, 'agreeing' => 1, 'share' => 50]);
});

// ── The order a meeting reads it in ──────────────────────────────────────────────────────────────────────

it('lists the short list highest first, then the items to discuss most-disagreed first, then too few views, ties in form order', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    // With "5" counted as agreeing: q_view offers no 5, so its top answer counts — 4 of 4 (100%); lk.clean 3 of 4 gave 5
    // (75%); lk.staff 1 of 4 (25%, though 3 of 4 gave 1); q_choice's top answer 2 of 4 (50%); q_scale 2 of 4 gave 5 (50%,
    // a tie with q_choice, after it in the form); q_yes has one view only.
    $rows = [
        ['q_view' => 'agree', 'lk' => ['clean' => '5', 'staff' => '1'], 'q_choice' => 'a', 'q_scale' => '5', 'q_yes' => true],
        ['q_view' => 'agree', 'lk' => ['clean' => '5', 'staff' => '1'], 'q_choice' => 'a', 'q_scale' => '5'],
        ['q_view' => 'agree', 'lk' => ['clean' => '5', 'staff' => '1'], 'q_choice' => 'b', 'q_scale' => '2'],
        ['q_view' => 'agree', 'lk' => ['clean' => '1', 'staff' => '5'], 'q_choice' => 'c', 'q_scale' => '1'],
    ];
    foreach ($rows as $answers) {
        agreementResponse($form, $answers);
    }

    $result = agreementList($this->owner, $form, [
        'items' => ['answer:q_yes', 'answer:q_scale', 'grid:lk', 'answer:q_choice', 'answer:q_view'],
        'agreeing' => ['5'], 'min_views' => 2, 'threshold' => 75,
    ]);

    expect(array_column($result['agreement']['items'], 'key'))->toBe(['q_view', 'lk.clean', 'lk.staff', 'q_choice', 'q_scale', 'q_yes'])
        ->and(array_column($result['agreement']['items'], 'status'))->toBe(['short_list', 'short_list', 'discuss', 'discuss', 'discuss', 'too_few'])
        ->and($result['agreement']['summary'])->toBe(['items' => 6, 'short_list' => 2, 'discuss' => 3, 'too_few' => 1])
        ->and($result['totals']['rows'])->toBe(4);
});

// ── What is counted, and what is offered ─────────────────────────────────────────────────────────────────

it('narrows the list by an answer filter and the range, and never counts a draft, another month or another form', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    $other = agreementForm($this->tenant, $this->owner, 'Another consultation');
    agreementResponse($form, ['q_view' => 'agree', 'q_choice' => 'a']);
    agreementResponse($form, ['q_view' => 'agree', 'q_choice' => 'b']);
    agreementResponse($form, ['q_view' => 'disagree', 'q_choice' => 'a']);
    agreementResponse($form, ['q_view' => 'disagree', 'q_choice' => 'a'], '2026-04-02 09:00:00');
    agreementResponse($form, ['q_view' => 'disagree', 'q_choice' => 'a'], '2026-03-11 09:00:00', SubmissionStatus::Draft);
    agreementResponse($other, ['q_view' => 'disagree']);

    $all = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_view'], 'min_views' => 1]))['q_view'];
    $filtered = agreementList($this->owner, $form, ['items' => ['answer:q_view'], 'min_views' => 1], [
        'filters' => [['field' => 'answer:q_choice', 'operator' => 'in', 'values' => ['a']]],
    ]);

    expect($all)->toMatchArray(['views' => 3, 'agreeing' => 2])
        ->and(agreementLines($filtered)['q_view'])->toMatchArray(['views' => 2, 'agreeing' => 1, 'share' => 50])
        ->and($filtered['totals']['rows'])->toBe(2);
});

it('offers choice questions, choice columns and Likert grids, and not text, long-text columns or a personal question', function (): void {
    $form = agreementForm($this->tenant, $this->owner);

    $fields = app(ExplorerPresenter::class)->fields($this->owner, 'agreement', $form->id);

    expect(array_column($fields['items'], 'value'))->toBe([
        'answer:q_view', 'answer:q_choice', 'answer:q_multi', 'answer:q_scale', 'answer:q_yes', 'grid:table:view', 'grid:table:cut', 'grid:table:svc', 'grid:lk',
    ])
        ->and(collect($fields['items'])->firstWhere('value', 'grid:table:cut'))->toMatchArray(['label' => 'Cut-off', 'question' => 'Indicators', 'kind' => 'table_column', 'rows' => 2])
        ->and(collect($fields['items'])->firstWhere('value', 'answer:q_multi')['multiple'])->toBeTrue()
        ->and($fields['notes'])->toContain('1 question marked personal or sensitive is not offered here.')
        ->and(agreementRefusal($this->owner, $form, ['items' => ['answer:q_text']]))->toBe('unknown_item')
        ->and(agreementRefusal($this->owner, $form, ['items' => ['answer:q_private']]))->toBe('unknown_item')
        ->and(agreementRefusal($this->owner, $form, ['items' => ['grid:table:why']]))->toBe('unknown_item');
});

it('refuses a list that expands past 500 items', function (): void {
    $rows = array_map(static fn (int $i): array => ['value' => "r{$i}", 'label' => "Row {$i}"], range(1, 501));
    $form = agreementForm($this->tenant, $this->owner, 'Big', [['big', FieldType::LikertMatrix, ['config' => [
        'rows' => $rows, 'columns' => [['value' => '1', 'label' => 'No'], ['value' => '2', 'label' => 'Yes']],
    ]]]]);

    expect(agreementRefusal($this->owner, $form, ['items' => ['grid:big']]))->toBe('too_many');
});

it('keeps a value with a quote in it as a value, left out like any other', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    agreementResponse($form, ['q_choice' => "it's'); drop table submissions; --"]);
    agreementResponse($form, ['q_choice' => 'a']);

    $line = agreementLines(agreementList($this->owner, $form, ['items' => ['answer:q_choice'], 'min_views' => 1, 'leave_out' => ["it's'); drop table submissions; --"]]))['q_choice'];

    expect($line)->toMatchArray(['responses' => 2, 'views' => 1, 'left_out' => 1, 'share' => 100])
        ->and(Submission::query()->count())->toBe(2);
});

it('offers the list only on its own source, and its source only the list', function (): void {
    $form = agreementForm($this->tenant, $this->owner);

    expect(agreementRefusal($this->owner, $form, ['items' => ['answer:q_view']], ['source' => 'answers']))->toBe('chart_not_allowed')
        ->and(agreementRefusal($this->owner, $form, ['items' => ['answer:q_view']], ['chart' => 'table', 'measures' => [['aggregate' => 'count']], 'agreement' => null]))->toBe('chart_not_allowed');
});

it('refuses a form the reader cannot reach', function (): void {
    $form = agreementForm($this->tenant, $this->owner);
    $member = User::factory()->create();
    makeActiveMember($member, 'form_editor');

    expect(agreementRefusal($member, $form, ['items' => ['answer:q_view']]))->toBe('form_unavailable');
});
