<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\DomainEventType;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionUpdated;
use App\Exceptions\Submissions\SubmissionEditException;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\AnswerIndexProjector;
use App\Services\Submissions\SubmissionAnswerEditService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Audit\AuditRedactor;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9c — post-submission answer editing, end to end.
|--------------------------------------------------------------------------
| Four things this file exists to hold still:
|   1. WHICH STATES are editable — asserted as an EQUALITY over every SubmissionStatus case, not as a list of
|      examples, so a new status has to be classified on purpose rather than inheriting a default.
|   2. THE PROJECTIONS ARE REPLACED, NOT APPENDED — asserted by COUNT. A duplicating implementation passes
|      every value assertion, which is exactly why the old inline projectors' insert-only shape could have
|      shipped here unnoticed.
|   3. THE DEMOTION — approved → under_review, stamps cleared, in ONE audit row.
|   4. THE REPLAY CLOCK — a constraint reading today() is judged against the day the response was filed.
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
    $this->service = app(SubmissionAnswerEditService::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The submission's own pinned version — what every caller of the service must pass. */
function editVersion(Submission $submission): FormVersion
{
    return FormVersion::findOrFail($submission->form_version_id);
}

/**
 * A published form whose two fields are actually PROJECTED.
 *
 * ⚠️ NAMED `editableIndexedForm`, not `indexedForm`: Pest's helper functions are GLOBAL, and
 * `AnswerValueAggregatorTest` already defines one by that name. A per-file run passes either way; the whole
 * suite dies on `Cannot redeclare function`. Only running the full suite finds it.
 *
 * `publishedInboxForm()` is deliberately not reused here: its fields carry neither `is_queryable` nor an
 * `indexed_data_type`, so {@see AnswerIndexProjector::project()} returns null for
 * every one of them and `submission_answer_index` stays empty. A projection test built on it would assert
 * `0 === 0` before and after the edit and pass against an implementation that does nothing at all.
 */
function editableIndexedForm(Tenant $tenant, User $owner, string $title): FormVersion
{
    $form = app(FormService::class)->create($tenant, $owner, $title);
    $version = $form->draftVersion;

    addFormField($version, $owner, 'full_name', FieldType::ShortText, 0, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Text,
    ]);
    addFormField($version, $owner, 'color', FieldType::ShortText, 1, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Text,
    ]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return FormVersion::findOrFail($form->refresh()->current_published_version_id);
}

/*
|--------------------------------------------------------------------------
| 1. The editable set
|--------------------------------------------------------------------------
*/

it('accepts exactly the four finalized non-terminal states, and refuses every other case', function (): void {
    // An EQUALITY over the whole enum, not a sample. A sixth SubmissionStatus added later is either
    // classified here on purpose or turns this red — which is the only way a default can be prevented.
    $accepted = array_map(
        static fn (SubmissionStatus $s): string => $s->value,
        SubmissionAnswerEditService::EDITABLE,
    );
    sort($accepted);

    expect($accepted)->toBe(['approved', 'returned', 'submitted', 'under_review']);

    $refused = array_values(array_diff(
        array_map(static fn (SubmissionStatus $s): string => $s->value, SubmissionStatus::cases()),
        $accepted,
    ));
    sort($refused);

    expect($refused)->toBe(['archived', 'draft', 'screened_out']);
});

it('refuses to edit a submission in a non-editable state', function (SubmissionStatus $status): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, $status, ['full_name' => 'Original']);

    expect(fn () => $this->service->edit($submission, editVersion($submission), ['full_name' => 'New'], $this->owner))
        ->toThrow(SubmissionEditException::class);

    // And the document is untouched — a refusal that had already written would be worse than no refusal.
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers'))
        ->toBe(['full_name' => 'Original']);
})->with([
    'archived' => SubmissionStatus::Archived,
    'screened_out' => SubmissionStatus::ScreenedOut,
    'draft' => SubmissionStatus::Draft,
]);

it('names the capacity reason when refusing a screened-out row', function (): void {
    // Not cosmetic: I9a's whole argument for excluding `screened_out` is that it consumes no capacity slot,
    // and a maintainer who reads only "not allowed" is one step from widening EDITABLE to "tidy the inbox".
    expect(SubmissionEditException::illegalState(SubmissionStatus::ScreenedOut)->getMessage())
        ->toContain('screened out')
        ->and(SubmissionEditException::illegalState(SubmissionStatus::Archived)->getMessage())
        ->toContain('archived');
});

