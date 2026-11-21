<?php

declare(strict_types=1);

use App\Enums\AfterSubmit;
use App\Enums\AuditEvent;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionUpdated;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionEditException;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\RespondentAmendService;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M248 (`D232`, NHETE recommendation 6a) — "Change my answers": a respondent changes the response they SENT.
|--------------------------------------------------------------------------
| A form set to `amend` returns an amend link with every guest submit; GET api/v1/public/submissions/amend/{token}
| reads the response and PUT changes it, through the staff correction's own write. Who may, decided live on every
| request: a guest response on its own form; "Change my answers" still on; the form open and taking guests; the
| response still `submitted` (`D232` answer 3). Own helpers (`amend*`) so the file runs standalone.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A guest form (required full_name + optional age), published, open to guests, set to "Change my answers". */
function amendFixture(AfterSubmit $afterSubmit = AfterSubmit::Amend, string $slug = 'acme'): object
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => $slug]);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'age', FieldType::Integer, 1);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => 'intake', 'allow_guest_submissions' => true]);
    $form->forceFill(['after_submit' => $afterSubmit])->save();

    return (object) ['tenant' => $tenant, 'owner' => $owner, 'form' => $form->refresh()];
}

function amendShareToken(Form $form): string
{
    return app(GuestShareTokenService::class)->mint(
        $form->tenant_id, $form->id, (string) $form->current_published_version_id,
    )->token;
}

/** Submit a guest response through the real route; returns the JSON body's `data`. */
function amendSubmit(object $test, Form $form, array $answers): array
{
    $response = $test->postJson('http://acme.meridian.test/api/v1/public/f/'.amendShareToken($form).'/submissions', [
        'answers' => $answers,
        'client_submission_uuid' => Uuid::uuid7()->toString(),
    ])->assertCreated();

    return $response->json('data');
}

function amendUrl(string $token): string
{
    return "http://acme.meridian.test/api/v1/public/submissions/amend/{$token}";
}

// ── The link a respondent keeps ──────────────────────────────────────────────────────────────────────

it('gives a respondent an amend link with every submit when the form offers "Change my answers"', function (): void {
    $f = amendFixture();
    $f->form->forceFill(['closes_at' => now()->addDays(10)])->save();

    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana', 'age' => '30']);

    expect($data['amend'])->toBeArray()
        ->and($data['amend']['url'])->toContain('/f/amend/')
        ->and($data['amend']['until'])->not->toBeNull();

    $token = app(GuestShareTokenService::class)->verifyAmend($data['amend']['token']);
    expect($token->submissionId)->toBe($data['id'])
        ->and($token->formId)->toBe($f->form->id);
});

it('gives no amend link when the form offers another response, or one response', function (AfterSubmit $mode): void {
    $f = amendFixture($mode);

    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    expect($data)->toHaveKey('amend')
        ->and($data['amend'])->toBeNull();
})->with([AfterSubmit::Another, AfterSubmit::Once]);

// ── Read and change ──────────────────────────────────────────────────────────────────────────────────

it('reads the response it names, with its baseline, its clock and a share token for its own version', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana', 'age' => '30']);

    $read = $this->getJson(amendUrl($data['amend']['token']))->assertOk()->json('data');

    enterTenant($f->tenant->id);
    $stored = SubmissionAnswer::query()->where('submission_id', $data['id'])->firstOrFail();
    $submission = Submission::query()->findOrFail($data['id']);

    expect($read['id'])->toBe($data['id'])
        ->and($read['answers'])->toMatchArray(['full_name' => 'Ana', 'age' => 30])
        ->and($read['content_checksum'])->toBe($stored->answers_content_checksum)
        ->and($read['submitted_at'])->toBe($submission->submitted_at?->toIso8601String())
        ->and($read['form_version_id'])->toBe($submission->form_version_id)
        ->and($read['amended_at'])->toBeNull();

    $share = app(GuestShareTokenService::class)->verify($read['share_token']);
    expect($share->formVersionId)->toBe($submission->form_version_id);
});

