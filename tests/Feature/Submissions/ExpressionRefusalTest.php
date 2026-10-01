<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Enums\SubmissionStatus;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Models\Form;
use App\Models\FormFieldValidation;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M123 (R-d8780a8b) — a form rule the server cannot evaluate, refused on the two staff pages.
|--------------------------------------------------------------------------
| An `ExpressionException` used to escape `SubmissionController::store()` and `SubmissionEditController::update()`
| to the central renderer, which answers an Inertia request with a toast-only `back()`. `Encode.vue` keeps its
| state only when the page comes back carrying ERRORS, so the toast-only refusal re-keyed the page: on the edit
| page a keyer's typed corrections were replaced by the stored document. Each controller now refuses with an
| errors bag (keyed `answers.<field>` when the exception names one, `expression` when it cannot) and reports the
| exception, because a caught exception is no longer reported for it.
|
| ⛔ THE STATE IS BUILT THE WAY HISTORY BUILT IT, as `PublishedRuleCompletenessAuditTest` does: since M116 the
| publish gate refuses these rules, so the rule is written while the version is a draft and the version then moves
| to `published` once — snapshot, checksum and status in ONE update, because the immutability trigger freezes
| them from the first moment the status is not `draft`. Every UPDATE asserts its row count: under RLS a write
| that matches nothing reports success.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function expressionRefusalReenter(): void
{
    enterTenant(test()->tenant->id, test()->owner->id);
}

/**
 * A published form with `full_name` (short text) and `age` (integer), where `age` carries the given rule.
 *
 * @param  array<string, mixed>  $rule  merged over the validation factory's defaults; pass
 *                                      `FormFieldValidation::factory()->expression(...)`-shaped keys for an expression row
 */
function expressionRefusalForm(array $rule): Form
{
    $form = app(FormService::class)->create(test()->tenant, test()->owner, 'Broken rule');
    $version = $form->draftVersion;
    addFormField($version, test()->owner, 'full_name', FieldType::ShortText, 0);
    $age = addFormField($version, test()->owner, 'age', FieldType::Integer, 1);

    FormFieldValidation::factory()->create(array_merge([
        'form_version_id' => $version->id,
        'form_field_id' => $age->id,
    ], $rule));

    $serializer = app(SchemaSnapshotSerializer::class);
    $snapshot = $serializer->snapshot($version->refresh());

    $moved = DB::table('form_versions')
        ->where('id', $version->id)
        ->where('status', FormVersionStatus::Draft->value)
        ->update([
            'status' => FormVersionStatus::Published->value,
            'schema_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'checksum' => $serializer->checksumOf($snapshot),
            'published_at' => now(),
        ]);
    expect($moved)->toBe(1, 'the draft could not be published, so the fixture never existed');

    $pointed = DB::table('forms')->where('id', $form->id)->update([
        'current_published_version_id' => $version->id,
        'status' => FormStatus::Published->value,
        'published_at' => now(),
    ]);
    expect($pointed)->toBe(1, 'the form could not be pointed at its published version');

    return $form->refresh();
}

/** A `greater_than_field` naming no compared question: it throws `missing_related_field` once `age` is answered. */
function ruleThatThrowsOnAge(): array
{
    return ['rule_type' => ValidationRuleType::GreaterThanField, 'rule_value' => null, 'related_form_field_id' => null];
}

/** A constraint expression the parser rejects: the throw carries no field key. */
function expressionThatCannotParse(): array
{
    return ['rule_type' => null, 'rule_value' => null, 'expression' => '(. > 1'];
}

function expressionRefusalStoredAnswers(Submission $submission): array
{
    expressionRefusalReenter();

    return SubmissionAnswer::query()->where('submission_id', $submission->id)->value('answers');
}

it('refuses a correction a broken rule cannot check, keeping the page by naming the field', function (): void {
    $form = expressionRefusalForm(ruleThatThrowsOnAge());
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    $edit = "http://acme.meridian.test/submissions/{$submission->id}/edit";

    $this->actingAs($this->owner)
        ->from($edit)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada', 'age' => '36'],
            'baseline' => (string) SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum'),
        ])
        ->assertRedirect($edit)
        ->assertSessionHasErrors('answers.age')
        ->assertSessionDoesntHaveErrors('baseline')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error'
            && ! str_contains(strtolower($toast['message']), 'try again'));

    expect(expressionRefusalStoredAnswers($submission))->toEqual(['full_name' => 'Ada']);
});