it('edits each of the four accepted states', function (SubmissionStatus $status): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, $status, ['full_name' => 'Original']);

    $this->service->edit($submission, editVersion($submission), ['full_name' => 'Corrected'], $this->owner);

    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])
        ->toBe('Corrected');
})->with([
    'submitted' => SubmissionStatus::Submitted,
    'under_review' => SubmissionStatus::UnderReview,
    'approved' => SubmissionStatus::Approved,
    'returned' => SubmissionStatus::Returned,
]);

/*
|--------------------------------------------------------------------------
| 2. Projections are REPLACED, never appended
|--------------------------------------------------------------------------
*/

it('replaces the scalar index rows rather than appending to them', function (): void {
    $version = editableIndexedForm($this->tenant, $this->owner, 'Indexed A');

    // Go through the real pipeline so the row is projected exactly as production projects it.
    $submission = submitForEdit($version, ['full_name' => 'Original', 'color' => 'r']);

    $before = SubmissionAnswerIndex::where('submission_id', $submission->id)->count();
    expect($before)->toBeGreaterThan(0);

    $this->service->edit($submission, $version, ['full_name' => 'Corrected', 'color' => 'b'], $this->owner);

    // ⚠️ THE COUNT IS THE ASSERTION. An implementation that inserted without deleting passes the value
    // check below and fails only here — and would silently double-count this submission in every filter,
    // export column and analytics series that reads the index.
    expect(SubmissionAnswerIndex::where('submission_id', $submission->id)->count())->toBe($before);

    $values = SubmissionAnswerIndex::where('submission_id', $submission->id)
        ->pluck('value_text', 'field_key')
        ->all();

    expect($values['full_name'])->toBe('Corrected')
        ->and($values['color'])->toBe('b');
});

it('drops the index row for an answer the edit clears', function (): void {
    $version = editableIndexedForm($this->tenant, $this->owner, 'Indexed B');
    $submission = submitForEdit($version, ['full_name' => 'Original', 'color' => 'r']);

    expect(SubmissionAnswerIndex::where('submission_id', $submission->id)->where('field_key', 'color')->count())
        ->toBe(1);

    $this->service->edit($submission, $version, ['full_name' => 'Original', 'color' => null], $this->owner);

    // The stale row must GO. Leaving it is the other half of the append bug: a filter on `color = red` would
    // keep returning a submission whose answer is now blank.
    expect(SubmissionAnswerIndex::where('submission_id', $submission->id)->where('field_key', 'color')->count())
        ->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 3. The demotion
|--------------------------------------------------------------------------
*/

it('demotes an approved submission to under_review and clears the approval stamps', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Approved, ['full_name' => 'Original']);
    $submission->forceFill([
        'validated_by' => $this->owner->id,
        'validated_at' => now(),
        'finalized_at' => now(),
    ])->save();

    $fresh = $this->service->edit($submission, editVersion($submission), ['full_name' => 'Corrected'], $this->owner);

    expect($fresh->status)->toBe(SubmissionStatus::UnderReview)
        ->and($fresh->validated_by)->toBeNull()
        ->and($fresh->validated_at)->toBeNull()
        ->and($fresh->finalized_at)->toBeNull();
});

it('leaves a non-approved status alone', function (SubmissionStatus $status): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, $status, ['full_name' => 'Original']);

    $fresh = $this->service->edit($submission, editVersion($submission), ['full_name' => 'Corrected'], $this->owner);

    expect($fresh->status)->toBe($status);
})->with([
    'submitted' => SubmissionStatus::Submitted,
    'under_review' => SubmissionStatus::UnderReview,
    'returned' => SubmissionStatus::Returned,
]);

