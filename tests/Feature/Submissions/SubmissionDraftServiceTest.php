<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Audit;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attachments\AttachmentStorageService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H9a — the server-side DRAFT substrate (SubmissionDraftService). A draft is Stage-1-normalized but
| Stage-3-skipped, its 409 content-conflict rule SUSPENDED (overwrite-in-place on every save under one
| client_submission_uuid) and RE-ARMED on promotion, which flips the SAME row draft→submitted, runs the full
| Stage 3 exactly once, and reuses the shared SubmissionFinalizer tail. No metering / projection / audit /
| event happens until promotion. (The SubmissionPipeline extraction is behaviour-preserving — that is proven
| by SubmissionPipelineTest passing unmodified.)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->drafts = app(SubmissionDraftService::class);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** Build a form, populate its draft via $build, publish it, and return the published version. */
function draftFormPublish(Tenant $tenant, User $user, Closure $build): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'Resumable Survey');
    $build($form->draftVersion, $user);

    return app(PublishService::class)->publish($form->refresh(), $user);
}

/**
 * A representative resumable form: a required text field, a plain text field, a relevance-gated field, a
 * queryable integer, and a calculated field (queryable) — enough to exercise required/relevance/calc/index on
 * promotion.
 */
function resumableVersion(Tenant $tenant, User $user): FormVersion
{
    return draftFormPublish($tenant, $user, function (FormVersion $draft, User $u): void {
        addFormField($draft, $u, 'applicant', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
        addFormField($draft, $u, 'country', FieldType::ShortText, 1);
        addFormField($draft, $u, 'state', FieldType::ShortText, 2, ['relevant_expression' => "\${country} = 'US'"]);
        addFormField($draft, $u, 'age', FieldType::Integer, 3, ['is_queryable' => true, 'indexed_data_type' => IndexedDataType::Number]);
        addFormField($draft, $u, 'double_age', FieldType::Calculated, 4, [
            'config' => ['calculated_formula' => '${age} * 2'],
            'is_queryable' => true,
            'indexed_data_type' => IndexedDataType::Number,
        ]);
    });
}

function storedChecksum(string $submissionId): ?string
{
    return SubmissionAnswer::query()->where('submission_id', $submissionId)->value('answers_content_checksum');
}

it('HEADLINE: suspends the checksum 409 across draft saves, re-arms on promotion, runs Stage 3 exactly once', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    Event::fake([SubmissionCreated::class]);
    $uuid = Str::uuid()->toString();

    $payload = fn (array $answers): SubmissionPayload => new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    );

    // Save 1 — creates the draft; missing the required `applicant` is fine (Stage 3 is skipped for drafts).
    $s1 = $this->drafts->saveDraft($payload(['age' => '30']));
    $sum1 = storedChecksum($s1->submission->id);
    expect($s1->created)->toBeTrue()
        ->and($s1->submission->status)->toBe(SubmissionStatus::Draft)
        ->and($s1->submission->completeness_percent)->toBe(25) // 1 of 4 answerable (applicant/country/state/age)
        ->and(Submission::query()->count())->toBe(1)
        ->and(SubmissionAnswerIndex::query()->count())->toBe(0);

    // Save 2 — SAME uuid, materially different content → NOT a 409, an in-place overwrite (the suspension).
    $s2 = $this->drafts->saveDraft($payload(['age' => '31', 'country' => 'CA']));
    $sum2 = storedChecksum($s2->submission->id);
    expect($s2->created)->toBeFalse()
        ->and($s2->submission->id)->toBe($s1->submission->id)
        ->and($s2->submission->completeness_percent)->toBe(50)
        ->and($sum2)->not->toBe($sum1) // checksum recomputed + stored each save, never conflicts
        ->and(Submission::query()->count())->toBe(1);

    // Save 3 — now valid (required present) and complete.
    $s3 = $this->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '31', 'country' => 'CA', 'state' => 'Alberta']));
    expect($s3->created)->toBeFalse()
        ->and(storedChecksum($s3->submission->id))->not->toBe($sum2)
        ->and($s3->submission->completeness_percent)->toBe(100)
        ->and($s3->submission->status)->toBe(SubmissionStatus::Draft)
        ->and($s3->submission->submitted_at)->toBeNull();

    // Nothing is projected, metered, or audited while it is a draft.
    expect(SubmissionAnswerIndex::query()->count())->toBe(0);
    Event::assertNotDispatched(SubmissionCreated::class);

    // Promote — flips the SAME row, runs Stage 3 once, projects, prunes, computes, audits, fires one event.
    $promoted = $this->drafts->promote($s3->submission);
    expect($promoted->created)->toBeTrue()
        ->and($promoted->submission->id)->toBe($s1->submission->id)
        ->and($promoted->submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($promoted->submission->submitted_at)->not->toBeNull()
        ->and($promoted->submission->draft_expires_at)->toBeNull()
        ->and(Submission::query()->count())->toBe(1);

    $doc = SubmissionAnswer::query()->findOrFail($promoted->submission->id);
    expect($doc->answers)->toHaveKeys(['applicant', 'age', 'country', 'double_age'])
        ->and($doc->answers)->not->toHaveKey('state')  // relevance-pruned (country != US)
        ->and($doc->answers['double_age'])->toEqual(62); // calc write-back

    $indexKeys = SubmissionAnswerIndex::query()->where('submission_id', $promoted->submission->id)->pluck('field_key')->sort()->values()->all();
    expect($indexKeys)->toBe(['age', 'double_age']);

    expect(Audit::query()->where('auditable_type', 'submission')->where('auditable_id', $promoted->submission->id)->where('event', 'created')->exists())->toBeTrue();
    Event::assertDispatchedTimes(SubmissionCreated::class, 1);

    // RE-ARM: the ordinary pipeline now governs the finalized row's idempotency again.
    $sameContent = $this->pipeline->submit($payload(['applicant' => 'Ada', 'age' => '31', 'country' => 'CA', 'state' => 'Alberta']));
    expect($sameContent->created)->toBeFalse()                       // byte-identical replay → 200 no-op
        ->and($sameContent->submission->id)->toBe($promoted->submission->id)
        ->and(Submission::query()->count())->toBe(1);

    expect(fn () => $this->pipeline->submit($payload(['applicant' => 'Zed', 'age' => '31', 'country' => 'CA', 'state' => 'Alberta'])))
        ->toThrow(SubmissionConflictException::class);                // materially different → 409
});

