<?php

declare(strict_types=1);

use App\Enums\ResourceCapacity;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\ResourceGrant;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    makeCollaborator($form, $editor, ResourceCapacity::Editor);
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

    makeCollaborator($form, $reviewer, ResourceCapacity::Reviewer);
    expect($reviewer->can('view', $submission))->toBeTrue()
        ->and($reviewer->can('export', [Submission::class, $form]))->toBeTrue()
        ->and($reviewer->can('review', $submission))->toBeTrue();
});

it('lets ONE reviewer-role member both review and encode once their grant is editor capacity', function (): void {
    /*
     * M77, closing M13's row. ⛔ THE FIRST DRAFT OF THIS CASE CLAIMED TO BE "THE CASE THAT DID NOT
     * EXIST", AND THAT WAS FALSE — the G10a case below ("requires EDITOR capacity to manually encode")
     * already pins a reviewer-capacity grant refusing `create`. Both arms of M77's read-only fan-out
     * missed it too, and one of them proposed adding exactly the case that was already there. Recorded
     * rather than quietly deleted, because "no test asserts this" is the single most repeated false
     * premise in this repository's history and it was about to be repeated here.
     *
     * ⚠️ SO M13's DEFECT WAS PURELY DOCUMENTARY, WHICH IS THE SHARPER FINDING. The behaviour was
     * already correct AND already tested; five documents — the seeder comment, the RBAC design doc's
     * role table, its §5 matrix row, its §8.3 shape sentence and `docs/ACCESS-MATRIX.md` — simply
     * disagreed with it. No access changed in M77.
     *
     * WHAT THIS CASE ADDS THAT THE G10a ONE DOES NOT, and the only reason it earns its place:
     *   (1) It asserts the coarse permission IS held (`$reviewer->can('submissions.create')`). The G10a
     *       case says so only in a comment, and it is the half every stale document read as "may
     *       encode" — so it is the half that has to be visible when someone next reads this file.
     *   (2) It uses ONE member throughout. The G10a case proves the editor half with a *form_editor*
     *       user, so nothing anywhere proved that a REVIEWER-role member can hold editor capacity and
     *       get both review and encode. That configuration is why `submissions.create` must stay on
     *       the role — M13's own prescribed remedy was to drop it, which would break this.
     *   (3) It therefore pins the §8.3 correction: `review()` still passes under an EDITOR grant,
     *       because `collaboratesWith()` is `ResourceGrantResolver::holdsAny()`, which accepts either
     *       capacity. The design doc said that check was capacity-specific. If it were, this
     *       configuration would be impossible — `capacity` sits outside
     *       `resource_grants_target_user_unique` and a grant is replaced rather than added, so nobody
     *       can hold both capacities on one form.
     */
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');

    // The coarse Spatie permission IS held — this is the half every document read as "may encode".
    expect($reviewer->can('submissions.create'))->toBeTrue();

    makeCollaborator($form, $reviewer, ResourceCapacity::Reviewer);

    // ⛔ …and the policy still refuses, because G10a requires editor capacity for an authoring act.
    expect($reviewer->can('create', [Submission::class, $form]))->toBeFalse()
        ->and($reviewer->can('review', $submission))->toBeTrue();

    // ⛔ RE-GRANTED BY REPLACEMENT, NOT BY A SECOND ROW, AND THE DIFFERENCE IS THE DOCUMENTED FACT.
    // `makeCollaborator()` inserts, so calling it twice here raises 23505 on
    // `resource_grants_target_user_unique` — measured, and it is exactly the constraint that makes
    // "one capacity per target" true. `ResourceGrantService::grant()` handles this in production by
    // UPDATING the existing grant; this mirrors that without dragging the service's actor-permission
    // and tenant-resolution machinery into a policy truth-table test.
    ResourceGrant::query()
        ->where('scopeable_type', $form->getMorphClass())
        ->where('scopeable_id', $form->getKey())
        ->where('user_id', $reviewer->id)
        ->update(['capacity' => ResourceCapacity::Editor->value]);
    app(ResourceGrantResolver::class)->forget($reviewer->id);

    expect($reviewer->can('create', [Submission::class, $form]))->toBeTrue()
        ->and($reviewer->can('review', $submission))->toBeTrue();
});