it('records the answer change and the demotion as ONE audit row', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Approved, ['full_name' => 'Original']);
    $submission->forceFill(['validated_by' => $this->owner->id, 'validated_at' => now()])->save();

    $before = Audit::where('auditable_id', $submission->id)->count();

    $this->service->edit($submission, editVersion($submission), ['full_name' => 'Corrected'], $this->owner);

    $rows = Audit::where('auditable_id', $submission->id)->orderByDesc('created_at')->get();

    // ONE, not two. A ledger showing "approval revoked" beside "answers edited" as separate events invites
    // the reading that somebody revoked it deliberately.
    expect($rows->count() - $before)->toBe(1);

    $row = $rows->first();
    expect($row->event)->toBe(AuditEvent::Updated)
        ->and($row->auditable_type)->toBe('submission')
        // The actor is EXPLICIT, never Auth::id() — here doubly so, since the demotion just cleared
        // validated_by and a guessed actor could contradict the row it describes.
        ->and($row->user_id)->toBe((string) $this->owner->id)
        ->and($row->old_values['status'])->toBe('approved')
        ->and($row->new_values['status'])->toBe('under_review')
        // ⚠️ TOP-LEVEL `answers.<key>`, not nested. AuditRedactor matches the top level only; nesting these
        // under an `answers` key makes $piiAnswerKeys a silent no-op.
        ->and($row->old_values['answers.full_name'])->toBe('Original')
        ->and($row->new_values['answers.full_name'])->toBe('Corrected');
});

it('records only the answers that actually changed', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Original', 'color' => 'r',
    ]);

    $this->service->edit($submission, editVersion($submission), ['full_name' => 'Corrected', 'color' => 'r'], $this->owner);

    $row = Audit::where('auditable_id', $submission->id)->orderByDesc('created_at')->first();

    expect($row->new_values)->toHaveKey('answers.full_name')
        ->and($row->new_values)->not->toHaveKey('answers.color');
});

/*
|--------------------------------------------------------------------------
| 4. PII redaction — the first consumer of AuditLogger::$piiAnswerKeys
|--------------------------------------------------------------------------
*/

it('redacts a pii answer on both sides of the audit row', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Sensitive');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'nickname', FieldType::ShortText, 0);
    addFormField($version, $this->owner, 'national_id', FieldType::ShortText, 1, ['is_pii' => true]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'nickname' => 'Ann', 'national_id' => 'ID-11111',
    ]);

    $this->service->edit(
        $submission,
        editVersion($submission),
        ['nickname' => 'Ann', 'national_id' => 'ID-22222'],
        $this->owner,
    );

    $row = Audit::where('auditable_id', $submission->id)->orderByDesc('created_at')->first();

    // The row records THAT it changed, never what it said — on BOTH sides.
    expect($row->old_values['answers.national_id'])->not->toBe('ID-11111')
        ->and($row->new_values['answers.national_id'])->not->toBe('ID-22222')
        ->and($row->redacted_fields)->toContain('answers.national_id');
});

/*
|--------------------------------------------------------------------------
| 5. The replay clock
|--------------------------------------------------------------------------
*/

it('evaluates a today()-dependent relevance against the day the response was filed, not today', function (): void {
    // ⚠️ THE FAILURE THIS PINS IS SILENT DATA LOSS, not a validation error, which is why it is worth the
    // fixture. Relevance PRUNES: Stage 3 drops the answers of fields it decides were irrelevant. A field
    // gated on `today() = <the day the response was filed>` was relevant then and is irrelevant now — so an
    // edit run under `Carbon::now()` would prune it, and correcting a typo in an unrelated field would
    // DELETE a real answer with no error, no warning, and a success toast.
    //
    // `relevant_expression` is the mechanism, not a `constraint` validation rule: `ValidationRuleType` has
    // no constraint case, and `today()` is an EXPRESSION function (ExpressionEvaluator:228). The same clock
    // governs both, so pinning the relevance half pins the clock.
    $filedOn = now()->subYear();

    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Dated');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'full_name', FieldType::ShortText, 0);
    addFormField($version, $this->owner, 'same_day_note', FieldType::ShortText, 1, [
        'relevant_expression' => "today() = '{$filedOn->toDateString()}'",
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Original', 'same_day_note' => 'Recorded on the day',
    ]);
    $submission->forceFill(['submitted_at' => $filedOn])->save();

    // Correct an UNRELATED field. The gated answer is resubmitted unchanged.
    $this->service->edit($submission->refresh(), editVersion($submission), [
        'full_name' => 'Corrected', 'same_day_note' => 'Recorded on the day',
    ], $this->owner);

    $answers = SubmissionAnswer::where('submission_id', $submission->id)->value('answers');

    expect($answers['full_name'])->toBe('Corrected')
        // Survives ONLY under the replay clock. Swap `$submission->submitted_at` for `now()` in the service
        // and this key is gone — the mutation check for this behaviour.
        ->and($answers)->toHaveKey('same_day_note')
        ->and($answers['same_day_note'])->toBe('Recorded on the day');
});

