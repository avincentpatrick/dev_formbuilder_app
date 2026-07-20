<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
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
| Increment F7 — the reviewer workflow: guarded status transitions (approve / return / under-review /
| archive) writing the review columns, illegal-transition rejection, and review authorization
| (review.any tenant-wide; review.own per collaborated form; Editor/Viewer cannot review).
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

function freshSubmission(string $tenantId, string $id): Submission
{
    enterTenant($tenantId);

    return Submission::findOrFail($id);
}

it('approves a submitted response, recording reviewer + timestamps', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $sub = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", ['action' => 'approve'])
        ->assertRedirect()
        ->assertSessionHas('toast');

    $fresh = freshSubmission($tenant->id, $sub->id);
    expect($fresh->status)->toBe(SubmissionStatus::Approved)
        ->and($fresh->validated_by)->toBe($owner->id)
        ->and($fresh->validated_at)->not->toBeNull()
        ->and($fresh->finalized_at)->not->toBeNull();
});

it('returns a submission with a reason', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $sub = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", [
            'action' => 'return',
            'returned_reason' => 'Please attach the ID scan.',
        ])
        ->assertRedirect();

    $fresh = freshSubmission($tenant->id, $sub->id);
    expect($fresh->status)->toBe(SubmissionStatus::Returned)
        ->and($fresh->returned_reason)->toBe('Please attach the ID scan.');
});

it('requires a reason when returning', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $sub = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", ['action' => 'return'])
        ->assertSessionHasErrors('returned_reason');

    expect(freshSubmission($tenant->id, $sub->id)->status)->toBe(SubmissionStatus::Submitted);
});

it('moves submitted → under_review and can then be archived', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $sub = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", ['action' => 'under_review'])
        ->assertRedirect();
    expect(freshSubmission($tenant->id, $sub->id)->status)->toBe(SubmissionStatus::UnderReview);

    $this->actingAs($owner)
        ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", ['action' => 'archive'])
        ->assertRedirect();
    expect(freshSubmission($tenant->id, $sub->id)->status)->toBe(SubmissionStatus::Archived);
});

it('rejects an illegal transition with an error toast and no state change', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    $sub = seedInboxSubmission($form, $owner, SubmissionStatus::Approved, ['full_name' => 'Ada']);

    $this->actingAs($owner)
        ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", ['action' => 'approve'])
        ->assertRedirect()
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error');

    expect(freshSubmission($tenant->id, $sub->id)->status)->toBe(SubmissionStatus::Approved);
});

it('lets a Reviewer review a form they collaborate on but forbids others', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $mine = publishedInboxForm($tenant, $owner, 'Reviewed');
    $other = publishedInboxForm($tenant, $owner, 'Other');
    $mineSub = seedInboxSubmission($mine, $owner, SubmissionStatus::Submitted, ['full_name' => 'M']);
    $otherSub = seedInboxSubmission($other, $owner, SubmissionStatus::Submitted, ['full_name' => 'O']);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($mine, $reviewer, ResourceCapacity::Reviewer);

    $this->actingAs($reviewer)
        ->patch("http://acme.meridian.test/submissions/{$mineSub->id}/review", ['action' => 'approve'])
        ->assertRedirect();
    expect(freshSubmission($tenant->id, $mineSub->id)->status)->toBe(SubmissionStatus::Approved);

    $this->actingAs($reviewer)
        ->patch("http://acme.meridian.test/submissions/{$otherSub->id}/review", ['action' => 'approve'])
        ->assertForbidden();
});

it('forbids a Form Editor and a Viewer from reviewing', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $sub = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    foreach (['form_editor', 'viewer'] as $role) {
        $user = User::factory()->create();
        enterTenant($tenant->id, $user->id);
        makeActiveMember($user, $role);
        if ($role === 'form_editor') {
            makeCollaborator($form, $user, ResourceCapacity::Editor); // even collaborating, no review perm
        }

        $this->actingAs($user)
            ->patch("http://acme.meridian.test/submissions/{$sub->id}/review", ['action' => 'approve'])
            ->assertForbidden();
    }
});