it('still saves a correction that never reaches the broken rule', function (): void {
    // The control: an empty `age` skips its constraints, so nothing throws and the edit lands as before.
    $form = expressionRefusalForm(ruleThatThrowsOnAge());
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace'],
            'baseline' => (string) SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum'),
        ])
        ->assertRedirect("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertSessionHasNoErrors();

    expect(expressionRefusalStoredAnswers($submission))->toEqual(['full_name' => 'Ada Lovelace']);
});

it('refuses a new response a broken rule cannot check, keeping the page and recording nothing', function (): void {
    $form = expressionRefusalForm(ruleThatThrowsOnAge());
    $create = "http://acme.meridian.test/forms/{$form->id}/submissions/create";

    $this->actingAs($this->owner)
        ->from($create)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['full_name' => 'Ada', 'age' => '36'],
        ])
        ->assertRedirect($create)
        ->assertSessionHasErrors('answers.age')
        ->assertSessionDoesntHaveErrors('baseline')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error'
            && ! str_contains(strtolower($toast['message']), 'try again'));

    expressionRefusalReenter();
    expect(Submission::query()->count())->toBe(0);
});

it('refuses a resumed draft at its promote, keeping the draft a draft', function (): void {
    // The draft branch: the autosave has already made a draft, so Submit saves (Stage 1) and then PROMOTES, and
    // the promote is what runs the broken rule. The try must cover this branch as well as the pipeline one.
    $form = expressionRefusalForm(ruleThatThrowsOnAge());
    $uuid = (string) Str::uuid();

    $draft = $this->actingAs($this->owner)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/submissions/draft", [
            'answers' => ['age' => '36'],
            'client_submission_uuid' => $uuid,
        ])
        ->assertCreated();

    $draftId = (string) $draft->json('data.id');

    $this->actingAs($this->owner)
        ->from("http://acme.meridian.test/submissions/{$draftId}/resume")
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['age' => '36'],
            'client_submission_uuid' => $uuid,
            'base_content_checksum' => $draft->json('data.content_checksum'),
        ])
        ->assertSessionHasErrors('answers.age');

    expressionRefusalReenter();
    expect(Submission::query()->findOrFail($draftId)->status)->toBe(SubmissionStatus::Draft);
});

it('records a new response that never reaches the broken rule', function (): void {
    $form = expressionRefusalForm(ruleThatThrowsOnAge());

    $this->actingAs($this->owner)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", ['answers' => ['full_name' => 'Ada']])
        ->assertRedirect("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertSessionHasNoErrors();

    expressionRefusalReenter();
    expect(Submission::query()->where('status', SubmissionStatus::Submitted)->count())->toBe(1);
});

it('still reports the broken rule on both pages, because catching it would otherwise silence it', function (): void {
    // Escaping, the exception was reported before rendering; once caught, only the controller can report it.
    Exceptions::fake([ExpressionEvaluationException::class]);
    $form = expressionRefusalForm(ruleThatThrowsOnAge());
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada', 'age' => '36'],
            'baseline' => (string) SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum'),
        ])
        ->assertSessionHasErrors('answers.age');

    Exceptions::assertReported(fn (ExpressionEvaluationException $e): bool => $e->slug() === 'missing_related_field'
        && $e->fieldKey() === 'age');
});

it('reports the broken rule from a new response too', function (): void {
    Exceptions::fake([ExpressionEvaluationException::class]);
    $form = expressionRefusalForm(ruleThatThrowsOnAge());

    $this->actingAs($this->owner)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['full_name' => 'Ada', 'age' => '36'],
        ])
        ->assertSessionHasErrors('answers.age');

    Exceptions::assertReported(fn (ExpressionEvaluationException $e): bool => $e->slug() === 'missing_related_field'
        && $e->fieldKey() === 'age');
});

it('keeps the page under a neutral key when the broken rule names no field', function (): void {
    // A syntax throw carries no field key, so nothing can render inline — but any key still makes the page keep
    // its state, which is the whole point. Never `baseline`: the page reads that as an editing conflict.
    $form = expressionRefusalForm(expressionThatCannotParse());

    $this->actingAs($this->owner)
        ->post("http://acme.meridian.test/forms/{$form->id}/submissions", [
            'answers' => ['full_name' => 'Ada', 'age' => '36'],
        ])
        ->assertSessionHasErrors('expression')
        ->assertSessionDoesntHaveErrors(['baseline', 'answers.age']);
});

it('keeps the edit page under a neutral key when the broken rule names no field', function (): void {
    $form = expressionRefusalForm(expressionThatCannotParse());
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada', 'age' => '36'],
            'baseline' => (string) SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum'),
        ])
        ->assertSessionHasErrors('expression')
        ->assertSessionDoesntHaveErrors(['baseline', 'answers.age']);
});