it('creates a draft row with the 30-day expiry, source, completeness, and last_saved, and nothing else', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    Event::fake([SubmissionCreated::class]);

    $result = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['age' => '20', 'country' => 'US'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ));

    $row = $result->submission;
    expect($row->status)->toBe(SubmissionStatus::Draft)
        ->and($row->source)->toBe(SubmissionSource::Guest)
        ->and($row->submitted_at)->toBeNull()
        ->and($row->last_saved_at)->not->toBeNull()
        ->and($row->completeness_percent)->toBe(50)
        ->and($row->draft_expires_at)->not->toBeNull();

    // ~30-day expiry (DRAFT_TTL_DAYS), stamped at creation — tolerant of the few ms elapsed since now().
    expect($row->draft_expires_at->isAfter(now()->addDays(SubmissionDraftService::DRAFT_TTL_DAYS - 1)))->toBeTrue()
        ->and($row->draft_expires_at->isBefore(now()->addDays(SubmissionDraftService::DRAFT_TTL_DAYS + 1)))->toBeTrue();

    // No projection / audit / event for a draft.
    expect(SubmissionAnswerIndex::query()->count())->toBe(0)
        ->and(Audit::query()->where('auditable_type', 'submission')->count())->toBe(0);
    Event::assertNotDispatched(SubmissionCreated::class);
});

