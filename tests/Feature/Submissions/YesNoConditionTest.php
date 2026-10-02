<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\ValidationRuleType;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M124 (R-9f296f7e) — a condition on a yes/no question, through the REAL publish and submit paths.
|--------------------------------------------------------------------------
| `M123` found this with a throwaway probe: `required_if consent = yes` published clean, and a submission
| answering Yes with the governed question empty was RECORDED. Stage 2 turns a yes/no answer into a boolean, and
| the equality rule compared `true` as the string '1', so `= yes` never held, `= no` matched nothing at all, and
| `!= yes` held for every answer. The browser, which kept the strings its Yes/No control emits, said the
| opposite — so a question shown there could have its answer pruned here. This file is that probe made
| permanent: the form is published by `PublishService`, the answers go through `SubmissionPipeline::submit()`,
| and every answer form a client really sends is driven — the browser's `'yes'`, the boolean a resumed draft
| carries, and a capitalised value an API client might send.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** A published form: a yes/no `consent`, then a short-text `details` required when consent [operator] [value]. */
function yesNoRequiredIfForm(ComparisonOperator $operator, string $value): FormVersion
{
    $form = app(FormService::class)->create(test()->tenant, test()->user, 'Consent survey');
    $draft = $form->draftVersion;
    $consent = addFormField($draft, test()->user, 'consent', FieldType::YesNo, 0);
    $details = addFormField($draft, test()->user, 'details', FieldType::ShortText, 1, ['is_required' => RequiredMode::Conditional]);

    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $details->id,
        'related_form_field_id' => $consent->id,
        'rule_type' => ValidationRuleType::RequiredIf,
        'operator' => $operator,
        'rule_value' => $value,
    ]);

    return app(PublishService::class)->publish($form->refresh(), test()->user);
}

/** @param  array<string, mixed>  $answers */
function yesNoSubmit(FormVersion $version, array $answers): Submission
{
    return test()->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Manual,
        respondentUserId: test()->user->id,
    ))->submission;
}

it('requires the governed answer when the yes/no question is answered yes', function (mixed $answer, string $ruleValue): void {
    $version = yesNoRequiredIfForm(ComparisonOperator::Eq, $ruleValue);

    try {
        yesNoSubmit($version, ['consent' => $answer]);
        $this->fail('a Yes left the governed question unanswered and the submission was recorded');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors())->toHaveCount(1)
            ->and($e->fieldErrors()[0]['field'])->toBe('details')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }

    expect(Submission::query()->count())->toBe(0);
})->with([
    'the browser string, rule yes' => ['yes', 'yes'],
    'a resumed boolean, rule yes' => [true, 'yes'],
    'a capitalised answer, rule yes' => ['Yes', 'yes'],
    'rule value true' => ['yes', 'true'],
    'rule value YES' => [true, 'YES'],
    'rule value 1, the old workaround, still holds' => [true, '1'],
]);

it('records a No without the governed answer, and stores it as false', function (): void {
    $version = yesNoRequiredIfForm(ComparisonOperator::Eq, 'yes');

    $submission = yesNoSubmit($version, ['consent' => 'no']);

    expect(SubmissionAnswer::query()->findOrFail($submission->id)->answers)->toBe(['consent' => false]);
});

it('reads "does not equal yes" by meaning, not as a condition that holds for every answer', function (): void {
    $version = yesNoRequiredIfForm(ComparisonOperator::Neq, 'yes');

    // A Yes satisfies the condition's negation, so nothing is required — before M124 this was refused.
    $accepted = yesNoSubmit($version, ['consent' => 'yes']);
    expect(SubmissionAnswer::query()->findOrFail($accepted->id)->answers)->toBe(['consent' => true]);

    try {
        yesNoSubmit($version, ['consent' => 'no']);
        $this->fail('a No left the governed question unanswered and the submission was recorded');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('details')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }
});

it('keeps the answer to a question shown because a yes/no question was answered yes', function (): void {
    // The silent-loss half: the respondent's browser showed `symptoms` and they answered it, and the server —
    // reading the gate as never holding — used to prune the answer before it was stored.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Symptom check');
    $draft = $form->draftVersion;
    addFormField($draft, $this->user, 'consent', FieldType::YesNo, 0);
    addFormField($draft, $this->user, 'symptoms', FieldType::ShortText, 1, [
        'relevant_expression' => '${consent} = '."'yes'",
    ]);
    $version = app(PublishService::class)->publish($form->refresh(), $this->user);

    $submission = yesNoSubmit($version, ['consent' => 'yes', 'symptoms' => 'cough']);

    $answers = SubmissionAnswer::query()->findOrFail($submission->id)->answers;
    expect($answers['consent'] ?? null)->toBeTrue()
        ->and($answers['symptoms'] ?? null)->toBe('cough');
});
