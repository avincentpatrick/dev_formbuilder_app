<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment F7 — the submissions inbox: viewAny gating, role-scoped row visibility (tenant-wide for
| Owner/Admin/Viewer via dashboard.org.view; own-forms for Editor/Reviewer via form_collaborators),
| cross-tenant isolation, filters, pagination, and the detail read model's value resolution.
|--------------------------------------------------------------------------
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

it('forbids a member with no submissions.view from the inbox', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    // A logged-in user with no role → no submissions.view → 403.
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('http://acme.meridian.test/submissions')
        ->assertForbidden();
});

it('shows every tenant submission to a Viewer (org-wide visibility)', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'Grace']);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // holds submissions.view + dashboard.org.view

    $this->actingAs($viewer)
        ->get('http://acme.meridian.test/submissions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('submissions/Inbox', false)
            ->where('meta.total', 2)
            ->has('data', 2));
});

it('shows a Form Editor only submissions for forms they collaborate on (own-forms visibility)', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    $mine = publishedInboxForm($tenant, $editor, 'Mine'); // editor is the creator → editor collaborator
    makeActiveMember($editor, 'form_editor');

    enterTenant($tenant->id, $owner->id);
    $theirs = publishedInboxForm($tenant, $owner, 'Theirs'); // editor does NOT collaborate
    seedInboxSubmission($mine, $editor, SubmissionStatus::Submitted, ['full_name' => 'Mine 1']);
    seedInboxSubmission($theirs, $owner, SubmissionStatus::Submitted, ['full_name' => 'Theirs 1']);

    $this->actingAs($editor)
        ->get('http://acme.meridian.test/submissions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 1)->has('data', 1));
});

it('lets a Reviewer see submissions for a form they review but not others', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $reviewed = publishedInboxForm($tenant, $owner, 'Reviewed');
    $unreviewed = publishedInboxForm($tenant, $owner, 'Unreviewed');
    seedInboxSubmission($reviewed, $owner, SubmissionStatus::Submitted, ['full_name' => 'R']);
    seedInboxSubmission($unreviewed, $owner, SubmissionStatus::Submitted, ['full_name' => 'U']);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($reviewed, $reviewer, ResourceCapacity::Reviewer);

    $this->actingAs($reviewer)
        ->get('http://acme.meridian.test/submissions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('meta.total', 1)->has('data', 1));
});

it('isolates submissions across tenants (a 404 on another tenant\'s submission)', function (): void {
    $this->withoutVite();
    $acme = inboxTenant('acme');
    $globex = inboxTenant('globex');

    $acmeOwner = User::factory()->create();
    enterTenant($acme->id, $acmeOwner->id);
    makeActiveMember($acmeOwner, 'owner');

    $globexOwner = User::factory()->create();
    enterTenant($globex->id, $globexOwner->id);
    $globexForm = publishedInboxForm($globex, $globexOwner, 'Globex Intake');
    $globexSub = seedInboxSubmission($globexForm, $globexOwner, SubmissionStatus::Submitted, ['full_name' => 'Secret']);

    // The Acme owner cannot resolve a Globex submission — RLS hides the row, so binding 404s.
    $this->actingAs($acmeOwner)
        ->get("http://acme.meridian.test/submissions/{$globexSub->id}")
        ->assertNotFound();
});

it('filters the inbox by form and by status', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'A1']);
    seedInboxSubmission($formA, $owner, SubmissionStatus::Approved, ['full_name' => 'A2']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'B1']);

    $this->actingAs($owner)
        ->get("http://acme.meridian.test/submissions?form_id={$formA->id}")
        ->assertInertia(fn ($page) => $page->where('meta.total', 2));

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions?status=approved')
        ->assertInertia(fn ($page) => $page->where('meta.total', 1));
});

