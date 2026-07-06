<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FormCollaboratorCapacity;
use App\Models\Form;
use App\Models\FormCollaborator;
use App\Models\User;

/**
 * Per-form authorization (multi-tenancy-rbac-design.md §5/§8) — the `.any`/`.own` composition. `.any`
 * (Owner/Admin) is a pure tenant-wide Spatie permission check; `.own` (Form Editor) additionally requires
 * an editor `form_collaborators` row for THIS form. The coarse Spatie permission answers "may this role
 * ever do this action"; this policy adds the per-form resource check.
 */
final class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('forms.create')
            || $user->hasPermissionTo('forms.edit.any')
            || $user->hasPermissionTo('forms.edit.own');
    }

    public function view(User $user, Form $form): bool
    {
        return $this->canEdit($user, $form);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('forms.create');
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
        return $user->hasPermissionTo('forms.publish.any')
            || ($user->hasPermissionTo('forms.publish.own') && $this->isEditorCollaborator($user, $form));
    }

    public function delete(User $user, Form $form): bool
    {
        return $user->hasPermissionTo('forms.delete');
    }

    public function manageCollaborators(User $user, Form $form): bool
    {
        return $user->hasPermissionTo('forms.collaborators.manage');
    }

    private function canEdit(User $user, Form $form): bool
    {
        return $user->hasPermissionTo('forms.edit.any')
            || ($user->hasPermissionTo('forms.edit.own') && $this->isEditorCollaborator($user, $form));
    }

    private function isEditorCollaborator(User $user, Form $form): bool
    {
        return FormCollaborator::query()
            ->where('form_id', $form->id)
            ->where('user_id', $user->id)
            ->where('capacity', FormCollaboratorCapacity::Editor)
            ->exists();
    }
}
