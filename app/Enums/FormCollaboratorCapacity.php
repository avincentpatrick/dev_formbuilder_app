<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-form collaborator capacity (multi-tenancy-rbac-design.md §8). Which of the two per-form `.own`
 * permissions this `form_collaborators` row grants: `editor` → `forms.edit.own`/`forms.publish.own`,
 * `reviewer` → `submissions.review.own`. A person holds exactly one capacity on a given form.
 */
enum FormCollaboratorCapacity: string
{
    case Editor = 'editor';
    case Reviewer = 'reviewer';
}