it('saves a draft that would fail Stage 3 (missing required) without error', function (): void {
    $version = resumableVersion($this->tenant, $this->user);

    // `applicant` is required, but a draft may be incomplete.
    $result = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['age' => '20'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ));

    expect($result->created)->toBeTrue()
        ->and($result->submission->status)->toBe(SubmissionStatus::Draft);
});

it('still runs Stage 1 for a draft (rejects an unknown key, persists nothing)', function (): void {
    $version = resumableVersion($this->tenant, $this->user);

    try {
        $this->drafts->saveDraft(new SubmissionPayload(
            version: $version,
            answers: ['not_a_field' => 'x'],
            source: SubmissionSource::Guest,
            clientSubmissionUuid: Str::uuid()->toString(),
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('structural');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('refuses to save a draft against a non-published version', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Draft only');
    addFormField($form->draftVersion, $this->user, 'name', FieldType::ShortText, 0);

    expect(fn () => $this->drafts->saveDraft(new SubmissionPayload(
        version: $form->draftVersion,
        answers: ['name' => 'Ada'],
        source: SubmissionSource::Guest,
    )))->toThrow(SubmissionException::class);
});

it('rejects a draft save whose client_submission_uuid already belongs to a finalized submission', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();

    // Finalize a real submission carrying the uuid.
    $submitted = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['applicant' => 'Ada', 'age' => '20'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    ));

    expect(fn () => $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['applicant' => 'Grace', 'age' => '21'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
    )))->toThrow(SubmissionConflictException::class);

    // The finalized row is untouched — its content was not overwritten by the rejected draft save.
    expect($submitted->submission->fresh()->status)->toBe(SubmissionStatus::Submitted)
        ->and(SubmissionAnswer::query()->findOrFail($submitted->submission->id)->answers['applicant'])->toBe('Ada');
});

it('fails promotion when Stage 3 fails and leaves the draft resumable', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    Event::fake([SubmissionCreated::class]);

    // A draft missing the required `applicant`.
    $draft = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['age' => '20'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ))->submission;

    try {
        $this->drafts->promote($draft);
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }

    // The draft is untouched — still a draft, nothing projected, no event.
    $draft->refresh();
    expect($draft->status)->toBe(SubmissionStatus::Draft)
        ->and($draft->submitted_at)->toBeNull()
        ->and(SubmissionAnswerIndex::query()->count())->toBe(0);
    Event::assertNotDispatched(SubmissionCreated::class);
});

it('is an idempotent no-op on a double promotion (no duplicate rows, index, or event)', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    Event::fake([SubmissionCreated::class]);

    $draft = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['applicant' => 'Ada', 'age' => '10'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ))->submission;

    $first = $this->drafts->promote($draft);
    $second = $this->drafts->promote($first->submission); // the already-Submitted row

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->submission->id)->toBe($first->submission->id)
        ->and(Submission::query()->count())->toBe(1)
        ->and(SubmissionAnswerIndex::query()->where('submission_id', $first->submission->id)->count())->toBe(2); // `age` + `double_age` (both queryable), projected once
    Event::assertDispatchedTimes(SubmissionCreated::class, 1);
});

it('preserves the draft source through promotion — including a server-staged (no-uuid) OCR draft', function (): void {
    $version = resumableVersion($this->tenant, $this->user);

    // The OCR server-side shape: source ocr_single, NO client_submission_uuid, no guest request.
    $ocrDraft = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['applicant' => 'Scanned', 'age' => '44'],
        source: SubmissionSource::OcrSingle,
        respondentUserId: $this->user->id,
    ));
    expect($ocrDraft->submission->status)->toBe(SubmissionStatus::Draft)
        ->and($ocrDraft->submission->client_submission_uuid)->toBeNull();

    $promotedOcr = $this->drafts->promote($ocrDraft->submission);
    expect($promotedOcr->submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($promotedOcr->submission->source)->toBe(SubmissionSource::OcrSingle);

    // A guest draft promotes as guest.
    $guestDraft = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['applicant' => 'Visitor', 'age' => '33'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ));
    expect($this->drafts->promote($guestDraft->submission)->submission->source)->toBe(SubmissionSource::Guest);
});

