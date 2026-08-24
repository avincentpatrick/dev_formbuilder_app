<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionReviewException;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\FormAcceptanceGuard;
use App\Services\Submissions\PublicFormPresenter;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Services\Submissions\SubmissionReviewService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9a — `SubmissionStatus::ScreenedOut`, end to end.
|--------------------------------------------------------------------------
| `tests/Unit/Submissions/FinalizedStatusTest.php` owns the PREDICATE. This file owns everything the
| predicate's answer then has to be true of: that BOTH finalize doors reach it, that a screened-out response
| does not consume a `max_responses` slot, that the public capacity banner agrees with the guard, and that the
| state is terminal.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
    $this->drafts = app(SubmissionDraftService::class);
});

/**
 * The H7 router: a `hidden` gate in a section of its own, and two branches gated on its value. Visited with
 * no prefill the whole step graph is empty — Doc #27 §4.1's specified terminal state, and the shape the
 * seeded "Branching Router" form has. `hidden` is `PdfFieldRole::Omitted`, so predicate 2 also drops the
 * gate's own section: without that, this form would always show one step and never screen anyone out.
 */
function screenedOutRouter(Tenant $tenant, User $owner, array $formAttributes = []): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Router');
    $draft = $form->draftVersion;

    $gate = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'gate', 'label' => 'Gate', 'sequence' => 1,
    ]);
    // `prefill_source: url` is REQUIRED, not decoration: `StructuralAnswerNormalizer::coerceHidden()` keeps a
    // hidden field's submitted value only for the URL source — every other source means the value is not the
    // client's to decide, so Stage 1 drops it. Without this config the gate is always null, both branches are
    // always irrelevant, and every submission screens out — including the ones this file uses as controls.
    addFormField($draft, $owner, 'role', FieldType::Hidden, 1, [
        'form_section_id' => $gate->id,
        'config' => ['prefill_source' => 'url'],
    ]);

    $staff = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'staff', 'label' => 'Staff details',
        'sequence' => 2, 'relevant_expression' => "\${role} = 'staff'",
    ]);
    addFormField($draft, $owner, 'staff_number', FieldType::ShortText, 2, ['form_section_id' => $staff->id]);

    app(PublishService::class)->publish($form->refresh(), $owner);
    $form = $form->refresh();

    if ($formAttributes !== []) {
        $form->forceFill($formAttributes)->save();
        $form = $form->refresh();
    }

    return $form;
}

function routerPayload(Form $form, array $answers, ?string $uuid = null): SubmissionPayload
{
    return new SubmissionPayload(
        version: FormVersion::findOrFail($form->current_published_version_id),
        answers: $answers,
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    );
}

/*
|--------------------------------------------------------------------------
| Both finalize doors, and the agreement between them.
|--------------------------------------------------------------------------
*/

it('records a bare router submit as screened out, and a routed one as submitted', function (): void {
    $form = screenedOutRouter($this->tenant, $this->user);

    $bare = $this->pipeline->submit(routerPayload($form, []))->submission;
    $routed = $this->pipeline->submit(routerPayload($form, ['role' => 'staff', 'staff_number' => 'A1']))->submission;

    expect($bare->status)->toBe(SubmissionStatus::ScreenedOut)
        ->and($routed->status)->toBe(SubmissionStatus::Submitted);
});

it('reaches the same two answers through promote() as through submit()', function (): void {
    // THE MUTATION GUARD for the `$sections = $version->sections()->get();` line in promote(). Drop it and
    // StepProjection walks an EMPTY section collection, every projection is empty, and every promoted draft
    // is classified screened_out — so the first half alone would still pass. It is the SECOND expectation,
    // that a routed promote is `submitted`, that reddens.
    $form = screenedOutRouter($this->tenant, $this->user);

    $bareUuid = Uuid::uuid7()->toString();
    $this->drafts->saveDraft(routerPayload($form, [], $bareUuid));
    $barePromoted = $this->drafts->promote(Submission::query()->where('client_submission_uuid', $bareUuid)->firstOrFail())->submission;

    $routedUuid = Uuid::uuid7()->toString();
    $this->drafts->saveDraft(routerPayload($form, ['role' => 'staff', 'staff_number' => 'A1'], $routedUuid));
    $routedPromoted = $this->drafts->promote(Submission::query()->where('client_submission_uuid', $routedUuid)->firstOrFail())->submission;

    expect($barePromoted->status)->toBe(SubmissionStatus::ScreenedOut)
        ->and($routedPromoted->status)->toBe(SubmissionStatus::Submitted);
});

/*
|--------------------------------------------------------------------------
| The capacity bug this increment exists to fix.
|--------------------------------------------------------------------------
*/

