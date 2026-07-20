<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ResourceCapacity;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;

/**
 * Per-form authorization (multi-tenancy-rbac-design.md §5/§8) — the `.any`/`.own` composition. `.any`
 * (Owner/Admin) is a pure tenant-wide Spatie permission check; `.own` (Form Editor) additionally requires
 * an editor grant covering THIS form. The coarse Spatie permission answers "may this role ever do this
 * action"; this policy adds the per-form resource check.
 *
 * Since Increment G10a that resource check is delegated to {@see ResourceGrantResolver}, which resolves
 * both a direct grant on the form and a grant on the scope node the form is assigned to. This policy no
 * longer knows the storage shape — which is what keeps it in agreement with
 * {@see Submission::scopeVisibleTo()}, the list-side twin.
 *
 * Permission checks go through `$user->can()` (Spatie's Gate::before → checkPermissionTo, which returns
 * false for an unheld OR unseeded permission) rather than hasPermissionTo() — the latter THROWS
 * PermissionDoesNotExist when the catalog isn't present, and this policy is reached from
 * HandleInertiaRequests::share() on every response (incl. the central admin console, where no tenant
 * catalog/team is set).
 */
final class FormPolicy
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    public function viewAny(User $user): bool
    {
        return $user->can('forms.create')
            || $user->can('forms.edit.any')
            || $user->can('forms.edit.own');
    }

    public function view(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    public function create(User $user): bool
    {
        return $user->can('forms.create');
    }

    public function update(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    public function restore(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    public function publish(User $user, Form $form): bool
    {
        return $user->can('forms.publish.any')
            || ($user->can('forms.publish.own') && $this->holdsEditorGrant($user, $form));
    }

    public function delete(User $user, Form $form): bool
    {
        return $user->can('forms.delete');
    }

    public function manageCollaborators(User $user, Form $form): bool
    {
        return $user->can('forms.collaborators.manage');
    }

    private function canEdit(User $user, Form $form): bool
    {
        return $user->can('forms.edit.any')
            || ($user->can('forms.edit.own') && $this->holdsEditorGrant($user, $form));
    }

    /** Editor capacity on this form — directly granted, or inherited from its scope node (G10a). */
    private function holdsEditorGrant(User $user, Form $form): bool
    {
        return $this->grants->holds($user, $form, ResourceCapacity::Editor);
    }
}