it('projects a PostGIS geometry only on promotion', function (): void {
    $version = draftFormPublish($this->tenant, $this->user, function (FormVersion $draft, User $u): void {
        addFormField($draft, $u, 'loc', FieldType::Geopoint, 0);
    });

    $draft = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['loc' => ['type' => 'Point', 'coordinates' => [121.05, 14.6], 'accuracy' => 4.5]],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ))->submission;

    $geoCount = static fn (): int => (int) DB::selectOne('SELECT count(*) AS c FROM submission_geo_index')->c;
    expect($geoCount())->toBe(0); // a draft never projects geo

    $this->drafts->promote($draft);
    $row = DB::selectOne('SELECT geometry_type, ST_SRID(geom) AS srid FROM submission_geo_index WHERE submission_id = ?', [$draft->id]);
    expect($row)->not->toBeNull()
        ->and($row->geometry_type)->toBe('Point')
        ->and((int) $row->srid)->toBe(4326);
});

it('re-points a media attachment from its form_field to the submission only on promotion', function (): void {
    Storage::fake('local');
    Queue::fake(); // leave the attachment `pending` (which the reference validator accepts)

    $version = draftFormPublish($this->tenant, $this->user, function (FormVersion $draft, User $u): void {
        addFormField($draft, $u, 'photos', FieldType::ImageCapture, 0);
    });

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $attachment = app(AttachmentStorageService::class)->store(
        UploadedFile::fake()->createWithContent('site.png', $png), $version, 'photos', $this->user->id
    );

    $draft = $this->drafts->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['photos' => [['id' => $attachment->id]]],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Str::uuid()->toString(),
    ))->submission;

    // A draft does not move ownership or populate attachment_refs.
    expect($attachment->refresh()->attachable_type)->toBe('form_field')
        ->and(SubmissionAnswer::query()->findOrFail($draft->id)->attachment_refs)->toBe([]);

    $this->drafts->promote($draft);
    expect($attachment->refresh()->attachable_type)->toBe('submission')
        ->and($attachment->attachable_id)->toBe($draft->id)
        ->and(SubmissionAnswer::query()->findOrFail($draft->id)->attachment_refs)->toBe([$attachment->id]);
});

/*
|--------------------------------------------------------------------------
| Increment P3a — the DRAFT channel's lost-update guard. H9b/H10 shipped cross-device resume (the emailed
| link carries the SAME client_submission_uuid to a second device), which gave one draft two writers for the
| first time. The write is a whole-document replace, so a save based on a state the other device has already
| moved past silently reverts its answers. The guard compares the BASE the saver read against what is stored
| — not the incoming answers against what is stored, which is the comparison saveDraft() correctly suspends.
|--------------------------------------------------------------------------
*/

/** A draft payload builder scoped to one uuid, so a test reads as a sequence of device saves. */
function p3aPayload(FormVersion $version, string $uuid): Closure
{
    return fn (array $answers, bool $check = false, ?string $base = null): SubmissionPayload => new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Guest,
        clientSubmissionUuid: $uuid,
        checkBaseline: $check,
        baseContentChecksum: $base,
    );
}

