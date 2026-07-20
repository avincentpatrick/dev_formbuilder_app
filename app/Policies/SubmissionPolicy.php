<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;

/**
 * Authorization for the submissions inbox, review, and export (Increments F4b + F7). Mirrors
 * {@see FormPolicy}'s `$user->can()` posture (Spatie's Gate::before → checkPermissionTo returns false for
 * an unheld OR unseeded permission, never throws — these methods are reached from the inbox presenter and
 * `HandleInertiaRequests::share()` on every render, incl. off-tenant where no catalog/team is set).
 *
 * Two visibility scopes coexist on the single `submissions.view` permission (multi-tenancy-rbac-design.md §5,
 * lines 97–104): Owner/Admin/Viewer see submissions **tenant-wide** (they hold `dashboard.org.view`), while
 * Form Editor/Reviewer see only forms they **collaborate on** (editor OR reviewer capacity). That split lives
 * in {@see hasOrgWideVisibility()} + {@see collaboratesWith()} and is mirrored by {@see Submission::scopeVisibleTo()}
 * so the policy (single-row checks) and the inbox query (list scoping) agree on exactly one rule.
 */
final class SubmissionPolicy
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * Manual encoding (F4b): submissions.create + published form + per-form scope.
     *
     * G10a tightening (deliberate, flagged): the per-form scope now requires EDITOR capacity, where it
     * previously accepted either capacity. Manual encoding is an authoring act, and once a grant can be
     * made against a scope node a "reviewer" grant on an interior node would otherwise hand out write
     * access to every form beneath it. No existing test asserted the old behaviour.
     */
    public function create(User $user, Form $form): bool
    {
        return $user->can('submissions.create')
            && $form->current_published_version_id !== null
            && ($user->can('forms.edit.any')
                || $this->grants->holdsOnFormId($user, $form->id, ResourceCapacity::Editor));
    }

    /** Reach the inbox list at all — row-level scoping is applied by the query, not here (F7). */
    public function viewAny(User $user): bool
    {
        return $user->can('submissions.view');
    }

    /** View one submission's detail: the permission + tenant-wide OR per-form collaborator scope (F7). */
    public function view(User $user, Submission $submission): bool
    {
        return $user->can('submissions.view')
            && ($this->hasOrgWideVisibility($user) || $this->collaboratesWith($user, $submission->form_id));
    }

    /** Stream a form's export (F7): submissions.export (Viewer lacks it) + the same visibility scope. */
    public function export(User $user, Form $form): bool
    {
        return $user->can('submissions.export')
            && ($this->hasOrgWideVisibility($user) || $this->collaboratesWith($user, $form->id));
    }

    /** Approve/return/transition one submission (F7): review.any tenant-wide, review.own per collaborated form. */
    public function review(User $user, Submission $submission): bool
    {
        return $user->can('submissions.review.any')
            || ($user->can('submissions.review.own') && $this->collaboratesWith($user, $submission->form_id));
    }

    /**
     * Tenant-wide submission visibility. `dashboard.org.view` is the one permission that separates the
     * org-wide read roles (Owner/Admin/Viewer) from the collaborator-scoped ones (Form Editor/Reviewer,
     * who hold only `dashboard.form.view`) — multi-tenancy-rbac-design.md §5.
     */
    private function hasOrgWideVisibility(User $user): bool
    {
        return $user->can('dashboard.org.view');
    }

    /**
     * Any grant covering this form (editor OR reviewer capacity) scopes a `.own` role to it — the exact
     * rule this policy has always applied. Since G10a the grant may name the form directly or name the
     * scope node it belongs to; {@see ResourceGrantResolver} owns that distinction so this policy and
     * {@see Submission::scopeVisibleTo()} cannot disagree about it.
     */
    private function collaboratesWith(User $user, string $formId): bool
    {
        return $this->grants->holdsAny($user, $formId);
    }
}
