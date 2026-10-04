<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormFolder;
use App\Models\User;

/**
 * Who may do what with a forms-list folder (M131, `D79`, answered by the user in chat on 2026-10-04).
 *
 * Anyone who may create forms creates a folder; only holders of `forms.edit.any` — Owners and Admins —
 * rename or delete one, because a folder holds other people's forms and a rename or a delete moves them all.
 * Filing a form is an edit of THAT form and is gated `can:update,form` on its own route, not here.
 *
 * No new permission key, deliberately: the existing ones already draw the line `D79` asked for, and a new key
 * would need a backfill migration on every workspace for no difference in who may act.
 *
 * Permission checks go through `$user->can()` rather than `hasPermissionTo()`, matching {@see FormPolicy}.
 */
final class FormFolderPolicy
{
    /** The same rule as {@see FormPolicy::viewAny()}: whoever reaches the forms list sees its folders. */
    public function viewAny(User $user): bool
    {
        return $user->can('forms.create')
            || $user->can('forms.edit.any')
            || $user->can('forms.edit.own');
    }

    public function create(User $user): bool
    {
        return $user->can('forms.create');
    }

    public function update(User $user, FormFolder $folder): bool
    {
        return $user->can('forms.edit.any');
    }

    public function delete(User $user, FormFolder $folder): bool
    {
        return $user->can('forms.edit.any');
    }
}
