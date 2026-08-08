<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Submission;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9b — resuming a saved draft on the encode page.
|--------------------------------------------------------------------------
| Every GET here carries `withoutVite()`: the Pest CI job builds no assets, so a blade rendering `@vite`
| throws and the 200 arrives as a 500 — a divergence that only ever shows up on CI.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = publishedInboxForm($this->tenant, $this->owner);
});

function resumeReenter(): void
{
    enterTenant(test()->tenant->id, test()->owner->id);
}

function seedResumableDraft(Form $form, array $answers): Submission
{
    $uuid = Uuid::uuid7()->toString();
    test()->actingAs(test()->owner)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/submissions/draft", [
            'answers' => $answers,
            'client_submission_uuid' => $uuid,
            'draft_current_step' => '__lead__',
        ])
        ->assertCreated();
    resumeReenter();

    return Submission::query()->where('client_submission_uuid', $uuid)->firstOrFail();
}

it('rehydrates the encode page from the stored answers', function (): void {
    $draft = seedResumableDraft($this->form, ['full_name' => 'Ada Lovelace']);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$draft->id}/resume")
        ->assertInertia(fn ($page) => $page
            // `false` disables Inertia's page-file existence check, and it is the repo-wide convention for
            // the same reason `withoutVite()` is: the Pest CI job builds no assets, so the view finder
            // cannot resolve a page and the assertion fails on Linux while passing on a case-insensitive
            // dev filesystem. Every other `->component()` call in tests/ passes it.
            ->component('submissions/Encode', false)
            ->where('draft.id', $draft->id)
            ->where('draft.client_submission_uuid', $draft->client_submission_uuid)
            ->where('draft.answers.full_name', 'Ada Lovelace')
            ->where('draft.current_step', '__lead__')
            ->has('draft_url'));
});

it('serves the blank create page with a null draft, so the prop is not resume-only', function (): void {
    // The control for every assertion above: without it they would pass against a page that always carried a
    // draft object, and `draft === null` is the single test the client uses for "am I creating".
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$this->form->id}/submissions/create")
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Encode', false)
            ->where('draft', null)
            ->has('draft_url'));
});

it('404s a resume on a finalized submission — a state guard, not an authorization one', function (): void {
    $submission = seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$submission->id}/resume")
        ->assertNotFound();
});

it('redirects with an honest message when the draft\'s version was superseded', function (): void {
    // ⚠️ NEVER A 500. A draft is pinned to the schema it was keyed against; if the author republished, the
    // page cannot be rendered against the current version (that would save answers keyed to a schema the
    // promote will then reject). Refusing AT OPEN is the only point at which this is still cheap for the
    // keyer — the alternative is an exception after ten minutes of retyping.
    $draft = seedResumableDraft($this->form, ['full_name' => 'Ada']);

    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    resumeReenter();

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$draft->id}/resume")
        ->assertRedirect("/submissions/{$draft->id}")
        ->assertSessionHas('toast');
});

it('projects the step list under the DRAFT\'S answers, not an empty document', function (): void {
    // Without this the server's first paint is the empty-document projection while the store immediately
    // recomputes a different one from the restored answers — a visible one-frame step flip on every resume,
    // and the two engines disagreeing on the one page whose purpose is that they agree.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Branching');
    $draftVersion = $form->draftVersion;
    addFormField($draftVersion, $this->owner, 'role', FieldType::ShortText, 1);
    $staff = FormSection::create([
        'form_version_id' => $draftVersion->id, 'key' => 'staff', 'label' => 'Staff details',
        'sequence' => 2, 'relevant_expression' => "\${role} = 'staff'",
    ]);
    addFormField($draftVersion, $this->owner, 'staff_number', FieldType::ShortText, 2, ['form_section_id' => $staff->id]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    resumeReenter();
    $form = $form->refresh();

    // The blank page: the gated section is NOT a step.
    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/create")
        ->assertInertia(fn ($page) => $page->where(
            'steps',
            fn ($steps): bool => ! collect($steps)->contains(fn ($s): bool => $s['section_key'] === 'staff'),
        ));

    // The same form resumed with `role = staff`: the branch IS a step, from the server, at first paint.
    $draft = seedResumableDraft($form, ['role' => 'staff']);

    $this->withoutVite()->actingAs($this->owner)
        ->get("http://acme.meridian.test/submissions/{$draft->id}/resume")
        ->assertInertia(fn ($page) => $page->where(
            'steps',
            fn ($steps): bool => collect($steps)->contains(fn ($s): bool => $s['section_key'] === 'staff'),
        ));
});

it('refuses a resume to someone with no claim on the form', function (): void {
    $editor = User::factory()->create();
    enterTenant($this->tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    resumeReenter();

    $draft = seedResumableDraft($this->form, ['full_name' => 'Ada']);

    $this->withoutVite()->actingAs($editor)
        ->get("http://acme.meridian.test/submissions/{$draft->id}/resume")
        ->assertForbidden();

    // The control: with a grant on that form, the same request renders.
    resumeReenter();
    makeCollaborator($this->form, $editor, ResourceCapacity::Editor);

    $this->withoutVite()->actingAs($editor)
        ->get("http://acme.meridian.test/submissions/{$draft->id}/resume")
        ->assertOk();
});