it('does not burn a max_responses slot on a screened-out respondent', function (): void {
    // docs/workflow-branching-design.md:140, discharged. Cap of 1, one real response, then a hundred
    // screen-outs would still leave the form full — but a screen-out arriving FIRST must not fill it.
    $form = screenedOutRouter($this->tenant, $this->user, ['max_responses' => 1]);

    $this->pipeline->submit(routerPayload($form, [])); // screened out — consumes nothing
    $this->pipeline->submit(routerPayload($form, [])); // and neither does a second

    $real = $this->pipeline->submit(routerPayload($form, ['role' => 'staff', 'staff_number' => 'A1']))->submission;

    expect($real->status)->toBe(SubmissionStatus::Submitted)
        ->and(Submission::query()->where('form_id', $form->id)->consumesCapacity()->count())->toBe(1);
});

it('still refuses the response that genuinely exceeds the cap', function (): void {
    // THE INVERSE, and without it the fix above is indistinguishable from "the cap stopped working".
    $form = screenedOutRouter($this->tenant, $this->user, ['max_responses' => 1]);

    $this->pipeline->submit(routerPayload($form, [])); // screened out
    $this->pipeline->submit(routerPayload($form, ['role' => 'staff', 'staff_number' => 'A1'])); // the one slot

    expect(fn () => $this->pipeline->submit(routerPayload($form, ['role' => 'staff', 'staff_number' => 'B2'])))
        ->toThrow(FormNotAcceptingSubmissionException::class, 'response limit');

    expect(Submission::query()->where('form_id', $form->id)->consumesCapacity()->count())->toBe(1);
});

it('keeps the public capacity banner agreeing with the guard', function (): void {
    // The six predicate sites move together or this lies: the guard would accept while the runtime told a
    // respondent the form was full.
    //
    // ⚠️ IT GOES THROUGH THE REAL `PublicFormPresenter`, and the first draft of this test did not — that
    // version called the pure `FormScheduleView::present()` and handed it a count it computed ITSELF with
    // `consumesCapacity()`, i.e. the same scope the guard uses. It could not fail: it compared the scope to
    // itself and asserted arithmetic. The presenter's own private `capacityCount()` is the thing that can
    // drift from the guard, so it has to be the thing under test.
    $form = screenedOutRouter($this->tenant, $this->user, ['max_responses' => 2]);

    $this->pipeline->submit(routerPayload($form, []));
    $this->pipeline->submit(routerPayload($form, []));
    $this->pipeline->submit(routerPayload($form, ['role' => 'staff', 'staff_number' => 'A1']));

    $version = FormVersion::findOrFail($form->refresh()->current_published_version_id);
    $schedule = app(PublicFormPresenter::class)->present($form, $version)['form']['schedule'];

    expect($schedule['acceptance'])->toBe('open')
        ->and($schedule['remaining'])->toBe(1);

    // The guard agrees — the same three rows, asked the enforcement question rather than the display one.
    // Without this half the test pins the banner alone and the pair could still disagree.
    expect(fn () => app(FormAcceptanceGuard::class)->assertCapacity($form))->not->toThrow(Exception::class);
});

it('leaves screened-out rows in the countable set, because the inbox lists them', function (): void {
    // The deliberate asymmetry between the two scopes. `screened_out` is a real row the tenant received, so
    // it counts as a response; it just did not consume a purchased slot. Excluding it from `countable()`
    // would make the analytics tile disagree with the list a reviewer is looking at.
    $form = screenedOutRouter($this->tenant, $this->user);

    $this->pipeline->submit(routerPayload($form, []));

    expect(Submission::query()->where('form_id', $form->id)->countable()->count())->toBe(1)
        ->and(Submission::query()->where('form_id', $form->id)->consumesCapacity()->count())->toBe(0);
});

it('spells the capacity predicate as two not-equal conjuncts, never as NOT IN', function (): void {
    // A SHAPE pin, in the spirit of `AnalyticsIndexShapeTest`: pin the structure, never the planner.
    //
    // `whereNotIn` is the obvious spelling and it is the wrong one. The hot consumer is assertCapacity(),
    // running under lockForUpdate() inside the finalize transaction and served by the partial index
    // `submissions_form_finalized_idx (… ) WHERE status <> 'draft'`. Postgres uses a partial index only when
    // it can PROVE the index predicate from the query's clauses; a bare not-equal conjunct on `status`
    // matches it verbatim, while NOT IN compiles to `<> ALL (...)` and the implication is not guaranteed to
    // be established. The failure mode is a sequential scan on the ingest path under a lock, with every
    // correctness test still green — so the guard has to be structural.
    //
    // The column is asserted QUALIFIED too: unqualified `status` is ambiguous the moment a caller joins
    // `forms`, which has one of its own, and analytics joins exactly that way.
    $query = Submission::query()->consumesCapacity();
    $sql = strtolower($query->toSql());

    expect(substr_count($sql, '"submissions"."status" != ?'))->toBe(2)
        ->and($sql)->not->toContain('not in')
        ->and($sql)->not->toContain('<> all');

    // The BINDINGS too, not only the shape. Structure alone would be satisfied by two conjuncts excluding
    // the wrong pair of statuses — `draft` twice, say, which is a scope that silently reverts the fix.
    expect($query->getBindings())->toContain('draft', 'screened_out');
});

