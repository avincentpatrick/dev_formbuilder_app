<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Forms\FieldTypeConversion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M183 (`R-5033e87e`, `D129`) — a matrix column's own type, choices and required flag, through the real pipeline
| (Stage 1 coercion, Stage 3 per column), the publish gate, the encode page's payload and a type conversion.
| The PHP ⇄ TS parity of Stage 3 is the golden vectors' (`tests/golden/validation/matrix.json`); these are the
| DB-backed halves the golden suite cannot reach.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** Publish a form holding one matrix `g` with $config (plus any field attributes) and return the version. */
function typedGridPublish(Tenant $tenant, User $user, array $config, array $attributes = []): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'Clinic roster');
    addFormField($form->draftVersion, $user, 'g', FieldType::Matrix, 0, ['config' => $config, ...$attributes]);

    return app(PublishService::class)->publish($form->refresh(), $user);
}

/** Two clinics × a whole number (required), a date, a multiple choice with its own list, a text, and an untyped column. */
function typedGridConfig(): array
{
    return [
        'rows' => [['value' => 'a', 'label' => 'Clinic A'], ['value' => 'b', 'label' => 'Clinic B']],
        'columns' => [
            ['value' => 'staff', 'label' => 'Staff on duty', 'type' => 'integer', 'required' => true],
            ['value' => 'open', 'label' => 'Opened', 'type' => 'date'],
            ['value' => 'svc', 'label' => 'Services', 'type' => 'multi_select', 'options' => [['value' => 'lab', 'label' => 'Lab'], ['value' => 'xray', 'label' => 'X-ray']]],
            ['value' => 'note', 'label' => 'Note', 'type' => 'short_text'],
            ['value' => 'rate', 'label' => 'Rating'],
        ],
        'cells' => [['value' => 'poor', 'label' => 'Poor'], ['value' => 'good', 'label' => 'Good']],
    ];
}

/** Submit $answers to $version and return the validation errors as `field => rule` (empty when it was stored). */
function typedGridErrors(SubmissionPipeline $pipeline, FormVersion $version, User $user, array $answers): array
{
    try {
        $pipeline->submit(new SubmissionPayload(version: $version, answers: $answers, source: SubmissionSource::Manual, respondentUserId: $user->id));
    } catch (SubmissionValidationException $e) {
        return collect($e->fieldErrors())->mapWithKeys(fn (array $error): array => [$error['field'] => $error['rule']])->all();
    }

    return [];
}

it('stores each cell typed by its column: a number, a date, a list of choices, a text and a choice', function (): void {
    $version = typedGridPublish($this->tenant, $this->user, typedGridConfig());
    $answers = ['g' => [
        'a' => ['staff' => 3, 'open' => '2026-10-22', 'svc' => ['lab', 'xray'], 'note' => 'Short of gloves', 'rate' => 'good'],
        'b' => ['staff' => '4'],
    ]];

    expect(typedGridErrors($this->pipeline, $version, $this->user, $answers))->toBe([]);

    $stored = SubmissionAnswer::query()->sole()->answers;
    expect($stored['g']['a'])->toEqual(['staff' => 3, 'open' => '2026-10-22', 'svc' => ['lab', 'xray'], 'note' => 'Short of gloves', 'rate' => 'good'])
        ->and($stored['g']['b'])->toEqual(['staff' => '4']);
});

it('refuses text in a number column, addressed by its row and column', function (): void {
    $version = typedGridPublish($this->tenant, $this->user, typedGridConfig());

    expect(typedGridErrors($this->pipeline, $version, $this->user, ['g' => ['a' => ['staff' => 'many'], 'b' => ['staff' => 2]]]))
        ->toBe(['g.a.staff' => 'not_a_number']);
    expect(Submission::query()->count())->toBe(0);
});

it('refuses a choice outside a column\'s own list, even when the shared list holds it', function (): void {
    $config = typedGridConfig();
    $config['cells'][] = ['value' => 'dental', 'label' => 'Dental'];
    $version = typedGridPublish($this->tenant, $this->user, $config);

    expect(typedGridErrors($this->pipeline, $version, $this->user, ['g' => ['a' => ['staff' => 1, 'svc' => ['lab', 'dental']], 'b' => ['staff' => 1]]]))
        ->toBe(['g.a.svc' => 'choice_not_in_options']);
});

