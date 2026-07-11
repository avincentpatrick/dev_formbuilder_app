<?php

declare(strict_types=1);

use App\Enums\FormCollaboratorCapacity;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment F7 — SubmissionPolicy truth table (multi-tenancy-rbac-design.md §5). viewAny/view/export/review
| across all five roles, and the own-forms collaborator scoping for Editor/Reviewer.
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

it('grants owner/admin view + export + review on any submission', function (string $role): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $role);

    expect($user->can('viewAny', Submission::class))->toBeTrue()
        ->and($user->can('view', $submission))->toBeTrue()
        ->and($user->can('export', [Submission::class, $form]))->toBeTrue()
        ->and($user->can('review', $submission))->toBeTrue();
})->with(['owner', 'admin']);

it('grants a viewer org-wide view but never export or review', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    expect($viewer->can('viewAny', Submission::class))->toBeTrue()
        ->and($viewer->can('view', $submission))->toBeTrue()   // org-wide (dashboard.org.view)
        ->and($viewer->can('export', [Submission::class, $form]))->toBeFalse()      // no submissions.export
        ->and($viewer->can('review', $submission))->toBeFalse();
});

it('scopes a form editor to collaborated forms and denies review', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    // Not collaborating: may reach the inbox (viewAny) but not this form's rows.
    expect($editor->can('viewAny', Submission::class))->toBeTrue()
        ->and($editor->can('view', $submission))->toBeFalse()
        ->and($editor->can('export', [Submission::class, $form]))->toBeFalse()
        ->and($editor->can('review', $submission))->toBeFalse();

    // Collaborating: gains view + export, but review stays denied (no review permission).
    makeCollaborator($form, $editor, FormCollaboratorCapacity::Editor);
    expect($editor->can('view', $submission))->toBeTrue()
        ->and($editor->can('export', [Submission::class, $form]))->toBeTrue()
        ->and($editor->can('review', $submission))->toBeFalse();
});

it('scopes a reviewer to collaborated forms and allows review there', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');

    expect($reviewer->can('view', $submission))->toBeFalse()
        ->and($reviewer->can('review', $submission))->toBeFalse();

    makeCollaborator($form, $reviewer, FormCollaboratorCapacity::Reviewer);
    expect($reviewer->can('view', $submission))->toBeTrue()
        ->and($reviewer->can('export', [Submission::class, $form]))->toBeTrue()
        ->and($reviewer->can('review', $submission))->toBeTrue();
});