/*
| Increment G10a — the same scoping, now reachable through a hierarchy node. The cases above are
| unchanged: they prove the direct-grant path still behaves exactly as it did before the rewiring.
*/

it('scopes a reviewer through an ANCESTOR node grant, and agrees with the inbox list', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $root = makeScopeNode(name: 'Region I');
    $province = makeScopeNode($root, 'Province A');

    $form = publishedInboxForm($tenant, $owner);
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $province->id]);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');

    expect($reviewer->can('view', $submission))->toBeFalse();

    grantOnNode($root, $reviewer, ResourceCapacity::Reviewer, descendants: true);

    // The per-row policy and the list-scoping query MUST agree — otherwise the inbox shows a row whose
    // detail page 403s. Asserting both together is the only way that divergence is caught.
    expect($reviewer->can('view', $submission))->toBeTrue()
        ->and($reviewer->can('review', $submission))->toBeTrue()
        ->and(Submission::query()->visibleTo($reviewer)->pluck('id')->contains($submission->id))->toBeTrue();
});

it('excludes a sibling branch from both the policy and the list', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);

    $root = makeScopeNode(name: 'Region I');
    $left = makeScopeNode($root, 'Province A');
    $right = makeScopeNode($root, 'Province B');

    $form = publishedInboxForm($tenant, $owner);
    DB::table('forms')->where('id', $form->id)->update(['scope_node_id' => $right->id]);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    grantOnNode($left, $reviewer, ResourceCapacity::Reviewer, descendants: true);

    expect($reviewer->can('view', $submission))->toBeFalse()
        ->and(Submission::query()->visibleTo($reviewer)->pluck('id')->contains($submission->id))->toBeFalse();
});