/*
| ── The form dropdown (Increment J2c) ──────────────────────────────────────────────────────────────────
|
| ⚠️ NOTHING IN THIS REPOSITORY ASSERTED `filters.forms` BEFORE THESE CASES — not here, not in
| `ListKeywordFilterTest`, nowhere. The only `filters.forms` assertions anywhere were two bare `->has()`
| calls against the ANALYTICS presenter. That is how the dropdown spent from F7 to J2c being derived from
| SUBMISSIONS rather than from forms, offering only forms that already had a visible response.
|
| It read as correct and no test could tell, because every fixture that exercises the filter seeds a
| submission first. These three cases are written so the two implementations are distinguishable.
*/

it('offers a form with NO responses in the dropdown', function (): void {
    // ⚠️ THE CASE THE OLD IMPLEMENTATION FAILED, and the question the filter is most often asked: an author
    // publishes a form and comes to the inbox to see whether anything has arrived. Derived from submissions,
    // that form was not selectable at all — the one thing the reader wanted to check was the one thing the
    // control could not express.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $answered = publishedInboxForm($tenant, $owner, 'Has Answers');
    publishedInboxForm($tenant, $owner, 'Nobody Has Answered This');
    seedInboxSubmission($answered, $owner, SubmissionStatus::Submitted, ['full_name' => 'A1']);

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page->where(
            'filters.forms',
            fn (Collection $forms) => $forms->pluck('label')->contains('Nobody Has Answered This')
        ));
});

it('scopes the dropdown to the forms the reader may open, not to the whole tenant', function (): void {
    // The other direction, so the fix cannot decay into "list every form". A Reviewer holds no
    // `dashboard.org.view`, so `Form::scopeReadableBy()` narrows them to their grants — and a Reviewer with
    // no grant at all sees an EMPTY dropdown rather than the tenant's form catalog.
    //
    // ⚠️ THIS IS ALSO THE MUTATION GUARD FOR THE SCOPE CHOICE. Swap `readableBy` for `visibleTo` (the
    // AUTHORING scope) and this case still passes — but the Owner case above breaks for Reviewers in
    // production, which is why the Reviewer-WITH-grant half below exists.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    publishedInboxForm($tenant, $owner, 'Not Theirs');

    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');

    $this->actingAs($reviewer)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page->where('filters.forms', []));
});

it('offers a REVIEWER the form they hold a grant on, which the authoring scope would refuse', function (): void {
    // ⚠️ THE CASE THAT FORBIDS `Form::scopeVisibleTo()` HERE. That scope keys on `forms.edit.any` /
    // `forms.edit.own`, and a Reviewer holds NEITHER — so building the dropdown on it returns an empty list
    // for a role that can see this form's submissions perfectly well, on a page that exists for them. Every
    // Owner-fixtured test would stay green while the control was blank for the reader who needed it.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner, 'Reviewed Form');

    $reviewer = User::factory()->create();
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($form, $reviewer, ResourceCapacity::Reviewer);
    app(ResourceGrantResolver::class)->forget();

    $this->actingAs($reviewer)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page->where(
            'filters.forms',
            fn (Collection $forms) => $forms->pluck('label')->contains('Reviewed Form')
        ));
});

it('carries form_id on every inbox row so the form name can be linked', function (): void {
    // Increment J2c. `detail()` has shipped `form_id` since F7 while the LIST row did not, so the inbox
    // printed a form's title on every row and linked none of them — one of the three dead ends
    // `FormHubController`'s docblock names. Free: `form:id,title` was already eager-loaded.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner, 'Linked Form');
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'A1']);

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page
            ->where('data.0.form_id', (string) $form->id)
            ->where('data.0.form_title', 'Linked Form')
            ->etc());
});

it('hides in-progress drafts by default and surfaces them (with completeness) under the Draft filter', function (): void {
    // Increment H10 — drafts (guest "save and finish later") are excluded from the default review list, but
    // the existing Draft status option opts them back in, carrying their progress columns.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Done']);
    $draft = seedInboxSubmission($form, $owner, SubmissionStatus::Draft, ['full_name' => 'Partial']);
    $draft->forceFill(['completeness_percent' => 40, 'last_saved_at' => now(), 'draft_expires_at' => now()->addDays(30)])->save();

    // Default list: only the submitted row (the draft is hidden).
    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->has('data', 1)
            ->where('data.0.status', 'submitted'));

    // Draft filter: the draft appears with its completeness surfaced.
    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions?status=draft')
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('data.0.status', 'draft')
            ->where('data.0.completeness_percent', 40));
});