it('changes the answers, stamps when, and records one change by the respondent', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana', 'age' => '30']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    Event::fake([SubmissionUpdated::class]);

    $changed = $this->putJson(amendUrl($data['amend']['token']), [
        'answers' => ['full_name' => 'Ana Cruz', 'age' => '31'],
        'base_content_checksum' => $read['content_checksum'],
    ])->assertOk()->json('data');

    enterTenant($f->tenant->id);
    $stored = SubmissionAnswer::query()->where('submission_id', $data['id'])->firstOrFail();
    $submission = Submission::query()->findOrFail($data['id']);

    expect($stored->answers)->toMatchArray(['full_name' => 'Ana Cruz', 'age' => 31])
        ->and($submission->status)->toBe(SubmissionStatus::Submitted)
        ->and($submission->respondent_amended_at)->not->toBeNull()
        ->and($changed['amended_at'])->toBe($submission->respondent_amended_at?->toIso8601String())
        ->and($changed['content_checksum'])->toBe($stored->answers_content_checksum)
        ->and($changed['content_checksum'])->not->toBe($read['content_checksum']);

    $audits = Audit::query()
        ->where('auditable_type', 'submission')
        ->where('auditable_id', $data['id'])
        ->where('event', AuditEvent::Updated)
        ->get();

    expect($audits)->toHaveCount(1)
        ->and($audits[0]->user_id)->toBeNull()
        ->and($audits[0]->new_values['by'])->toBe('respondent')
        ->and($audits[0]->new_values['answers.full_name'] ?? null)->toBe('Ana Cruz')
        ->and($audits[0]->isRespondentAct())->toBeTrue();

    Event::assertDispatched(SubmissionUpdated::class, fn (SubmissionUpdated $e): bool => $e->editedByRespondent
        && $e->editedByUserId === null
        && $e->envelope()['data']['edited_by_respondent'] === true);
});

it('names a respondent\'s change "Respondent" in the audit log, never "System"', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');
    $this->putJson(amendUrl($data['amend']['token']), [
        'answers' => ['full_name' => 'Ana Cruz'],
        'base_content_checksum' => $read['content_checksum'],
    ])->assertOk();

    enterTenant($f->tenant->id, $f->owner->id);
    $rows = collect(app(AuditLogPresenter::class)->index($f->owner, [])['data'])
        ->where('target.type', 'submission');

    // The guest submit itself stays "System" (no user, no marker); the change is the respondent's.
    expect($rows->pluck('actor')->all())->toContain('Respondent')
        ->and($rows->pluck('actor')->all())->toContain('System');
});

it('never credits the change to someone who happens to be signed in to the same browser', function (): void {
    // Measured by NHETE's route test: the logger's `Auth::id()` fallback put the signed-in admin on a respondent's change.
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    $this->actingAs($f->owner)->putJson(amendUrl($data['amend']['token']), [
        'answers' => ['full_name' => 'Ana Cruz'],
        'base_content_checksum' => $read['content_checksum'],
    ])->assertOk();

    enterTenant($f->tenant->id);
    $audit = Audit::query()->where('auditable_id', $data['id'])->where('event', AuditEvent::Updated)->firstOrFail();
    expect($audit->user_id)->toBeNull()
        ->and($audit->isRespondentAct())->toBeTrue();
});

it('takes no capacity: a full form still lets its respondents change their answers', function (): void {
    $f = amendFixture();
    $f->form->forceFill(['max_responses' => 1])->save();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    $read = $this->getJson(amendUrl($data['amend']['token']))->assertOk()->json('data');
    $this->putJson(amendUrl($data['amend']['token']), [
        'answers' => ['full_name' => 'Ana Cruz'],
        'base_content_checksum' => $read['content_checksum'],
    ])->assertOk();

    enterTenant($f->tenant->id);
    expect(Submission::query()->count())->toBe(1);
});

