<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\ValidationRuleType;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
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

/**
 * Publish a form with a repeatable "hh" section; $build(draft, user, section) adds the member fields (and
 * any top-level fields / validations). $sectionAttrs overrides the section defaults (min/max/relevant).
 *
 * @param  array<string, mixed>  $sectionAttrs
 */
function publishRepeatForm(Tenant $tenant, User $user, array $sectionAttrs, Closure $build): FormVersion
{
    return pipelinePublish($tenant, $user, function (FormVersion $draft, User $u) use ($sectionAttrs, $build): void {
        $section = FormSection::create(array_merge([
            'form_version_id' => $draft->id,
            'key' => 'hh',
            'label' => 'Household',
            'sequence' => 0,
            'is_repeatable' => true,
        ], $sectionAttrs));

        $build($draft, $u, $section);
    });
}

it('persists repeat-group instances as a nested array and never indexes member or section keys', function (): void {
    $version = publishRepeatForm($this->tenant, $this->user, [], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'title', FieldType::ShortText, 0, ['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Text]);
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $section->id]);
        addFormField($draft, $user, 'member_age', FieldType::Integer, 2, ['form_section_id' => $section->id, 'is_queryable' => true, 'indexed_data_type' => IndexedDataType::Number]);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: [
            'title' => 'Census',
            'hh' => [
                ['member_name' => 'Bob', 'member_age' => '40'],
                ['member_name' => 'Cleo', 'member_age' => '12'],
            ],
        ],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->toEqual([
        'title' => 'Census',
        'hh' => [
            ['member_name' => 'Bob', 'member_age' => '40'],
            ['member_name' => 'Cleo', 'member_age' => '12'],
        ],
    ]);

    // Only the top-level queryable field projects; the member scalar (even though queryable) and the section
    // key never reach the typed index (data-dictionary §8/§9).
    $index = SubmissionAnswerIndex::query()->where('submission_id', $result->submission->id)->get();
    expect($index)->toHaveCount(1)
        ->and($index->first()->field_key)->toBe('title');
});