/*
|--------------------------------------------------------------------------
| 6. Media is server-enforced read-only
|--------------------------------------------------------------------------
*/

it('ignores client-supplied media answers and keeps the stored ones', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'WithPhoto');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'full_name', FieldType::ShortText, 0);
    addFormField($version, $this->owner, 'photo', FieldType::FileUpload, 1);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $stored = [['id' => (string) Uuid::uuid7()]];
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Original', 'photo' => $stored,
    ]);

    // A hand-rolled PATCH body pointing `photo` at somebody else's attachment id. The UI renders this field
    // read-only, but a disabled control is a suggestion — the server has to be the one that says no.
    $this->service->edit($submission, editVersion($submission), [
        'full_name' => 'Corrected',
        'photo' => [['id' => (string) Uuid::uuid7()]],
    ], $this->owner);

    $answers = SubmissionAnswer::where('submission_id', $submission->id)->value('answers');

    expect($answers['full_name'])->toBe('Corrected')
        ->and($answers['photo'][0]['id'])->toBe($stored[0]['id']);
});

/*
|--------------------------------------------------------------------------
| 7. The announcement
|--------------------------------------------------------------------------
*/

it('announces submission.updated post-commit, with the demotion flag', function (): void {
    Event::fake([SubmissionUpdated::class]);

    $form = publishedInboxForm($this->tenant, $this->owner);
    $approved = seedInboxSubmission($form, $this->owner, SubmissionStatus::Approved, ['full_name' => 'A']);
    $submitted = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'B']);

    $this->service->edit($approved, editVersion($approved), ['full_name' => 'A2'], $this->owner);
    $this->service->edit($submitted, editVersion($submitted), ['full_name' => 'B2'], $this->owner);

    Event::assertDispatched(
        SubmissionUpdated::class,
        fn (SubmissionUpdated $e): bool => $e->submissionId === (string) $approved->id
            && $e->wasDemoted === true
            && $e->status === 'under_review',
    );

    // ⚠️ `wasDemoted` is PASSED, not inferred from the status: an edit to a row that was ALREADY under
    // review also ends `under_review`, and reporting a withdrawn approval there would be false.
    Event::assertDispatched(
        SubmissionUpdated::class,
        fn (SubmissionUpdated $e): bool => $e->submissionId === (string) $submitted->id
            && $e->wasDemoted === false,
    );
});

it('does not announce when the edit was refused', function (): void {
    Event::fake([SubmissionUpdated::class]);

    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Archived, ['full_name' => 'A']);

    expect(fn () => $this->service->edit($submission, editVersion($submission), ['full_name' => 'B'], $this->owner))
        ->toThrow(SubmissionEditException::class);

    Event::assertNotDispatched(SubmissionUpdated::class);
});

it('carries no answer values in the announced payload', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Secret name']);

    $captured = null;
    Event::listen(SubmissionUpdated::class, function (SubmissionUpdated $e) use (&$captured): void {
        $captured = $e->data();
    });

    $this->service->edit($submission, editVersion($submission), ['full_name' => 'Another secret'], $this->owner);

    // A webhook body and a Slack message are not places for respondent data — nor are the KEY names.
    expect(json_encode($captured))->not->toContain('Secret name')
        ->and(json_encode($captured))->not->toContain('Another secret')
        ->and(json_encode($captured))->not->toContain('full_name')
        ->and($captured['status'])->toBe('submitted')
        ->and($captured['approval_withdrawn'])->toBeFalse();
});

it('keeps submission.updated in the webhook delivery CHECK constraint', function (): void {
    // The 23514 trap: a tenant may subscribe to any DomainEventType, so the constraint has to have grown
    // with the enum. Reading the live constraint is the only assertion that proves the migration ran.
    $definition = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'webhook_deliveries_event_type_check'"
    );

    expect($definition->def)->toContain(DomainEventType::SubmissionUpdated->value);
});