/*
|--------------------------------------------------------------------------
| Terminality.
|--------------------------------------------------------------------------
*/

it('refuses every review transition out of screened out', function (): void {
    $form = screenedOutRouter($this->tenant, $this->user);
    $row = $this->pipeline->submit(routerPayload($form, []))->submission;
    $service = app(SubmissionReviewService::class);

    expect(fn () => $service->markUnderReview($row, $this->user))->toThrow(SubmissionReviewException::class)
        ->and(fn () => $service->approve($row, $this->user))->toThrow(SubmissionReviewException::class)
        ->and(fn () => $service->returnToRespondent($row, $this->user, 'why'))->toThrow(SubmissionReviewException::class)
        ->and(fn () => $service->archive($row, $this->user))->toThrow(SubmissionReviewException::class);

    expect(Submission::findOrFail($row->id)->status)->toBe(SubmissionStatus::ScreenedOut);
});

it('archives exactly the four statuses that consumed a slot, and no future one by default', function (): void {
    // Written as a sweep over cases() rather than as "screened_out cannot be archived", so that a SEVENTH
    // status added later reddens THIS file rather than shipping silently archivable. Archiving is the
    // dangerous direction: `archived` consumes capacity, so widening it retroactively overfills a paid cap.
    $form = screenedOutRouter($this->tenant, $this->user);
    $service = app(SubmissionReviewService::class);

    $archivable = [];
    foreach (SubmissionStatus::cases() as $status) {
        $row = Submission::factory()->forVersion(FormVersion::findOrFail($form->current_published_version_id))
            ->create(['status' => $status, 'submitted_at' => now()]);

        try {
            $service->archive($row, $this->user);
            $archivable[] = $status->value;
        } catch (SubmissionReviewException) {
            // refused — the default, and the right one for anything not named below
        }
    }

    expect($archivable)->toBe(['submitted', 'under_review', 'approved', 'returned']);
});

/*
|--------------------------------------------------------------------------
| Increment M12 — the pre-lock lost update reaches THIS predicate, not only the answer document.
|--------------------------------------------------------------------------
| FinalizedStatus::for() is handed promote()'s PRE-LOCK SemanticResult, and its own docblock demands "this
| finalize's OWN Stage-3 result — never a cached or borrowed one". A save committing inside promote()'s
| window made that result borrowed by TIMING rather than by wiring, and this predicate is the difference
| between consuming a purchased `max_responses` slot and not.
*/

it('cannot stamp screened_out from a Stage-3 result the stored document has already moved past', function (): void {
    // The sharpest form of the M12 defect. Device A resumes a bare router draft — nobody routed, so the
    // pre-lock Stage 3 says `screened_out`. Device B supplies the gate inside promote()'s window. Before M12
    // this finalized as `screened_out`: a row labelled "was shown no questions" over a document that answers
    // two, consuming NO slot against a cap the tenant paid for, and unrecoverable because the row was final.
    $form = screenedOutRouter($this->tenant, $this->user, ['max_responses' => 1]);
    $uuid = Uuid::uuid7()->toString();

    $this->drafts->saveDraft(routerPayload($form, [], $uuid));
    $id = Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail()->id;

    $fired = interleaveOnPromoteRead(function () use ($form, $uuid): void {
        test()->drafts->saveDraft(routerPayload($form, ['role' => 'staff', 'staff_number' => 'A1'], $uuid));
    });

    expect(fn () => $this->drafts->promote(Submission::findOrFail($id)))
        ->toThrow(SubmissionConflictException::class, 'This draft was updated on another device.');

    expect($fired())->toBeTrue()
        ->and(Submission::findOrFail($id)->status)->toBe(SubmissionStatus::Draft)
        ->and(Submission::query()->where('form_id', $form->id)->consumesCapacity()->count())->toBe(0);

    // ⚠️ AND THE REFUSAL IS NOT A DEAD END — the retry the message names is what produces the classification
    // the stored document actually warrants. Without this half the case would be satisfied by a promote()
    // that simply never worked.
    $retried = $this->drafts->promote(Submission::findOrFail($id))->submission;

    expect($retried->status)->toBe(SubmissionStatus::Submitted)
        ->and(Submission::query()->where('form_id', $form->id)->consumesCapacity()->count())->toBe(1);
});