it('rejects a missing required member answer addressed by the instance path', function (): void {
    $version = publishRepeatForm($this->tenant, $this->user, [], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 0, ['form_section_id' => $section->id, 'is_required' => RequiredMode::Required]);
        addFormField($draft, $user, 'member_age', FieldType::Integer, 1, ['form_section_id' => $section->id]);
    });

    try {
        // Instance 1 carries an age (so it is not an empty, dropped instance) but omits the required name.
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['hh' => [['member_name' => 'Bob', 'member_age' => '40'], ['member_age' => '12']]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors()[0]['field'])->toBe('hh[1].member_name')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('rejects a failed member constraint addressed by the instance path', function (): void {
    $version = publishRepeatForm($this->tenant, $this->user, [], function (FormVersion $draft, User $user, FormSection $section): void {
        $age = addFormField($draft, $user, 'member_age', FieldType::Integer, 0, ['form_section_id' => $section->id]);
        FormFieldValidation::create([
            'form_version_id' => $draft->id,
            'form_field_id' => $age->id,
            'rule_type' => ValidationRuleType::MinValue,
            'rule_value' => '18',
            'error_message' => 'Must be at least 18.',
            'sequence' => 0,
        ]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['hh' => [['member_age' => '10'], ['member_age' => '40']]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh[0].member_age')
            ->and($e->fieldErrors()[0]['rule'])->toBe('min_value')
            ->and($e->fieldErrors()[0]['message'])->toBe('Must be at least 18.');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('enforces min and max instance counts on a relevant repeat section', function (): void {
    $belowMin = publishRepeatForm($this->tenant, $this->user, ['min_instances' => 2], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 0, ['form_section_id' => $section->id]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $belowMin,
            answers: ['hh' => [['member_name' => 'Bob']]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh')
            ->and($e->fieldErrors()[0]['rule'])->toBe('min_instances');
    }

    $aboveMax = publishRepeatForm($this->tenant, $this->user, ['max_instances' => 2], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 0, ['form_section_id' => $section->id]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $aboveMax,
            answers: ['hh' => [['member_name' => 'A'], ['member_name' => 'B'], ['member_name' => 'C']]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('hh')
            ->and($e->fieldErrors()[0]['rule'])->toBe('max_instances');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('drops a hidden repeat section entirely and enforces no instance count on it', function (): void {
    $version = publishRepeatForm($this->tenant, $this->user, [
        'min_instances' => 2,
        'relevant_expression' => '${mode} = \'full\'',
    ], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'mode', FieldType::ShortText, 0);
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 1, ['form_section_id' => $section->id]);
    });

    // mode != full → the section is irrelevant, so its 1-instance array (below min 2) is dropped without error.
    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['mode' => 'basic', 'hh' => [['member_name' => 'Bob']]],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->toBe(['mode' => 'basic']);
});

it('treats a replayed client_submission_uuid with nested answers as an idempotent no-op', function (): void {
    $version = publishRepeatForm($this->tenant, $this->user, [], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 0, ['form_section_id' => $section->id]);
    });

    $payload = new SubmissionPayload(
        version: $version,
        answers: ['hh' => [['member_name' => 'Bob'], ['member_name' => 'Cleo']]],
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

it('stores an answers-content checksum on persist (Increment G8c)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: Str::uuid()->toString(),
    ));

    $doc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($doc->answers_content_checksum)->toBeString()->toHaveLength(64);
});

it('treats a same-uuid replay carrying DIFFERENT content as a 409 conflict (Increment G8c)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $uuid = Str::uuid()->toString();
    $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    // Same idempotency key, materially different answers → a genuine concurrent-edit conflict.
    expect(fn () => $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Grace'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    )))->toThrow(SubmissionConflictException::class);

    // The original submission is untouched — the conflict never persisted a second row.
    expect(Submission::query()->count())->toBe(1)
        ->and(SubmissionAnswer::query()->findOrFail(Submission::query()->firstOrFail()->id)->answers)->toBe(['name' => 'Ada']);
});

it('treats a same-uuid replay with key-reordered but equal content as an idempotent no-op (Increment G8c)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'first', FieldType::ShortText, 0);
        addFormField($draft, $user, 'last', FieldType::ShortText, 1);
    });

    $uuid = Str::uuid()->toString();
    $first = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['first' => 'Ada', 'last' => 'Lovelace'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    // Identical values, different key order — the canonical checksum matches → a 200 no-op, not a conflict.
    $second = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['last' => 'Lovelace', 'first' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    expect($second->created)->toBeFalse()
        ->and($second->submission->id)->toBe($first->submission->id)
        ->and(Submission::query()->count())->toBe(1);
});

it('never false-conflicts on a legacy row with no stored content checksum (Increment G8c)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $uuid = Str::uuid()->toString();
    $first = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    // Simulate a pre-G8c row: clear the stored checksum so the pipeline "cannot compare".
    SubmissionAnswer::query()->where('submission_id', $first->submission->id)->update(['answers_content_checksum' => null]);

    // Even different content must fall back to the idempotent no-op (no false 409 on legacy data).
    $second = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Grace'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    expect($second->created)->toBeFalse()
        ->and($second->submission->id)->toBe($first->submission->id);
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

it('writes a calculated field back into the answer document and the typed index (Increment G3)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'quantity', FieldType::Integer, 0);
        addFormField($draft, $user, 'unit_price', FieldType::Decimal, 1);
        addFormField($draft, $user, 'line_total', FieldType::Calculated, 2, [
            'config' => ['calculated_formula' => '${quantity} * ${unit_price}'],
            'is_queryable' => true,
            'indexed_data_type' => IndexedDataType::Number,
        ]);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['quantity' => '3', 'unit_price' => '5'], // calculated key is server-computed, never submitted
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers['line_total'])->toEqual(15);

    $indexRow = SubmissionAnswerIndex::query()
        ->where('submission_id', $result->submission->id)
        ->where('field_key', 'line_total')
        ->first();
    expect($indexRow)->not->toBeNull()
        ->and((float) $indexRow->value_number)->toBe(15.0);
});

it('computes count() over repeat-group instances into a calculated field (Increment G3)', function (): void {
    $version = publishRepeatForm($this->tenant, $this->user, [], function (FormVersion $draft, User $user, FormSection $section): void {
        addFormField($draft, $user, 'member_name', FieldType::ShortText, 0, ['form_section_id' => $section->id]);
        addFormField($draft, $user, 'headcount', FieldType::Calculated, 1, [
            'config' => ['calculated_formula' => 'count(${hh})'],
        ]);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['hh' => [['member_name' => 'Ada'], ['member_name' => 'Bob'], ['member_name' => 'Cleo']]],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers['headcount'])->toEqual(3);
});

it('omits a hidden calculated field from the answer document (Increment G3)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'wants_total', FieldType::YesNo, 0);
        addFormField($draft, $user, 'a', FieldType::Integer, 1);
        addFormField($draft, $user, 'b', FieldType::Integer, 2);
        addFormField($draft, $user, 'total', FieldType::Calculated, 3, [
            'config' => ['calculated_formula' => '${a} + ${b}'],
            'relevant_expression' => '${wants_total}', // yes_no → bool; false hides the calc
        ]);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['wants_total' => 'no', 'a' => '2', 'b' => '3'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->not->toHaveKey('total');
});

/*
|--------------------------------------------------------------------------
| Increment M11 — Stage 2b resolves WITHIN one form and one author.
|--------------------------------------------------------------------------
| The uniqueness domain of `submissions_tenant_client_uuid_unique` is the TENANT; the scope a caller may
| resolve within is one form and one author. Everything below is the gap between those two, which used to be
| a resolve (another form's row handed back as an idempotent no-op) or an unclassifiable 23505.
*/

it('refuses a uuid already spent on ANOTHER form instead of resolving it (M11)', function (): void {
    $mine = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });
    $theirs = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $uuid = Str::uuid()->toString();
    $foreign = $this->pipeline->submit(new SubmissionPayload(
        version: $theirs,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    expect(fn () => $this->pipeline->submit(new SubmissionPayload(
        version: $mine,
        answers: ['name' => 'Mallory'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    )))->toThrow(SubmissionConflictException::class, 'This submission identifier already belongs to another response.');

    // No second row, and the foreign row was neither returned nor touched.
    expect(Submission::query()->count())->toBe(1)
        ->and($foreign->submission->fresh()->form_id)->toBe($theirs->form_id)
        ->and(SubmissionAnswer::query()->findOrFail($foreign->submission->id)->answers)->toBe(['name' => 'Ada']);
});

it('refuses a uuid spent by ANOTHER author on the same form (M11)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $uuid = Str::uuid()->toString();
    // A member's own submission — `respondent_user_id` is theirs.
    $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
        clientSubmissionUuid: $uuid,
    ));

    // The same form, the same uuid, but no author (the guest channel) — a different row, not a replay.
    expect(fn () => $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    )))->toThrow(SubmissionConflictException::class, 'This submission identifier already belongs to another response.');

    expect(Submission::query()->count())->toBe(1);
});

it('refuses a uuid still reserved by a soft-deleted row rather than 23505ing on the index (M11)', function (): void {
    $version = pipelinePublish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'name', FieldType::ShortText, 0);
    });

    $uuid = Str::uuid()->toString();
    $first = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    ));

    // The tombstone keeps the index entry (the index filters on the uuid being non-null, NOT on deleted_at),
    // while the SoftDeletes global scope hides the row from every resolve. Latent today — ReapTenantDraftsJob
    // hard-deletes for exactly this reason — and asserted so it cannot become live silently.
    $first->submission->delete();

    expect(fn () => $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::ApiImport,
        clientSubmissionUuid: $uuid,
    )))->toThrow(SubmissionConflictException::class, 'This submission identifier already belongs to another response.');

    expect(Submission::query()->count())->toBe(0)
        ->and(Submission::withTrashed()->count())->toBe(1);
});