it('HEADLINE: refuses a second device saving from a base the first device has already replaced', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    // Both devices resumed this draft while it held {age: 30} — that checksum is their shared base.
    $seed = $this->drafts->saveDraft($payload(['age' => '30']));
    $id = $seed->submission->id;
    $sharedBase = $seed->contentChecksum;
    expect($sharedBase)->toBe(storedChecksum($id));

    // Device A saves first and moves the stored checksum on.
    $a = $this->drafts->saveDraft($payload(['age' => '30', 'country' => 'CA'], true, $sharedBase));
    expect($a->contentChecksum)->not->toBe($sharedBase);

    // Device B saves from the now-stale shared base. Before P3a this silently destroyed A's `country`.
    expect(fn () => $this->drafts->saveDraft($payload(['age' => '30', 'state' => 'Alberta'], true, $sharedBase)))
        ->toThrow(SubmissionConflictException::class);

    // A's work survives intact and B wrote nothing at all.
    $stored = SubmissionAnswer::query()->where('submission_id', $id)->value('answers');
    expect(data_get($stored, 'country'))->toBe('CA')
        ->and(data_get($stored, 'state'))->toBeNull()
        ->and(storedChecksum($id))->toBe($a->contentChecksum)
        ->and(Submission::query()->count())->toBe(1);
});

it('carries the distinct draft_conflict code, not the content or finalized one', function (): void {
    expect(SubmissionConflictException::draftConcurrentlyModified()->code())->toBe('draft_conflict')
        ->and(SubmissionConflictException::contentConflict()->code())->toBe('submission_conflict')
        ->and(SubmissionConflictException::draftAlreadyFinalized()->code())->toBe('draft_already_finalized')
        // The message must name the action; "conflict" alone leaves a respondent pressing Save into the
        // same refusal forever, because the copy they hold is the stale thing.
        ->and(SubmissionConflictException::draftConcurrentlyModified()->getMessage())->toContain('Reload');
});

it('never fires for a same-device autosave chain that carries its own base forward', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    // The ordinary case this guard must stay silent for: one device, many ticks, each based on the last.
    $r = $this->drafts->saveDraft($payload(['age' => '30']));
    foreach ([['age' => '31'], ['age' => '31', 'country' => 'CA'], ['applicant' => 'Ada', 'age' => '31']] as $next) {
        $r = $this->drafts->saveDraft($payload($next, true, $r->contentChecksum));
    }

    expect($r->created)->toBeFalse()
        ->and(Submission::query()->count())->toBe(1)
        ->and(data_get(SubmissionAnswer::query()->where('submission_id', $r->submission->id)->value('answers'), 'applicant'))
        ->toBe('Ada');
});

it('leaves a caller that makes no baseline claim behaving exactly as it did before P3a', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    // checkBaseline:false is the server-side caller with no page behind it. The suspension is untouched:
    // materially different content under one uuid still overwrites in place rather than conflicting.
    $first = $this->drafts->saveDraft($payload(['age' => '30']));
    $second = $this->drafts->saveDraft($payload(['age' => '31', 'country' => 'CA']));

    expect($second->created)->toBeFalse()
        ->and($second->submission->id)->toBe($first->submission->id)
        ->and(data_get(SubmissionAnswer::query()->where('submission_id', $first->submission->id)->value('answers'), 'country'))
        ->toBe('CA');
});

it('admits a legacy draft whose stored checksum is null against a null base', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    $seed = $this->drafts->saveDraft($payload(['age' => '30']));
    // A draft written before 2026_07_16_000001 added the column: the value is legitimately absent.
    SubmissionAnswer::query()->where('submission_id', $seed->submission->id)
        ->update(['answers_content_checksum' => null]);

    // null base === null stored, so the guard admits it rather than stranding a pre-existing draft.
    $again = $this->drafts->saveDraft($payload(['age' => '31'], true, null));
    expect($again->created)->toBeFalse()
        ->and(storedChecksum($again->submission->id))->not->toBeNull();
});

it('refuses a stale client that omits the baseline once a checksum is stored', function (): void {
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    $this->drafts->saveDraft($payload(['age' => '30']));

    // The fail-CLOSED direction, asserted on purpose: a client that stopped sending the token is refused
    // rather than silently degraded back to the lost update the guard exists to stop.
    expect(fn () => $this->drafts->saveDraft($payload(['age' => '31'], true, null)))
        ->toThrow(SubmissionConflictException::class);
});

