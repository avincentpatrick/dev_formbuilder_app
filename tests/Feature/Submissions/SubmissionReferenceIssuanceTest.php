<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Submissions\SubmissionReference;
use App\Support\Submissions\SubmissionReferenceIssuer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ScriptedSubmissionReferenceIssuer;

/**
 * Increment J2e — `submissions.reference` is issued for every row, by every writer, and the DATABASE is what
 * makes it unique.
 *
 * ⚠️ SEVERAL CASES HERE END ON A `toThrow(QueryException::class)` AND MUST BE THE FINAL DB INTERACTION IN
 * THEIR `it()`. A 23505/23502 aborts the surrounding PostgreSQL transaction — which under `RefreshDatabase`
 * is the test's own — so anything after it fails with 25P02 for a reason that has nothing to do with the
 * assertion. The `SubmissionRlsTest` idiom, and the same fact that forced the retry loops in
 * {@see SubmissionPipeline} and {@see SubmissionDraftService} out to the transaction boundary.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
});

it('issues a valid reference through the factory', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);

    expect(SubmissionReference::isValid($submission->reference))->toBeTrue();
});

it('issues a reference through the submit pipeline and the draft service alike', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    $submitted = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Ana'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    $drafted = app(SubmissionDraftService::class)->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Bea'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
        clientSubmissionUuid: (string) Str::uuid(),
    ));

    expect(SubmissionReference::isValid($submitted->submission->reference))->toBeTrue()
        ->and(SubmissionReference::isValid($drafted->submission->reference))->toBeTrue()
        // Two rows in ONE tenant never share a code. The mutation this reddens is a constant `issue()`.
        ->and($submitted->submission->reference)->not->toBe($drafted->submission->reference);
});

it('ignores a caller-supplied reference, because it is not fillable', function (): void {
    // The `ScopeNodePathTest` shape: an UNSAVED model, so this proves mass-assignment is refused rather than
    // that some later write overwrote it. `reference` is server-issued identity — a fillable one would let
    // any update() reachable from a request payload rewrite the handle a respondent already wrote down.
    $submission = new Submission(['reference' => 'FORGED12']);

    expect($submission->reference)->toBeNull();
});

it('keeps a deliberately force-filled reference', function (): void {
    // The `=== null` guard in Submission::booted(). This is how a test pins a known code — and, more
    // importantly, why the retry loops work: a re-run builds a NEW model whose attribute is null again.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    $submission = Submission::factory()->forVersion($version)->create();
    $submission->forceFill(['reference' => 'KNOWN123'])->save();

    expect($submission->fresh()?->reference)->toBe('KNOWN123');
});

it('lets two different tenants hold the SAME reference', function (): void {
    // The corollary of a COMPOSITE unique index, and the case that reddens if anyone "tidies"
    // submissions_tenant_id_reference_unique down to unique(['reference']). Not a nicety: a per-tenant
    // namespace is what keeps eight characters enough.
    $formA = publishedInboxForm($this->tenant, $this->owner);
    $a = seedInboxSubmission($formA, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $a->forceFill(['reference' => 'SHARED12'])->save();

    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo', 'default_locale' => 'en']);
    $otherOwner = User::factory()->create();
    enterTenant($other->id, $otherOwner->id);
    $formB = publishedInboxForm($other, $otherOwner);
    $b = seedInboxSubmission($formB, $otherOwner, SubmissionStatus::Submitted, ['full_name' => 'Bea']);
    $b->forceFill(['reference' => 'SHARED12'])->save();

    expect($b->fresh()?->reference)->toBe('SHARED12');
});

it('recovers from a reference collision instead of failing the submission', function (): void {
    // THE case the injectable issuer exists for. At 32^8 codes no test can make two real draws agree, so
    // without this seam the retry in SubmissionPipeline would ship unexercised.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    $taken = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $taken->forceFill(['reference' => 'DUPE1234'])->save();

    $issuer = new ScriptedSubmissionReferenceIssuer(['DUPE1234', 'FRESH123']);
    app()->instance(SubmissionReferenceIssuer::class, $issuer);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Bea'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    expect($result->created)->toBeTrue()
        ->and($result->submission->reference)->toBe('FRESH123')
        // Exactly two draws: the collision, then the recovery. Without this the MAX_REFERENCE_ATTEMPTS
        // budget could be raised to anything and nothing would notice.
        ->and($issuer->calls())->toBe(2);
});

it('recovers from a reference collision on the draft path too', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    $taken = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $taken->forceFill(['reference' => 'DUPE5678'])->save();

    $issuer = new ScriptedSubmissionReferenceIssuer(['DUPE5678', 'FRESH567']);
    app()->instance(SubmissionReferenceIssuer::class, $issuer);

    $result = app(SubmissionDraftService::class)->saveDraft(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Bea'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
        clientSubmissionUuid: (string) Str::uuid(),
    ));

    expect($result->submission->reference)->toBe('FRESH567')
        ->and($issuer->calls())->toBe(2);
});

it('gives up loudly rather than looping forever when every code collides', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    $taken = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $taken->forceFill(['reference' => 'ALWAYS12'])->save();

    // One code, forever. The scripted issuer repeats its last value once exhausted.
    app()->instance(SubmissionReferenceIssuer::class, new ScriptedSubmissionReferenceIssuer(['ALWAYS12']));

    // Throws — and aborts this test's transaction, so it is the final DB interaction here.
    expect(fn () => app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Bea'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    )))->toThrow(QueryException::class);
});

it('lets the database refuse a duplicate reference within one tenant', function (): void {
    // The index is the authority; every PHP-side guard above is convenience. Throws — final DB interaction.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $a = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $a->forceFill(['reference' => 'CLASH123'])->save();

    $b = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Bea']);

    expect(fn () => $b->forceFill(['reference' => 'CLASH123'])->save())->toThrow(QueryException::class);
});

it('keeps a soft-deleted submission’s reference reserved', function (): void {
    // ⚠️ THE ABSENT `WHERE deleted_at IS NULL` ARM, asserted rather than described. Freeing a trashed row's
    // code would let a respondent's written-down reference resolve LATER to a DIFFERENT submission — strictly
    // worse than "not found". Throws — final DB interaction.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $a = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ana']);
    $a->forceFill(['reference' => 'GHOST123'])->save();
    $a->delete();

    expect($a->fresh()?->deleted_at)->not->toBeNull();

    $b = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Bea']);

    expect(fn () => $b->forceFill(['reference' => 'GHOST123'])->save())->toThrow(QueryException::class);
});

it('refuses a submissions row carrying no reference at all', function (): void {
    // The NOT NULL constraint. A fail-closed guard on a path no production writer takes — the model hook
    // fills the column for all seven Eloquent writers — so its only real callers are the two raw-insert RLS
    // helpers, which is exactly why it is pinned here. Throws — final DB interaction.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    expect(fn () => DB::table('submissions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'form_id' => $form->id,
        'form_version_id' => $version->id,
        'status' => 'submitted',
        'source' => 'manual',
    ]))->toThrow(QueryException::class);
});
