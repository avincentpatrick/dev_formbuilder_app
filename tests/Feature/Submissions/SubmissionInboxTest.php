<?php

declare(strict_types=1);

use App\Enums\FormCollaboratorCapacity;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    makeCollaborator($reviewed, $reviewer, FormCollaboratorCapacity::Reviewer);

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
