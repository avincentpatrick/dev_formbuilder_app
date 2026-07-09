<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\ValidationRuleType;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormFieldValidation;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** Build a form, populate its draft via $build, publish it, and return the published version. */
function pipelinePublish(Tenant $tenant, User $user, Closure $build): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'Encoded Survey');
    $build($form->draftVersion, $user);

    return app(PublishService::class)->publish($form->refresh(), $user);
}

it('persists a submission, its answer document, and the typed index rows', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required, 'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text]);
        addFormField($draft, $user, 'age', FieldType::Integer, 1, ['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Number]);
        addFormField($draft, $user, 'subscribe', FieldType::YesNo, 2, ['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Boolean]);
        addFormField($draft, $user, 'visit_date', FieldType::Date, 3, ['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Date]);
        addFormField($draft, $user, 'notes', FieldType::LongText, 4);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada', 'age' => '30', 'subscribe' => true, 'visit_date' => '2026-07-09', 'notes' => 'hi'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    expect($result->created)->toBeTrue();

    $submission = $result->submission;
    expect($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->source)->toBe(SubmissionSource::Manual)
        ->and($submission->respondent_user_id)->toBe($this->user->id)
        ->and($submission->form_version_id)->toBe($version->id)
        ->and($submission->submitted_at)->not->toBeNull();

    $answerDoc = SubmissionAnswer::query()->findOrFail($submission->id);
    // JSONB does not preserve key order on round-trip, so compare order-insensitively.
    expect($answerDoc->answers)->toEqual(['name' => 'Ada', 'age' => '30', 'subscribe' => true, 'visit_date' => '2026-07-09', 'notes' => 'hi'])
        ->and($answerDoc->answers_schema_checksum)->toBe($version->checksum);

    $index = SubmissionAnswerIndex::query()->where('submission_id', $submission->id)->get()->keyBy('field_key');
    expect($index)->toHaveCount(4) // notes is not queryable → not projected
        ->and($index->has('notes'))->toBeFalse()
        ->and($index['name']->value_text)->toBe('Ada')
        ->and((float) $index['age']->value_number)->toBe(30.0)
        ->and($index['subscribe']->value_boolean)->toBeTrue()
        ->and($index['visit_date']->value_date->format('Y-m-d'))->toBe('2026-07-09');
});

it('prunes irrelevant answers from the persisted document and index', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'country', FieldType::SingleSelect, 0, ['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text]);
        addFormField($draft, $user, 'state', FieldType::ShortText, 1, ['relevant_expression' => '${country} = \'US\'']);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['country' => 'CA', 'state' => 'Alberta'], // state is irrelevant when country != US
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->toBe(['country' => 'CA'])
        ->and(SubmissionAnswerIndex::query()->where('submission_id', $result->submission->id)->pluck('field_key')->all())
        ->toBe(['country']);
});

it('rejects a missing required answer and persists nothing', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: [],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors()[0]['field'])->toBe('name')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }

    expect(Submission::query()->count())->toBe(0)
        ->and(SubmissionAnswer::query()->count())->toBe(0);
});

it('rejects a failed constraint through the semantic stage', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        $field = addFormField($draft, $user, 'age', FieldType::Integer, 0);
        FormFieldValidation::create([
            'form_version_id' => $draft->id,
            'form_field_id' => $field->id,
            'rule_type' => ValidationRuleType::MinValue,
            'rule_value' => '18',
            'error_message' => 'Must be at least 18.',
            'sequence' => 0,
        ]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['age' => '10'],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['rule'])->toBe('min_value')
            ->and($e->fieldErrors()[0]['message'])->toBe('Must be at least 18.');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('rejects an unknown key at the structural stage and persists nothing', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['name' => 'Ada', 'ghost' => 'x'],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('structural');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('treats a replayed client_submission_uuid as an idempotent no-op', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $payload = new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: Str::uuid()->toString(),
    );

    $first = $this->pipeline->submit($payload);
    $second = $this->pipeline->submit($payload);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->submission->id)->toBe($first->submission->id)
        ->and(Submission::query()->count())->toBe(1);
});

it('refuses to submit against a non-published version', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Draft only');
    addFormField($form->draftVersion, $this->user, 'name', FieldType::ShortText, 0);

    expect(fn () => $this->pipeline->submit(new SubmissionPayload(
        version: $form->draftVersion,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    )))->toThrow(SubmissionException::class);
});