it('gives every domain event type a label, so /webhooks and /integrations cannot 500', function (): void {
    foreach (DomainEventType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
});

/*
|--------------------------------------------------------------------------
| 8. Authorization
|--------------------------------------------------------------------------
*/

it('grants update to owner/admin tenant-wide', function (string $role): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $user = User::factory()->create();
    enterTenant($this->tenant->id, $user->id);
    makeActiveMember($user, $role);

    expect($user->can('update', $submission))->toBeTrue();
})->with(['owner', 'admin']);

it('refuses update to a reviewer, who holds neither edit key', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $reviewer = User::factory()->create();
    enterTenant($this->tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($form, $reviewer, ResourceCapacity::Reviewer);

    // The separation this increment turns on: a reviewer may DECIDE a submission's outcome and may not
    // rewrite the answers they are deciding about.
    expect($reviewer->can('review', $submission))->toBeTrue()
        ->and($reviewer->can('update', $submission))->toBeFalse();
});

it('grants a form editor update only on forms they hold EDITOR capacity on', function (): void {
    $mine = publishedInboxForm($this->tenant, $this->owner, 'Mine');
    $other = publishedInboxForm($this->tenant, $this->owner, 'Other');
    $onMine = seedInboxSubmission($mine, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'X']);
    $onOther = seedInboxSubmission($other, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Y']);

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($mine, $editor, ResourceCapacity::Editor);

    expect($editor->can('update', $onMine))->toBeTrue()
        ->and($editor->can('update', $onOther))->toBeFalse();
});

it('refuses update on a REVIEWER-capacity grant, even to a role holding submissions.edit.own', function (): void {
    // The G10a tightening, applied to editing: reviewer capacity on an interior scope node must not confer
    // write access to every submission beneath it.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($form, $editor, ResourceCapacity::Reviewer);

    expect($editor->can('update', $submission))->toBeFalse();
});

it('never grants update to a respondent on the strength of having submitted it', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);

    $respondent = User::factory()->create();
    enterTenant($this->tenant->id, $respondent->id);
    makeActiveMember($respondent, 'reviewer');
    $submission = seedInboxSubmission($form, $respondent, SubmissionStatus::Submitted, ['full_name' => 'X']);

    // I8a gave respondents view() so their notification links resolve. update() deliberately has no such
    // arm, and SubmissionPolicy::view()'s docblock forbids adding one "for symmetry".
    expect($respondent->can('view', $submission))->toBeTrue()
        ->and($respondent->can('update', $submission))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| 9. The guards an adversarial review found untested
|--------------------------------------------------------------------------
*/

it('refuses a second editor whose page was loaded before the first one saved', function (): void {
    // ⚠️ THE LOST-UPDATE RACE. Everything before the transaction — the stored document, the media merge,
    // Stage 1, Stage 3 — runs WITHOUT the lock, and the write is a whole-document REPLACE. Without the
    // checksum guard, editor B's save silently reverts every field editor A corrected, and B's audit row is
    // diffed against B's stale snapshot: it records B's one change and says nothing about undoing A's.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ann', 'color' => 'r',
    ]);

    // The token both editors' pages were rendered from.
    $baseline = SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum');

    // Editor A commits first, from that baseline.
    $this->service->edit($submission->fresh(), editVersion($submission), [
        'full_name' => 'Anna', 'color' => 'r',
    ], $this->owner, checkBaseline: true, baseline: $baseline);

    // Editor B saves a page rendered BEFORE A's write — the same, now-stale, baseline.
    expect(fn () => $this->service->edit($submission, editVersion($submission), [
        'full_name' => 'Ann', 'color' => 'b',
    ], $this->owner, checkBaseline: true, baseline: $baseline))->toThrow(SubmissionEditException::class);

    // A's correction survives, which is the whole point.
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])
        ->toBe('Anna');
});

it('tells the refused editor what to DO, not just that there was a conflict', function (): void {
    // The page they are looking at is stale, so clicking Save again lands in the same refusal forever.
    expect(SubmissionEditException::concurrentlyModified()->getMessage())->toContain('Reload');
});