it('keeps the response on its own version after the form is republished', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    enterTenant($f->tenant->id, $f->owner->id);
    $firstVersion = (string) $f->form->current_published_version_id;
    addFormField($f->form->refresh()->draftVersion, $f->owner, 'town', FieldType::ShortText, 2, ['is_required' => RequiredMode::Required]);
    app(PublishService::class)->publish($f->form->refresh(), $f->owner);
    expect($f->form->refresh()->current_published_version_id)->not->toBe($firstVersion);

    $read = $this->getJson(amendUrl($data['amend']['token']))->assertOk()->json('data');
    expect($read['form_version_id'])->toBe($firstVersion);

    // The new version's required `town` does not bind the old response.
    $this->putJson(amendUrl($data['amend']['token']), [
        'answers' => ['full_name' => 'Ana Cruz'],
        'base_content_checksum' => $read['content_checksum'],
    ])->assertOk();
});

// ── Refusals ─────────────────────────────────────────────────────────────────────────────────────────

it('refuses once the form no longer offers "Change my answers" (403 amend_not_allowed)', function (AfterSubmit $mode): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    enterTenant($f->tenant->id);
    $f->form->forceFill(['after_submit' => $mode])->save();

    $this->getJson(amendUrl($data['amend']['token']))->assertForbidden()->assertJsonPath('error.code', 'amend_not_allowed');
    $this->putJson(amendUrl($data['amend']['token']), ['answers' => ['full_name' => 'X'], 'base_content_checksum' => $read['content_checksum']])
        ->assertForbidden()->assertJsonPath('error.code', 'amend_not_allowed');
})->with([AfterSubmit::Another, AfterSubmit::Once]);

it('refuses once the form has closed (403 form_closed), and once it stops taking guests', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    enterTenant($f->tenant->id);
    $f->form->forceFill(['closes_at' => now()->subMinute()])->save();

    $this->getJson(amendUrl($data['amend']['token']))->assertForbidden()->assertJsonPath('error.code', 'form_closed');
    $this->putJson(amendUrl($data['amend']['token']), ['answers' => ['full_name' => 'X'], 'base_content_checksum' => $read['content_checksum']])
        ->assertForbidden()->assertJsonPath('error.code', 'form_closed');

    enterTenant($f->tenant->id);
    $f->form->forceFill(['closes_at' => null])->save();
    $f->form->update(['allow_guest_submissions' => false]);

    $this->getJson(amendUrl($data['amend']['token']))->assertForbidden()->assertJsonPath('error.code', 'amend_not_allowed');
});

it('refuses once staff have acted on the response (409 response_under_review)', function (SubmissionStatus $status): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    enterTenant($f->tenant->id);
    Submission::query()->whereKey($data['id'])->update(['status' => $status]);

    $this->getJson(amendUrl($data['amend']['token']))->assertStatus(409)->assertJsonPath('error.code', 'response_under_review');
    $this->putJson(amendUrl($data['amend']['token']), ['answers' => ['full_name' => 'X'], 'base_content_checksum' => $read['content_checksum']])
        ->assertStatus(409)->assertJsonPath('error.code', 'response_under_review');

    enterTenant($f->tenant->id);
    expect(SubmissionAnswer::query()->where('submission_id', $data['id'])->value('answers'))->toMatchArray(['full_name' => 'Ana']);
})->with([SubmissionStatus::UnderReview, SubmissionStatus::Approved, SubmissionStatus::Returned, SubmissionStatus::Archived]);

it('refuses a change made from a stale page (409 response_changed)', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    $this->putJson(amendUrl($data['amend']['token']), ['answers' => ['full_name' => 'Ana Cruz'], 'base_content_checksum' => $read['content_checksum']])
        ->assertOk();
    // The second device still holds the first baseline.
    $this->putJson(amendUrl($data['amend']['token']), ['answers' => ['full_name' => 'Anna'], 'base_content_checksum' => $read['content_checksum']])
        ->assertStatus(409)->assertJsonPath('error.code', 'response_changed');

    enterTenant($f->tenant->id);
    expect(SubmissionAnswer::query()->where('submission_id', $data['id'])->value('answers'))->toMatchArray(['full_name' => 'Ana Cruz']);
});

it('refuses answers that break the form\'s own rules (422)', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $read = $this->getJson(amendUrl($data['amend']['token']))->json('data');

    $this->putJson(amendUrl($data['amend']['token']), ['answers' => ['full_name' => ''], 'base_content_checksum' => $read['content_checksum']])
        ->assertStatus(422)->assertJsonPath('error.code', 'submission_invalid');
});