it('requires EDITOR capacity to manually encode, not merely any grant', function (): void {
    // The one deliberate G10a behaviour change. Manual encoding is an authoring act, and at subtree scale
    // a reviewer grant on an interior node would otherwise hand out write access to every form under it.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);

    $reviewer = User::factory()->create();
    enterTenant($tenant->id, $reviewer->id);
    makeActiveMember($reviewer, 'reviewer');
    makeCollaborator($form, $reviewer, ResourceCapacity::Reviewer);

    // Reviewers hold submissions.create and may review this form — but may no longer encode against it.
    expect($reviewer->can('review', seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, [])))->toBeTrue()
        ->and($reviewer->can('create', [Submission::class, $form]))->toBeFalse();

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($form, $editor, ResourceCapacity::Editor);

    expect($editor->can('create', [Submission::class, $form]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The respondent clause (Increment I8a) — filed by I4 as `docs/feature-backlog.md` §4, decided with the
| user 2026-08-07 and built here rather than in I9.
|--------------------------------------------------------------------------
| Before this, `view()` was `submissions.view && (org-wide || collaborates)` with NOTHING about having
| authored the row — yet `submission_approved`/`submission_returned` are addressed to `respondent_user_id`
| and tell that person to open it. A Form Editor whose grant was later revoked kept a notification
| pointing at a bare 403 outside the Inertia shell. I4 made that honest (NotificationPresenter runs the
| real Gate and ships `url: null`); this makes it work.
*/

it('lets a respondent read back their own submission after their grant is revoked', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($form, $editor, ResourceCapacity::Editor);

    $submission = seedInboxSubmission($form, $editor, SubmissionStatus::Submitted, ['full_name' => 'Mine']);

    expect($editor->can('view', $submission))->toBeTrue();

    // Revoke every grant — the exact scenario NotificationPresenter's docblock describes.
    DB::table('resource_grants')->where('user_id', $editor->id)->delete();
    app(ResourceGrantResolver::class)->forget($editor->id);

    expect($editor->can('view', $submission))->toBeTrue()
        // ...and the list agrees, which is the invariant SubmissionPolicy's class docblock pins.
        ->and(Submission::query()->visibleTo($editor)->pluck('id')->contains($submission->id))->toBeTrue();
});

it('does not let a respondent read OTHER submissions on the same form', function (): void {
    // The clause is per-ROW, not per-form. Authoring one response is not a reason to read a colleague's.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $someoneElses = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Theirs']);

    $respondent = User::factory()->create();
    enterTenant($tenant->id, $respondent->id);
    makeActiveMember($respondent, 'form_editor');
    $mine = seedInboxSubmission($form, $respondent, SubmissionStatus::Submitted, ['full_name' => 'Mine']);

    expect($respondent->can('view', $mine))->toBeTrue()
        ->and($respondent->can('view', $someoneElses))->toBeFalse();

    $visible = Submission::query()->visibleTo($respondent)->pluck('id');
    expect($visible->contains($mine->id))->toBeTrue()
        ->and($visible->contains($someoneElses->id))->toBeFalse();
});

it('does not widen review or export for a respondent', function (): void {
    // view() ONLY. Reading back what you typed is not a privilege; deciding your own submission's outcome
    // is a different question with a different answer. Post-submission EDITING is I9's vertical.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);

    $respondent = User::factory()->create();
    enterTenant($tenant->id, $respondent->id);
    makeActiveMember($respondent, 'form_editor');
    $mine = seedInboxSubmission($form, $respondent, SubmissionStatus::Submitted, ['full_name' => 'Mine']);

    expect($respondent->can('view', $mine))->toBeTrue()
        ->and($respondent->can('review', $mine))->toBeFalse()
        ->and($respondent->can('export', [Submission::class, $form]))->toBeFalse();
});

it('grants nothing to a role that holds no submissions.view at all', function (): void {
    // The permission check still leads. Without it this clause would hand a read to anyone with a row,
    // regardless of the catalog.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);

    $respondent = User::factory()->create();
    enterTenant($tenant->id, $respondent->id);
    makeActiveMember($respondent, 'form_editor');
    $mine = seedInboxSubmission($form, $respondent, SubmissionStatus::Submitted, []);

    $respondent->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($respondent->can('view', $mine))->toBeFalse();
});

it('leaves an ANONYMOUS guest submission unreadable', function (): void {
    // respondent_user_id is NULL for every guest row. The explicit null guard in isRespondent() is what
    // keeps "belongs to nobody" from collapsing into "belongs to whoever asks".
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $guestRow = seedInboxSubmission($form, null, SubmissionStatus::Submitted, [], SubmissionSource::Guest);

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');

    expect($guestRow->respondent_user_id)->toBeNull()
        ->and($editor->can('view', $guestRow))->toBeFalse();
});

it('keeps other inbox filters intact alongside the respondent arm', function (): void {
    // Pins the COMPOSED inbox behaviour: adding a respondent arm to the visibility scope must not widen
    // what a status/date/form filter returns. Honest note, established by mutation while writing this:
    // flattening the closure in scopeVisibleTo() does NOT redden this test, because Builder::callScope()
    // already nests whatever a local scope adds. The closure survives for the case the framework does not
    // cover — a direct scopeVisibleTo() call — and this test covers the composition, not the closure.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);

    $respondent = User::factory()->create();
    enterTenant($tenant->id, $respondent->id);
    makeActiveMember($respondent, 'form_editor');

    $submitted = seedInboxSubmission($form, $respondent, SubmissionStatus::Submitted, []);
    $approved = seedInboxSubmission($form, $respondent, SubmissionStatus::Approved, []);

    $filtered = Submission::query()
        ->where('status', SubmissionStatus::Approved->value)
        ->visibleTo($respondent)
        ->pluck('id');

    expect($filtered->contains($approved->id))->toBeTrue()
        ->and($filtered->contains($submitted->id))->toBeFalse();
});