/*
|--------------------------------------------------------------------------
| 9b. The baseline guard, exercised against REAL checksums on BOTH sides
|--------------------------------------------------------------------------
| ⛔ EVERY ACCEPTED WRITE ABOVE COMPARES null AGAINST null, AND THAT IS NOT A COINCIDENCE.
| `SubmissionAnswerFactory` stamps no `answers_content_checksum`, so `seedInboxSubmission()` produces the
| LEGACY row — a submission written before the pipeline stamped one. {@see EditSubmissionAnswersRequest}
| supports that row on purpose (`present` + `nullable`, so it stays editable), and the cases above are the
| right cases for it. What they cannot show is anything about a document that HAS a checksum, because the
| only fact they pin is "null baseline against a non-null stored checksum is refused".
|
| ⚠️ THAT LEAVES THE GUARD PINNED FROM ONE SIDE ONLY, AND BOTH FAILURE DIRECTIONS WERE MEASURED, NOT ARGUED.
| Two mutations of the comparison at {@see SubmissionAnswerEditService::edit()} left the whole 60-test
| concurrency suite GREEN before these cases existed:
|
|   (1) `$baseline === null && $stored->answers_content_checksum !== null` — the guard reduced to a PRESENCE
|       check. Two editors each holding a real, DIFFERENT token never conflict, so the lost update the guard
|       exists to prevent is silently live again. Killed by `refuses a second editor when BOTH tokens are
|       real` below.
|   (2) `$baseline !== null || $stored->answers_content_checksum !== $baseline` — every non-null baseline
|       refused. Every submission written by the real pipeline becomes PERMANENTLY UNEDITABLE, which is the
|       backlog row's own phrase. Killed by `accepts an edit whose baseline is the document's real checksum`
|       below.
|
| ⚠️ AND THE OBVIOUS PROBE IS THE WRONG ONE. DELETING the guard outright is ALREADY caught: editor B re-reads
| the answer row at `edit()`'s top, so the under-lock re-check compares a value against itself, B's write is
| accepted and the cases above redden. The suite was never blind to REMOVING this guard — only to WEAKENING
| it. A deletion probe here would have measured zero and proved nothing.
|
| ⚠️ THE FIX IS NOT IN THE FACTORY. Stamping a checksum in `SubmissionAnswerFactory` would convert the legacy
| rows rather than add the production ones — deleting the only coverage the nullable path has, and changing
| the fixture shape under every other caller of `seedInboxSubmission()` in both lanes' suites. These cases go
| through `submitForEdit()`, the file's own pipeline helper, which no concurrency case used.
*/

it('accepts an edit whose baseline is the document\'s REAL checksum, and moves that checksum', function (): void {
    $version = FormVersion::findOrFail(publishedInboxForm($this->tenant, $this->owner)->current_published_version_id);
    $submission = submitForEdit($version, ['full_name' => 'Ann', 'color' => 'r']);

    $baseline = SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum');

    // ⚠️ THE NON-VACUITY GUARD, AND WITHOUT IT THIS CASE SILENTLY BECOMES ANOTHER null === null. If a future
    // change stops the pipeline stamping the column, every assertion below still passes while measuring the
    // legacy path a second time. This is the assertion that makes the word "REAL" in the name true.
    expect($baseline)->toBeString()->not->toBe('');

    $fresh = $this->service->edit($submission, $version, [
        'full_name' => 'Anna', 'color' => 'r',
    ], $this->owner, checkBaseline: true, baseline: $baseline);

    expect($fresh->status)->toBe(SubmissionStatus::Submitted);

    $after = SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum');

    // The correction landed, and the token MOVED. A guard that accepted the write but left the checksum
    // stale would hand the next editor a baseline that matches a document it no longer describes.
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])->toBe('Anna')
        ->and($after)->toBeString()->not->toBe('')
        ->and($after)->not->toBe($baseline);
});