it('offers resume only on draft rows the viewer may finalize', function (): void {
    // Increment I9b. Three assertions in one because the interesting part is the CONTRAST: a draft the viewer
    // may promote, a finalized row (never resumable, whatever the permissions), and a viewer with no claim.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);

    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Done']);
    $draft = seedInboxSubmission($form, $owner, SubmissionStatus::Draft, ['full_name' => 'Partial']);
    $draft->forceFill(['completeness_percent' => 40, 'last_saved_at' => now()])->save();

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions?status=draft')
        ->assertInertia(fn ($page) => $page->where('data.0.can.resume', true));

    // A finalized row is never resumable — the route 404s on it, so the button must not be offered either.
    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions?status=submitted')
        ->assertInertia(fn ($page) => $page->where('data.0.can.resume', false));

    // A viewer holds `submissions.view` but no editor capacity, so they may read the draft and not continue it.
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    enterTenant($tenant->id, $owner->id);

    $this->actingAs($viewer)
        ->get('http://acme.meridian.test/submissions?status=draft')
        ->assertInertia(fn ($page) => $page->where('data.0.can.resume', false));
});

it('lists screened-out submissions in the default view and offers them as a filter', function (): void {
    // I9a. Deliberately the OPPOSITE of the draft rule directly above, and the contrast is the point: a draft
    // was never finalized, so hiding it is a review convenience; a screened-out response WAS finalized and is
    // a real thing the tenant received. Hiding it would also hide the one shape that makes a badly-built form
    // visible — a form that screens everyone out shows a page of "Screened out" pills instead of silence.
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Done']);
    seedInboxSubmission($form, $owner, SubmissionStatus::ScreenedOut, []);

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 2)
            // The filter catalog is `SubmissionStatus::cases()`-derived, so it picked the new option up with
            // no code change — asserted so that stays true rather than being assumed.
            ->where('filters.statuses', fn (Collection $statuses): bool => $statuses->contains(
                fn (array $option): bool => $option === ['value' => 'screened_out', 'label' => 'Screened out'],
            )));

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions?status=screened_out')
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 1)
            ->where('data.0.status', 'screened_out'));
});

it('paginates the inbox at 25 per page', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    for ($i = 0; $i < 30; $i++) {
        seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => "Person {$i}"]);
    }

    $this->actingAs($owner)
        ->get('http://acme.meridian.test/submissions')
        ->assertInertia(fn ($page) => $page
            ->where('meta.total', 30)
            ->where('meta.last_page', 2)
            ->has('data', 25));
});

it('renders answers resolved against the version schema in the detail read model', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ada Lovelace',
        'color' => 'r',
        'hobbies' => ['read', 'run'],
        'subscribe' => true,
    ]);

    $detail = app(SubmissionInboxPresenter::class)->detail($owner, $submission->refresh());

    $values = collect($detail['blocks'])
        ->flatMap(fn (array $b): array => $b['fields'])
        ->keyBy('key')
        ->map(fn (array $f): string => $f['value']);

    expect($values['full_name'])->toBe('Ada Lovelace')
        ->and($values['color'])->toBe('Red')             // choice value → option label
        ->and($values['hobbies'])->toBe('Reading; Running') // multi-select joined + labelled
        ->and($values['subscribe'])->toBe('Yes');           // yes/no rendered
});

it('forbids a non-collaborating Form Editor from opening a submission detail', function (): void {
    $this->withoutVite();
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner, 'Not mine');
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor'); // no collaborator row for this form

    $this->actingAs($editor)
        ->get("http://acme.meridian.test/submissions/{$submission->id}")
        ->assertForbidden();
});
