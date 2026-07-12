<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G4a — likert_scale + cascading_select end-to-end through the
| Submission Pipeline (Stage-1 coercion, Stage-3 membership, index) and the
| pre-publish structural gate. The PHP⇄TS validation parity itself is proven
| by the shared golden vectors (membership.json / cascading.json); this
| exercises the real DB-backed pipeline + publish flow the golden suite can't.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** Build a form, populate its draft via $build, publish it, and return the published version. */
function g4Publish(Tenant $tenant, User $user, Closure $build): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'G4 Survey');
    $build($form->draftVersion, $user);

    return app(PublishService::class)->publish($form->refresh(), $user);
}

/** @return array{value: string} the standard 1–5 likert scale point option shape. */
function likertOptions(): array
{
    return array_map(static fn (string $v): array => ['value' => $v, 'label' => "Score {$v}"], ['1', '2', '3', '4', '5']);
}

/** The standard 2-level region → province cascade config used across the cascading cases. */
function cascadeConfig(): array
{
    return [
        'levels' => [['key' => 'region', 'label' => 'Region'], ['key' => 'province', 'label' => 'Province']],
        'options' => [
            ['value' => 'ncr', 'label' => 'NCR', 'level' => 'region', 'parent' => null],
            ['value' => 'cebu', 'label' => 'Cebu', 'level' => 'region', 'parent' => null],
            ['value' => 'manila', 'label' => 'Manila', 'level' => 'province', 'parent' => 'ncr'],
            ['value' => 'cebu_city', 'label' => 'Cebu City', 'level' => 'province', 'parent' => 'cebu'],
        ],
    ];
}

it('stores a likert_scale answer as a scalar and projects it into the numeric index', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'rating', FieldType::LikertScale, 0, [
            'config' => ['options' => likertOptions()],
            'is_queryable' => true,
            'indexed_data_type' => IndexedDataType::Number,
        ]);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['rating' => '4'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->toEqual(['rating' => '4']);

    $index = SubmissionAnswerIndex::query()->where('submission_id', $result->submission->id)->get();
    expect($index)->toHaveCount(1)
        ->and($index->first()->field_key)->toBe('rating')
        ->and((float) $index->first()->value_number)->toBe(4.0);
});

it('rejects a likert_scale value that is not one of the scale points', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'rating', FieldType::LikertScale, 0, ['config' => ['options' => likertOptions()]]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['rating' => '9'],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors()[0]['field'])->toBe('rating')
            ->and($e->fieldErrors()[0]['rule'])->toBe('choice_not_in_options');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('stores a valid cascading answer as an ordered list and never indexes it', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::CascadingSelect, 0, [
            'config' => cascadeConfig(),
            // Even if an author marks it queryable, a list answer never reaches the scalar index (data-dict §8/§9).
            'is_queryable' => true,
            'indexed_data_type' => IndexedDataType::Text,
        ]);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['loc' => ['ncr', 'manila']],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->toEqual(['loc' => ['ncr', 'manila']]);

    expect(SubmissionAnswerIndex::query()->where('submission_id', $result->submission->id)->count())->toBe(0);
});

it('rejects an out-of-hierarchy cascading child value addressed by its level index', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::CascadingSelect, 0, ['config' => cascadeConfig()]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['loc' => ['ncr', 'bogus']],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('loc.1')
            ->and($e->fieldErrors()[0]['rule'])->toBe('cascading_choice_invalid');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('rejects a cascading child whose parent does not match the chosen parent level', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::CascadingSelect, 0, ['config' => cascadeConfig()]);
    });

    try {
        // cebu_city is a real province option, but it belongs under "cebu", not the chosen "ncr".
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['loc' => ['ncr', 'cebu_city']],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->fieldErrors()[0]['field'])->toBe('loc.1')
            ->and($e->fieldErrors()[0]['rule'])->toBe('cascading_parent_mismatch');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('requires a cascading answer when the field is required', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::CascadingSelect, 0, [
            'config' => cascadeConfig(),
            'is_required' => RequiredMode::Required,
        ]);
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
        expect($e->fieldErrors()[0]['field'])->toBe('loc')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('refuses to publish a cascading field with a dangling parent', function (): void {
    try {
        g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
            addFormField($draft, $user, 'loc', FieldType::CascadingSelect, 0, ['config' => [
                'levels' => [['key' => 'region'], ['key' => 'province']],
                'options' => [
                    ['value' => 'ncr', 'level' => 'region', 'parent' => null],
                    ['value' => 'manila', 'level' => 'province', 'parent' => 'nowhere'],
                ],
            ]]);
        });
        expect(false)->toBeTrue('expected a PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain('loc');
    }
});

it('refuses to publish a likert_scale with no options', function (): void {
    try {
        g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
            addFormField($draft, $user, 'rating', FieldType::LikertScale, 0, ['config' => ['options' => []]]);
        });
        expect(false)->toBeTrue('expected a PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain('rating');
    }
});

it('marks likert_scale + cascading fields supported and emits the cascade payload to the encode UI', function (): void {
    $version = g4Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'rating', FieldType::LikertScale, 0, ['config' => ['options' => likertOptions()]]);
        addFormField($draft, $user, 'loc', FieldType::CascadingSelect, 1, ['config' => cascadeConfig()]);
    });

    /** @var Form $form */
    $form = Form::query()->findOrFail($version->form_id);
    $presented = app(EncodeFormPresenter::class)->present($form, $version);

    $fields = collect($presented['blocks'])->flatMap(fn (array $b): array => $b['fields'])->keyBy('key');

    expect($fields['rating']['supported'])->toBeTrue()
        ->and($fields['rating']['options'])->toHaveCount(5)
        ->and($fields['loc']['supported'])->toBeTrue()
        ->and($fields['loc']['cascade']['levels'])->toHaveCount(2)
        ->and($fields['loc']['cascade']['options'][2])->toMatchArray(['value' => 'manila', 'level' => 'province', 'parent' => 'ncr']);
});