it('refuses a second editor when BOTH tokens are real and only B\'s is stale', function (): void {
    // The case in section 9 runs this race with a null token on both sides. This one runs it the way
    // production runs it — two pages, each rendered from a checksum that actually exists — so the guard has
    // to compare the VALUES rather than merely notice that one was supplied.
    $version = FormVersion::findOrFail(publishedInboxForm($this->tenant, $this->owner, 'Race')->current_published_version_id);
    $submission = submitForEdit($version, ['full_name' => 'Ann', 'color' => 'r']);

    $stale = SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum');
    expect($stale)->toBeString()->not->toBe('');

    // Editor A saves from that baseline and moves the token.
    $this->service->edit($submission->fresh(), $version, [
        'full_name' => 'Anna', 'color' => 'r',
    ], $this->owner, checkBaseline: true, baseline: $stale);

    $current = SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum');

    // ⚠️ BOTH SIDES NON-NULL AND DIFFERENT — the fact this whole section exists for. Asserted rather than
    // assumed, because if A's write did not move the token the refusal below would be testing nothing.
    expect($current)->toBeString()->not->toBe('')->and($current)->not->toBe($stale);

    // Editor B's page was rendered before A saved, so B holds a real token for a document that has moved on.
    expect(fn () => $this->service->edit($submission, $version, [
        'full_name' => 'Ann', 'color' => 'b',
    ], $this->owner, checkBaseline: true, baseline: $stale))->toThrow(SubmissionEditException::class);

    // A's correction survives and B's revert never landed.
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers'))
        ->toMatchArray(['full_name' => 'Anna', 'color' => 'r']);
});

it('re-asserts the state UNDER THE LOCK, not only on the caller\'s copy', function (): void {
    // The pre-check at the top of edit() reads the model the caller passed in. A reviewer archiving the row
    // between page load and Save is an ordinary race — updating the row underneath a stale model is how it
    // is reproduced. Delete the in-transaction assertEditable() and this is the only test that reddens.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ann']);

    // Raw update so the in-memory $submission keeps saying `submitted`.
    DB::table('submissions')->where('id', $submission->id)->update(['status' => 'archived']);

    expect(fn () => $this->service->edit($submission, editVersion($submission), ['full_name' => 'Bea'], $this->owner))
        ->toThrow(SubmissionEditException::class);

    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])->toBe('Ann');
});

it('raises the event only after the transaction has committed', function (): void {
    // `Event::assertDispatched` records dispatch, never its POSITION — it would happily pass with `event()`
    // moved back inside the closure, and then a rolled-back edit would still announce to every subscribed
    // webhook and Slack channel that answers changed. This is `SubmissionReviewEventsTest`'s idiom.
    $levelInsideListener = null;
    Event::listen(SubmissionUpdated::class, function () use (&$levelInsideListener): void {
        $levelInsideListener = DB::transactionLevel();
    });

    $outerLevel = DB::transactionLevel();

    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ann']);
    $this->service->edit($submission, editVersion($submission), ['full_name' => 'Bea'], $this->owner);

    // Under RefreshDatabase the suite already holds one uncommitted transaction, so "post-commit" means the
    // listener sees the SAME level it started at — not one deeper.
    expect($levelInsideListener)->toBe($outerLevel);
});

it('drops a media answer the client invents for a field that has none stored', function (): void {
    // ⚠️ THE SECURITY-RELEVANT HALF OF mergeMedia, and the branch its own docblock is written about. The
    // other media test has a STORED photo, so the restore overwrites whatever arrived and the `unset()` is
    // never exercised. Here there is nothing to restore: without the unset, a hand-rolled PATCH body pointing
    // at somebody else's attachment id survives straight into the stored document.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'NoPhotoYet');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'full_name', FieldType::ShortText, 0);
    addFormField($version, $this->owner, 'photo', FieldType::FileUpload, 1);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ann']);

    $this->service->edit($submission, editVersion($submission), [
        'full_name' => 'Bea',
        'photo' => [['id' => (string) Uuid::uuid7()]],
    ], $this->owner);

    $answers = SubmissionAnswer::where('submission_id', $submission->id)->value('answers');

    expect($answers['full_name'])->toBe('Bea')
        ->and($answers['photo'] ?? null)->toBeNull();
});

