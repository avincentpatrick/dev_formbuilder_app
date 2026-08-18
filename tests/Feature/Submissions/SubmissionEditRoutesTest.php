<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\Forms\PublishService;
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