it('demands a required column in every row, and no other cell', function (): void {
    $version = typedGridPublish($this->tenant, $this->user, typedGridConfig());

    expect(typedGridErrors($this->pipeline, $version, $this->user, ['g' => ['a' => ['staff' => 3], 'b' => ['rate' => 'good']]]))
        ->toBe(['g.b.staff' => 'field_required']);
});

it('still refuses a list in a single-choice cell', function (): void {
    $version = typedGridPublish($this->tenant, $this->user, typedGridConfig());

    expect(typedGridErrors($this->pipeline, $version, $this->user, ['g' => ['a' => ['staff' => 1, 'rate' => ['good']]]]))
        ->toBe(['g.a.rate' => 'expected_scalar']);
});

it('publishes a grid with no shared choices when every choice column carries its own', function (): void {
    $config = typedGridConfig();
    unset($config['columns'][4]);
    $config['columns'] = array_values($config['columns']);
    $config['cells'] = [];

    expect(typedGridPublish($this->tenant, $this->user, $config)->version_number)->toBe(1);
});

it('refuses to publish a grid whose untyped column has no choices to draw from', function (): void {
    $config = typedGridConfig();
    $config['cells'] = [];

    expect(fn () => typedGridPublish($this->tenant, $this->user, $config))->toThrow(PublishValidationException::class, 'g');
});

it('refuses to publish a choice column whose own choices repeat a value', function (): void {
    $config = typedGridConfig();
    $config['columns'][2]['options'][] = ['value' => 'lab', 'label' => 'Laboratory'];

    expect(fn () => typedGridPublish($this->tenant, $this->user, $config))->toThrow(PublishValidationException::class, 'g');
});

it('refuses to publish a column whose type a grid cannot hold', function (): void {
    $config = typedGridConfig();
    $config['columns'][3]['type'] = 'signature';

    expect(fn () => typedGridPublish($this->tenant, $this->user, $config))->toThrow(PublishValidationException::class, 'g');
});

it('hands the encode page each column\'s type, its choices and its required flag, and the grid\'s layout', function (): void {
    $version = typedGridPublish($this->tenant, $this->user, [...typedGridConfig(), 'layout' => 'stacked']);
    /** @var Form $form */
    $form = Form::query()->findOrFail($version->form_id);

    $grid = collect(app(EncodeFormPresenter::class)->present($form, $version)['blocks'])
        ->flatMap(fn (array $block): array => $block['fields'])->keyBy('key')['g']['matrix'];

    expect($grid['layout'])->toBe('stacked')
        ->and($grid['columns'][0])->toEqual(['value' => 'staff', 'label' => 'Staff on duty', 'type' => 'integer', 'options' => [], 'required' => true])
        ->and($grid['columns'][2]['type'])->toBe('multi_select')
        ->and($grid['columns'][2]['options'])->toEqual([['value' => 'lab', 'label' => 'Lab'], ['value' => 'xray', 'label' => 'X-ray']])
        ->and($grid['columns'][4])->toEqual(['value' => 'rate', 'label' => 'Rating', 'type' => 'single_select', 'options' => [['value' => 'poor', 'label' => 'Poor'], ['value' => 'good', 'label' => 'Good']], 'required' => false]);
});

it('drops a column\'s type, choices and required flag when a matrix becomes a Likert matrix', function (): void {
    $field = makeSchemaField(['key' => 'g', 'field_type' => FieldType::Matrix, 'config' => typedGridConfig()]);

    $plan = FieldTypeConversion::plan($field, [], FieldType::LikertMatrix, ['rows' => [], 'columns' => []], false);

    expect($plan->config['columns'])->toBe([
        ['value' => 'staff', 'label' => 'Staff on duty'],
        ['value' => 'open', 'label' => 'Opened'],
        ['value' => 'svc', 'label' => 'Services'],
        ['value' => 'note', 'label' => 'Note'],
        ['value' => 'rate', 'label' => 'Rating'],
    ])->and($plan->config)->not->toHaveKey('cells');
});
