<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9c — the HTTP surface of post-submission answer editing.
|--------------------------------------------------------------------------
| `SubmissionAnswerEditTest` owns the service's behaviour. This file owns the things only a real request can
| show: that the routes are gated on `can:update` (NOT `can:review`), that the page renders in EDIT mode with
| the right wire block, that a refused state redirects rather than 500s, and that the presenter's mode props
| are mutually exclusive.
|
| Every GET here carries `withoutVite()` and `->component(..., false)`: the Pest CI job builds no assets, so
| a blade rendering `@vite` throws and the 200 arrives as a 500, and Inertia's page-file existence check
| cannot resolve a component on a case-sensitive filesystem. Both were live CI failures in I9b.
*/

beforeEach(function (): void {
    TenantContext::flush();
    // The reset both sibling route suites do. Without it every authorization assertion in this file is
    // order-dependent on a permission map cached by whatever ran before it.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = publishedInboxForm($this->tenant, $this->owner);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function editReenter(): void
{
    enterTenant(test()->tenant->id, test()->owner->id);
}

function seedEditable(SubmissionStatus $status = SubmissionStatus::Submitted, array $answers = ['full_name' => 'Ada']): Submission
{
    return seedInboxSubmission(test()->form, test()->owner, $status, $answers);
}

/** The optimistic-concurrency token the real page would carry — see EditSubmissionAnswersRequest. */
function baselineOf(Submission $submission): string
{
    return (string) SubmissionAnswer::where('submission_id', $submission->id)->value('answers_content_checksum');
}

/**
 * A submission written by the REAL pipeline, so its answer row carries a real `answers_content_checksum`
 * exactly as production writes one.
 *
 * ⚠️ `seedEditable()` DELIBERATELY DOES NOT DO THIS AND MUST NOT START. It goes through
 * `SubmissionAnswerFactory`, which stamps no checksum — the LEGACY row, which
 * {@see EditSubmissionAnswersRequest} keeps editable on purpose and which every case above is the right
 * fixture for. Converting it would delete the nullable path's only coverage and change the fixture shape
 * under every caller of `seedInboxSubmission()` in the suite. This is an ADDITION, not a replacement.
 *
 * ⚠️ AND IT IS DEFINED HERE RATHER THAN REUSING `SubmissionAnswerEditTest`'s `submitForEdit()`. Pest's
 * helper functions are GLOBAL, so calling that one from this file passes on a whole-suite run and dies with
 * `Call to undefined function` on a per-file run — the same global-namespace trap `editableIndexedForm`'s
 * docblock in that file already records, arriving from the other direction.
 *
 * @param  array<string, mixed>  $answers
 */
function seedEditableWithRealChecksum(array $answers = ['full_name' => 'Ada', 'color' => 'r']): Submission
{
    $version = FormVersion::findOrFail(test()->form->current_published_version_id);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Manual,
        respondentUserId: null,
    ));

    return $result->submission->refresh();
}

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('renders the encode page in EDIT mode, with the editing block populated and draft null', function (): void {
    $submission = seedEditable();

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('editing.id', $submission->id)
            ->where('editing.status', 'submitted')
            ->where('editing.answers.full_name', 'Ada')
            ->where('editing.demotes_on_save', false)
            // ⚠️ MUTUALLY EXCLUSIVE. `draft` non-null in edit mode would put the page in resume mode, where
            // it autosaves — down a channel with no `update` policy check and no audit row.
            ->where('draft', null)
            ->where('draft_url', null)
            ->where('update_url', "http://acme.meridian.test/submissions/{$submission->id}/answers"));
});

it('flags demotes_on_save only for an approved submission', function (): void {
    $approved = seedEditable(SubmissionStatus::Approved);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$approved->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            // The consequence is announced BEFORE typing, not discovered at Save.
            ->where('editing.demotes_on_save', true));
});

it('keeps the create page in create mode — the control for every assertion above', function (): void {
    // Without this, the edit assertions would pass against a presenter that always emitted an `editing`
    // object, and `editing === null` is the single test the client uses for "am I creating".
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$this->form->id}/submissions/create")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('editing', null)
            ->where('update_url', null)
            ->has('draft_url'));
});

it('renders against the submission\'s OWN version, not the currently published one', function (): void {
    // ⚠️ THE I9b VERSION-PIN BUG IN ITS OTHER CLOTHES. Republish, then edit an older submission: the page
    // must render v1's schema, because the stored answers are keyed to it. Resolving the current version
    // would surface a renamed field as an unknown key and drop a deleted one from a record it is part of.
    $submission = seedEditable();
    $pinned = FormVersion::findOrFail($submission->form_version_id);

    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    editReenter();

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('version.id', $pinned->id));
});

