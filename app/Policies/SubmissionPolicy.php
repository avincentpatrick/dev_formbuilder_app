<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Form;
use App\Models\FormCollaborator;
use App\Models\User;

/**
 * Authorization for creating submissions against a form (Increment F4b — manual encoding). Mirrors
 * {@see FormPolicy}'s `$user->can()` posture (Spatie's Gate::before → checkPermissionTo returns false for
 * an unheld OR unseeded permission, never throws — this policy is reached from the forms-list presenter on
 * every render, incl. off-tenant where no catalog/team is set).
 *
 * `create` composes three conditions: the coarse `submissions.create` permission (Owner/Admin/Form
 * Editor/Reviewer hold it; Viewer does not) **+** a per-form scope (Owner/Admin encode any form
 * tenant-wide via `forms.edit.any`; a Form Editor/Reviewer only a form they collaborate on) **+** the form
 * must be published (a submission is always created against the current published version).
 */
final class SubmissionPolicy
{
    public function create(User $user, Form $form): bool
    {
        return $user->can('submissions.create')
            && $form->current_published_version_id !== null
            && ($user->can('forms.edit.any') || $this->isCollaborator($user, $form));
    }

    /** Any per-form collaborator row (editor OR reviewer capacity) scopes a `.own`-role encoder to this form. */
    private function isCollaborator(User $user, Form $form): bool
    {
        return FormCollaborator::query()
            ->where('form_id', $form->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