it('rewrites attachment_refs when relevance prunes a media answer', function (): void {
    // An edit cannot ADD media, but relevance can REMOVE it. Without the rewrite the JSONB — the declared
    // source of truth — says the submission holds no file while the denormalised list still names one.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'GatedPhoto');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'has_photo', FieldType::YesNo, 0);
    addFormField($version, $this->owner, 'photo', FieldType::FileUpload, 1, [
        'relevant_expression' => "\${has_photo} = 'yes'",
    ]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $attachmentId = (string) Uuid::uuid7();
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'has_photo' => 'yes', 'photo' => [['id' => $attachmentId]],
    ]);
    SubmissionAnswer::where('submission_id', $submission->id)->update(['attachment_refs' => [$attachmentId]]);

    $this->service->edit($submission, editVersion($submission), ['has_photo' => 'no'], $this->owner);

    $row = SubmissionAnswer::where('submission_id', $submission->id)->first();

    expect($row->answers)->not->toHaveKey('photo')
        ->and($row->attachment_refs)->toBe([]);
});

it('reports no change when a repeat group is resubmitted with its keys in a different order', function (): void {
    // PHP's `===` on arrays requires identical KEY ORDER, and the order of an object-valued answer is the
    // CLIENT'S. Without the order-insensitive compare, every edit to a form with a repeat group would claim
    // the group changed — noise in the one table answerDiff() tells readers to replay.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Roster');
    $version = $form->draftVersion;
    $section = FormSection::create([
        'form_version_id' => $version->id, 'key' => 'members', 'label' => 'Members',
        'sequence' => 1, 'is_repeatable' => true, 'min_instances' => 1,
    ]);
    addFormField($version, $this->owner, 'note', FieldType::ShortText, 0);
    addFormField($version, $this->owner, 'first_name', FieldType::ShortText, 1, ['form_section_id' => $section->id]);
    addFormField($version, $this->owner, 'age', FieldType::Integer, 2, ['form_section_id' => $section->id]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'note' => 'Original',
        'members' => [['first_name' => 'Ann', 'age' => 30]],
    ]);

    // Same content, keys reversed — exactly what a differently-ordered client serialisation produces.
    $this->service->edit($submission, editVersion($submission), [
        'note' => 'Corrected',
        'members' => [['age' => 30, 'first_name' => 'Ann']],
    ], $this->owner);

    $row = Audit::where('auditable_id', $submission->id)->orderByDesc('created_at')->first();

    expect($row->new_values)->toHaveKey('answers.note')
        ->and($row->new_values)->not->toHaveKey('answers.members');
});

it('redacts a pii answer to the placeholder, not merely to something different', function (): void {
    // `->not->toBe('ID-11111')` passes for null, for a missing key, and for any wrong placeholder. This is
    // the assertion that catches a redactor which blanks instead of placeholdering.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Sensitive2');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'nickname', FieldType::ShortText, 0);
    addFormField($version, $this->owner, 'national_id', FieldType::ShortText, 1, ['is_pii' => true]);
    addFormField($version, $this->owner, 'salary', FieldType::ShortText, 2, ['is_sensitive' => true]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $form = $form->refresh();
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'nickname' => 'Ann', 'national_id' => 'ID-11111', 'salary' => '100',
    ]);

    $this->service->edit($submission, editVersion($submission), [
        'nickname' => 'Ann', 'national_id' => 'ID-22222', 'salary' => '200',
    ], $this->owner);

    $row = Audit::where('auditable_id', $submission->id)->orderByDesc('created_at')->first();

    expect($row->old_values['answers.national_id'])->toBe(AuditRedactor::PLACEHOLDER)
        ->and($row->new_values['answers.national_id'])->toBe(AuditRedactor::PLACEHOLDER)
        // `is_sensitive` too, not only `is_pii` — the second half of the condition, previously untested.
        ->and($row->new_values['answers.salary'])->toBe(AuditRedactor::PLACEHOLDER)
        // The anti-vacuity control: a non-flagged answer is NOT placeholdered.
        ->and($row->new_values)->not->toHaveKey('answers.nickname');
});

/**
 * Submit a real response through the pipeline so its projections exist exactly as production writes them.
 */
function submitForEdit(FormVersion $version, array $answers): Submission
{
    $result = app(SubmissionPipeline::class)->submit(
        new SubmissionPayload(
            version: $version,
            answers: $answers,
            source: SubmissionSource::Manual,
            respondentUserId: null,
        ),
    );

    return $result->submission->refresh();
}