it('still opens for edit when the pinned version has been superseded', function (): void {
    // The DIFFERENCE from I9b's resume page, which refuses a superseded pin. An edit creates no submission —
    // it corrects an existing record against the schema it was captured under. Requiring `published` would
    // make almost every real submission uneditable after one republish, which is the population most likely
    // to need a correction.
    $submission = seedEditable();
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    editReenter();

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| State refusals
|--------------------------------------------------------------------------
*/

it('redirects a non-editable state back to the detail page with the reason', function (SubmissionStatus $status): void {
    $submission = seedEditable($status);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        // A redirect + toast, never a bare 403: the caller may well hold `submissions.edit.any`; it is the
        // ROW that is not editable, and a 403 would be a lie about which.
        ->assertRedirect("/submissions/{$submission->id}")
        ->assertSessionHas('toast');
})->with([
    'archived' => SubmissionStatus::Archived,
    'screened_out' => SubmissionStatus::ScreenedOut,
]);

it('sends a draft to the resume page instead of refusing it', function (): void {
    $draft = seedEditable(SubmissionStatus::Draft);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$draft->id}/edit")
        ->assertRedirect("/submissions/{$draft->id}/resume");
});

it('refuses the PATCH on a non-editable state even when the page was never opened', function (): void {
    // The route gate is authorization only; state legality is the service's, re-asserted under a lock. A
    // caller can POST straight here, and a reviewer can archive the row between page load and Save.
    $submission = seedEditable(SubmissionStatus::Archived);

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'X'], 'baseline' => baselineOf($submission),
        ])
        ->assertSessionHas('toast');

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])->toBe('Ada');
});

/*
|--------------------------------------------------------------------------
| The write
|--------------------------------------------------------------------------
*/

it('applies the correction and returns to the detail page', function (): void {
    $submission = seedEditable();

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace'], 'baseline' => baselineOf($submission),
        ])
        ->assertRedirect("/submissions/{$submission->id}")
        ->assertSessionHas('toast');

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])
        ->toBe('Ada Lovelace');
});

it('returns per-field errors rather than one flattened toast when Stage 3 rejects', function (): void {
    // `full_name` is Required on the inbox fixture form. The controller deliberately does NOT catch
    // SubmissionValidationException — the central render closure turns it into back()->withErrors(), which
    // is what puts a message against the field that failed.
    $submission = seedEditable();

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => ''], 'baseline' => baselineOf($submission),
        ])
        ->assertSessionHasErrors();

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])->toBe('Ada');
});

it('rejects a body with no answers key at all', function (): void {
    $submission = seedEditable();

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", ['baseline' => baselineOf($submission)])
        ->assertSessionHasErrors('answers');
});

it('rejects a body with no concurrency baseline', function (): void {
    // A request without one is a page that predates the field, or a hand-rolled body. Both are exactly the
    // callers whose blind whole-document write would revert somebody else's correction, so this fails loudly
    // rather than being treated as "no opinion".
    $submission = seedEditable();

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", ['answers' => ['full_name' => 'X']])
        ->assertSessionHasErrors('baseline');

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])->toBe('Ada');
});

it('refuses a PATCH whose baseline is stale, so a second editor cannot silently revert the first', function (): void {
    $submission = seedEditable(SubmissionStatus::Submitted, ['full_name' => 'Ada', 'color' => 'r']);
    $stale = baselineOf($submission);

    // Editor A saves first.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace', 'color' => 'r'], 'baseline' => $stale,
        ])->assertRedirect("/submissions/{$submission->id}");

    editReenter();

    // Editor B is still on a page rendered before A saved.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada', 'color' => 'b'], 'baseline' => $stale,
        ])->assertSessionHas('toast');

    editReenter();
    // A's correction survives — the whole point.
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])
        ->toBe('Ada Lovelace');
});

/*
| ⛔ THE TWO CASES BELOW CARRY A REAL TOKEN OVER THE WIRE, AND EVERY PATCH ABOVE CARRIES AN EMPTY STRING.
| `baselineOf()` reads a column the factory never stamps and casts it, so it returns `''`;
| `ConvertEmptyStringsToNull` then turns that back into null before validation. So the requests above prove
| the LEGACY row is still editable — which matters, and is why they stay — but they cannot show that this
| route transports a real checksum, because none of them has ever sent one. Both mutations named in
| `SubmissionAnswerEditTest`'s section 9b survived the whole 60-test concurrency suite, so this gap is
| measured rather than hypothetical, and it is closed at the HTTP layer too: a token that the service
| compares correctly is still worthless if the request never delivers it intact.
*/