/*
|--------------------------------------------------------------------------
| Increment M12 — the PRE-LOCK lost update, the second write door P3a did not reach.
|--------------------------------------------------------------------------
| promote() read the answer document OUTSIDE any transaction, ran Stage 3 and the DB media check over it for
| tens of milliseconds, took the row lock only afterwards, and then finalized with the PRE-LOCK values —
| SubmissionFinalizer::finalize() being a whole-document replace. An autosave committing inside that window
| was reverted, and the row was `submitted` afterwards, so no later save could restore it. P3a's guard sits in
| updateDraft() and cannot see this: it compares the base a SAVE carries, and a promote carries none.
|
| ⚠️ EVERY REFUSAL CASE HERE ASSERTS THE MESSAGE, NOT THE CLASS. Four causes share
| SubmissionConflictException and two of them share the `submission_conflict` code; M11's mutation pass
| proved a bare toThrow(SubmissionConflictException::class) passes for a completely different cause.
*/


it('HEADLINE: refuses a promote whose answer document moved between the pre-lock read and the lock', function (): void {
    // THE DEFECT, staged as the real interleaving rather than approximated. Before M12 this promote finalized
    // the document it read BEFORE device B's save, so `country` was gone from a row that was then `submitted`
    // — and `created: true` came back with no error to either device.
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    $seed = $this->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '30']));
    $id = $seed->submission->id;

    // Device B's autosave, committed inside promote()'s pre-lock window.
    $fired = interleaveOnPromoteRead(function () use ($payload, $seed): void {
        test()->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '30', 'country' => 'CA'], true, $seed->contentChecksum));
    });

    Event::fake([SubmissionCreated::class]);

    expect(fn () => $this->drafts->promote(Submission::findOrFail($id)))
        ->toThrow(SubmissionConflictException::class, 'This draft was updated on another device.');

    // ⚠️ NON-VACUITY: without the interleave there is no race to survive and every assertion below passes for
    // the wrong reason.
    expect($fired())->toBeTrue();

    $row = Submission::findOrFail($id);
    $stored = SubmissionAnswer::query()->where('submission_id', $id)->firstOrFail();

    expect($row->status)->toBe(SubmissionStatus::Draft)          // NOT finalized over the top of device B
        ->and($row->submitted_at)->toBeNull()
        ->and($row->draft_expires_at)->not->toBeNull()           // still resumable, still reapable
        ->and(data_get($stored->answers, 'country'))->toBe('CA') // device B's answer survives byte-for-byte
        ->and(SubmissionAnswerIndex::query()->where('submission_id', $id)->count())->toBe(0)
        ->and(Audit::query()->where('auditable_id', $id)->count())->toBe(0)
        ->and(Submission::query()->count())->toBe(1);

    Event::assertNotDispatched(SubmissionCreated::class);
});

it('does NOT refuse when the interleaved save left the document identical', function (): void {
    // THE CONTROL THAT KEEPS THE GUARD FROM BEING OVER-STRONG. A device re-saving the same answers (a network
    // retry, a debounced autosave firing on an unchanged form) rewrites `last_saved_at` and the checksum, but
    // AnswersContentChecksum::of() is deterministic so the VALUE does not move. Nothing was lost, so nothing
    // may be refused — a guard keyed on `updated_at` or on a row read would fail exactly here.
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    $seed = $this->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '30']));
    $id = $seed->submission->id;

    $fired = interleaveOnPromoteRead(function () use ($payload, $seed): void {
        test()->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '30'], true, $seed->contentChecksum));
    });

    $result = $this->drafts->promote(Submission::findOrFail($id));

    expect($fired())->toBeTrue()
        ->and($result->created)->toBeTrue()
        ->and($result->submission->status)->toBe(SubmissionStatus::Submitted);
});