it('takes no other kind of token, and gives none of its own to another route', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);
    $tokens = app(GuestShareTokenService::class);

    $resume = $tokens->mintResume($f->tenant->id, $f->form->id, (string) $f->form->current_published_version_id, $data['id'])->token;
    $this->getJson(amendUrl($resume))->assertUnauthorized();
    $this->getJson(amendUrl(amendShareToken($f->form)))->assertUnauthorized();

    $this->getJson("http://acme.meridian.test/api/v1/public/drafts/{$data['amend']['token']}")->assertUnauthorized();
    expect(fn () => $tokens->verify($data['amend']['token']))->toThrow(Exception::class);
});

it('reaches only a guest response on the token\'s own form (404)', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    // A token naming this response under ANOTHER form of the same tenant.
    enterTenant($f->tenant->id, $f->owner->id);
    $other = app(FormService::class)->create($f->tenant, $f->owner, 'Other');
    $forged = app(GuestShareTokenService::class)->mintAmend($f->tenant->id, $other->id, (string) $f->form->current_published_version_id, $data['id'])->token;
    $this->getJson(amendUrl($forged))->assertNotFound()->assertJsonPath('error.code', 'response_not_found');

    // A member's response on this very form is not a guest's to change.
    enterTenant($f->tenant->id);
    Submission::query()->whereKey($data['id'])->update(['respondent_user_id' => $f->owner->id]);
    $own = app(GuestShareTokenService::class)->mintAmend($f->tenant->id, $f->form->id, (string) $f->form->current_published_version_id, $data['id'])->token;
    $this->getJson(amendUrl($own))->assertNotFound();
});

// ── Under the lock: the race a pre-check cannot see ──────────────────────────────────────────────────

it('re-checks the status under the lock, so a review that lands mid-request wins', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    enterTenant($f->tenant->id);
    $stale = Submission::query()->findOrFail($data['id']);            // read while still `submitted`
    $version = FormVersion::query()->findOrFail($stale->form_version_id);
    $baseline = SubmissionAnswer::query()->where('submission_id', $stale->id)->value('answers_content_checksum');
    Submission::query()->whereKey($stale->id)->update(['status' => SubmissionStatus::UnderReview]); // a reviewer picks it up

    expect(fn () => app(RespondentAmendService::class)->amend($f->form, $stale, $version, ['full_name' => 'X'], $baseline))
        ->toThrow(SubmissionEditException::class);
    expect(SubmissionAnswer::query()->where('submission_id', $stale->id)->value('answers'))->toMatchArray(['full_name' => 'Ana']);
});

it('re-reads the form under the lock, so switching "Change my answers" off mid-request wins', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    enterTenant($f->tenant->id);
    $staleForm = Form::query()->findOrFail($f->form->id);              // read while still `amend`
    $submission = Submission::query()->findOrFail($data['id']);
    $version = FormVersion::query()->findOrFail($submission->form_version_id);
    $baseline = SubmissionAnswer::query()->where('submission_id', $submission->id)->value('answers_content_checksum');
    Form::query()->whereKey($staleForm->id)->update(['after_submit' => AfterSubmit::Another->value]);

    expect(fn () => app(RespondentAmendService::class)->amend($staleForm, $submission, $version, ['full_name' => 'X'], $baseline))
        ->toThrow(FormNotAcceptingSubmissionException::class);
});

// ── The page ─────────────────────────────────────────────────────────────────────────────────────────

it('opens the guest page from an amend link, and 404s a bad one', function (): void {
    $f = amendFixture();
    $data = amendSubmit($this, $f->form, ['full_name' => 'Ana']);

    $this->get("http://acme.meridian.test/f/amend/{$data['amend']['token']}")
        ->assertOk()
        ->assertSee('data-share-token', false);

    $this->get('http://acme.meridian.test/f/amend/v1.forged.signature')->assertNotFound();
});

it('leaves /f/amend/manifest.webmanifest to the manifest route, for a form named "amend" before the reservation', function (): void {
    $f = amendFixture();
    enterTenant($f->tenant->id);
    $f->form->update(['public_slug' => 'amend']);

    $this->get('http://acme.meridian.test/f/amend/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');
});