it('accepts a PATCH carrying the document\'s REAL checksum, and hands back a moved one', function (): void {
    $submission = seedEditableWithRealChecksum();
    $baseline = baselineOf($submission);

    // ⚠️ NON-VACUITY. Without this the case degrades into a fourth empty-string PATCH the moment the
    // pipeline stops stamping the column, and it would keep passing while measuring nothing.
    expect($baseline)->not->toBe('');

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace', 'color' => 'r'], 'baseline' => $baseline,
        ])
        ->assertRedirect("/submissions/{$submission->id}")
        ->assertSessionHas('toast');

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])
        ->toBe('Ada Lovelace')
        ->and(baselineOf($submission))->not->toBe($baseline);
});

it('refuses a PATCH whose real baseline is stale, with both editors holding a real token', function (): void {
    $submission = seedEditableWithRealChecksum(['full_name' => 'Ada', 'color' => 'r']);
    $stale = baselineOf($submission);
    expect($stale)->not->toBe('');

    // Editor A saves first, from a token that is current at the time.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace', 'color' => 'r'], 'baseline' => $stale,
        ])->assertRedirect("/submissions/{$submission->id}");

    editReenter();

    // Both tokens are now real and they differ — the condition the sibling case above can never reach.
    expect(baselineOf($submission))->not->toBe('')->and(baselineOf($submission))->not->toBe($stale);

    // Editor B is still on the page rendered before A saved.
    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada', 'color' => 'b'], 'baseline' => $stale,
        ])->assertSessionHas('toast');

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers'))
        ->toMatchArray(['full_name' => 'Ada Lovelace', 'color' => 'r']);
});

/*
|--------------------------------------------------------------------------
| The SHAPE of a refusal (Increment M62)
|--------------------------------------------------------------------------
| Every case above proves the refusal HAPPENS. None of them could see WHAT IT LOOKS LIKE, and the page
| depends on the difference: `Encode.vue`'s `submitEdit()` asks `preserveState` whether the errors bag is
| non-empty, so a toast-only refusal made Inertia re-key the component and replace a page of typed
| corrections with the stored document — silently, with the toast the only trace.
|
| ⛔ AND THE ASSERTION THOSE CASES USE CANNOT TELL THE TWO OUTCOMES APART. `assertSessionHas('toast')` is
| true of an accepted correction as well as a refused one — the success arm flashes a toast too — so it
| passes whichever way the controller goes. The pair below is the discriminator: errors on the refusal,
| NO errors on the acceptance. Asserting only the first half would leave a controller that bags errors on
| every response, including the ones the editor should be redirected away from, looking correct.
*/

it('carries an errors bag on a refused correction, so the page does not remount over the corrections', function (): void {
    $submission = seedEditableWithRealChecksum(['full_name' => 'Ada', 'color' => 'r']);
    $stale = baselineOf($submission);
    expect($stale)->not->toBe('');

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace', 'color' => 'r'], 'baseline' => $stale,
        ])->assertRedirect("/submissions/{$submission->id}");

    editReenter();

    // `from()` because the assertion below is about WHERE `back()` lands. The refusal must return to the page
    // whose state has to survive; a refusal that bounced to the detail view would discard the corrections
    // however well `preserveState` were armed, and without a referer `back()` would fall through to `/`.
    $this->actingAs($this->owner)
        ->from("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada', 'color' => 'b'], 'baseline' => $stale,
        ])
        ->assertRedirect("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertSessionHasErrors('baseline')
        ->assertSessionHas('toast');
});

it('leaves the session clean on an ACCEPTED correction, which is what makes the bag a discriminator', function (): void {
    $submission = seedEditableWithRealChecksum(['full_name' => 'Ada', 'color' => 'r']);
    $baseline = baselineOf($submission);
    expect($baseline)->not->toBe('');

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'Ada Lovelace', 'color' => 'r'], 'baseline' => $baseline,
        ])
        ->assertRedirect("/submissions/{$submission->id}")
        ->assertSessionHasNoErrors();
});