it('stays an idempotent no-op when a concurrent PROMOTE wins under the lock, rather than a 409', function (): void {
    // ⚠️ THE ORDER GUARD. A concurrent promote moves the status AND the checksum — finalize() rewrites the
    // answer document — so if the new checksum comparison ran BEFORE the status re-assert, the documented
    // double-promote no-op would start returning conflicts to callers the contract promises an unchanged 200.
    // Swap the two checks in promote() and this case is the one that reddens.
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    $seed = $this->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '30']));
    $id = $seed->submission->id;

    // What the winning promote's own commit leaves behind: a finalized head row and a rewritten document.
    $fired = interleaveOnPromoteRead(function () use ($id): void {
        Submission::query()->whereKey($id)->update([
            'status' => SubmissionStatus::Submitted,
            'submitted_at' => now(),
            'draft_expires_at' => null,
        ]);
        SubmissionAnswer::query()->where('submission_id', $id)
            ->update(['answers_content_checksum' => str_repeat('a', 64)]);
    });

    $result = $this->drafts->promote(Submission::findOrFail($id));

    expect($fired())->toBeTrue()
        ->and($result->created)->toBeFalse()
        ->and($result->submission->status)->toBe(SubmissionStatus::Submitted);
});

it('promotes a legacy draft whose stored checksum is null, and refuses one that gains a checksum in the window', function (): void {
    // BOTH DIRECTIONS OF THE NULL, because the comparison is `!==` and a legacy draft predating the checksum
    // column must not be stranded. null vs null admits; null vs a real value refuses — the fail-CLOSED
    // direction, which is the one that matters: something wrote in the window.
    $version = resumableVersion($this->tenant, $this->user);

    $legacy = $this->drafts->saveDraft(p3aPayload($version, Str::uuid()->toString())(['applicant' => 'Ada', 'age' => '30']));
    SubmissionAnswer::query()->where('submission_id', $legacy->submission->id)
        ->update(['answers_content_checksum' => null]);

    expect($this->drafts->promote(Submission::findOrFail($legacy->submission->id))->submission->status)
        ->toBe(SubmissionStatus::Submitted);

    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);
    $seed = $this->drafts->saveDraft($payload(['applicant' => 'Grace', 'age' => '40']));
    $id = $seed->submission->id;
    SubmissionAnswer::query()->where('submission_id', $id)->update(['answers_content_checksum' => null]);

    $fired = interleaveOnPromoteRead(function () use ($id): void {
        SubmissionAnswer::query()->where('submission_id', $id)
            ->update(['answers_content_checksum' => str_repeat('b', 64)]);
    });

    expect(fn () => $this->drafts->promote(Submission::findOrFail($id)))
        ->toThrow(SubmissionConflictException::class, 'This draft was updated on another device.');
    expect($fired())->toBeTrue()
        ->and(Submission::findOrFail($id)->status)->toBe(SubmissionStatus::Draft);
});

it('raises the SAME draft_conflict cause the save door raises, not a fourth name for it', function (): void {
    // The wire contract M12 deliberately did not move: one code and one sentence for both write doors, so no
    // client learns a second name for "another device wrote to this draft; reload it". A new factory here
    // would be the change that made openapi.json and every guest client move.
    $version = resumableVersion($this->tenant, $this->user);
    $uuid = Str::uuid()->toString();
    $payload = p3aPayload($version, $uuid);

    $seed = $this->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '30']));
    $id = $seed->submission->id;

    $fired = interleaveOnPromoteRead(function () use ($payload, $seed): void {
        test()->drafts->saveDraft($payload(['applicant' => 'Ada', 'age' => '31'], true, $seed->contentChecksum));
    });

    try {
        $this->drafts->promote(Submission::findOrFail($id));
        $this->fail('promote() finalized over a document that moved in its pre-lock window');
    } catch (SubmissionConflictException $e) {
        expect($e->code())->toBe('draft_conflict')
            ->and($e->getMessage())->toBe(SubmissionConflictException::draftConcurrentlyModified()->getMessage())
            ->and($e->getMessage())->toContain('Reload');
    }

    expect($fired())->toBeTrue();
});