it('carries the errors bag on a non-editable refusal too, a different cause reaching the same arm', function (): void {
    // `illegalState()`, not `concurrentlyModified()`. The row may not be corrected AT ALL rather than not
    // from this page, and it takes the same catch arm deliberately: discarding a page of typed work is not a
    // better answer for it either, and a remount shows the editor nothing the toast has not already said.
    $submission = seedEditable(SubmissionStatus::Archived);

    $this->actingAs($this->owner)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'X'], 'baseline' => baselineOf($submission),
        ])
        ->assertSessionHasErrors('baseline')
        ->assertSessionHas('toast');
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses both routes to a reviewer, who may decide an outcome but not rewrite the answers', function (): void {
    $submission = seedEditable();

    $reviewer = User::factory()->create();
    enterTenant($this->tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);
    editReenter();

    $this->withoutVite()->actingAs($reviewer)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertForbidden();

    $this->actingAs($reviewer)
        ->patch("http://acme.meridian.test/submissions/{$submission->id}/answers", [
            'answers' => ['full_name' => 'X'], 'baseline' => baselineOf($submission),
        ])
        ->assertForbidden();

    // And the row is untouched — a 403 that had already written would be the worst of both.
    editReenter();
    expect(SubmissionAnswer::where('submission_id', $submission->id)->value('answers')['full_name'])->toBe('Ada');
});

it('refuses a viewer, who holds neither review nor edit', function (): void {
    $submission = seedEditable();

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    editReenter();

    $this->withoutVite()->actingAs($viewer)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertForbidden();
});

it('exposes can.update on the detail page, separately from can.review', function (): void {
    $submission = seedEditable();

    // Owner: both.
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Show', false)
            ->where('can.review', true)
            ->where('can.update', true));

    // Reviewer: review yes, update no. Collapsing these into one flag would hand every Reviewer the power
    // to rewrite the answers they are meant to be judging.
    $reviewer = User::factory()->create();
    enterTenant($this->tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($this->form, $reviewer, ResourceCapacity::Reviewer);
    editReenter();

    $this->withoutVite()->actingAs($reviewer)
        ->get("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Show', false)
            ->where('can.review', true)
            ->where('can.update', false));
});

it('hands the client the SUBMISSION\'S clock, not today, so both engines prune alike', function (): void {
    // ⚠️ THE CLIENT HALF OF THE REPLAY CLOCK, and without it the service's half is only half the fix.
    // `version.now` feeds `RuntimeOptions.now`, so the store evaluates `today()` against it — and the server
    // runs Stage 3 against `submitted_at`. Let this default to `Carbon::now()` and on a year-old submission
    // the editor sees fields the server is about to delete, and the first-paint step rail disagrees with the
    // one the store immediately recomputes. Delete the `$editing?->submitted_at` arm in
    // EncodeFormPresenter::clock() and this is the only test that reddens.
    $submission = seedEditable();
    $filedOn = now()->subYear();
    $submission->forceFill(['submitted_at' => $filedOn])->save();

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('version.now', $filedOn->toIso8601String()));
});

it('leaves the create page on the wall clock, so only editing replays', function (): void {
    // The control for the case above: a blank keying form must still stamp the real now, or a keyer working
    // near midnight gets a step list computed for the wrong day.
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$this->form->id}/submissions/create")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('version.now', fn (string $now): bool => abs(strtotime($now) - time()) < 120));
});

it('carries the concurrency baseline on the edit page', function (): void {
    // The token has to reach the page, or the client has nothing to send back and the server's guard is
    // comparing against a null that always matches.
    $submission = seedEditable();
    // Give the row a real checksum, as the pipeline does for every genuine submission.
    SubmissionAnswer::where('submission_id', $submission->id)->update(['answers_content_checksum' => 'abc123']);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('editing.baseline', 'abc123'));
});

it('refuses both routes across a tenant boundary, even to an owner who holds the key in their own tenant', function (): void {
    // ⚠️ NEITHER ROUTE HAD A CROSS-TENANT TEST. Both are `{submission}` route-model-bound with only
    // `can:update,submission` in front, so the isolation rests entirely on the tenant global scope plus RLS —
    // and `SubmissionRlsTest` covers the MODEL layer, not these routes. A tenant-B owner legitimately holds
    // `submissions.edit.any` in their own workspace; the id of a tenant-A submission is the only thing
    // standing between them and it.
    $victim = seedEditable(SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $other = inboxTenant('northwind');
    $intruder = User::factory()->create();
    enterTenant($other->id, $intruder->id);
    makeActiveMember($intruder, 'owner');

    // Reached on the OTHER tenant's host, with that tenant's context — exactly what a real cross-tenant
    // attempt looks like.
    $this->withoutVite()->actingAs($intruder)
        ->get("http://northwind.meridian.test/submissions/{$victim->id}/edit")
        ->assertNotFound();

    $this->actingAs($intruder)
        ->patch("http://northwind.meridian.test/submissions/{$victim->id}/answers", [
            'answers' => ['full_name' => 'Overwritten'], 'baseline' => null,
        ])
        ->assertNotFound();

    editReenter();
    expect(SubmissionAnswer::where('submission_id', $victim->id)->value('answers')['full_name'])->toBe('Ada');
});
