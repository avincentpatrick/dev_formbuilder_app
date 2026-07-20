<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ResourceCapacity;
use App\Models\ResourceGrant;
use App\Models\User;

/**
 * Who may hand out access (Increment G10b). {@see ScopeNodePolicy} covers authoring the hierarchy;
 * this covers authoring the GRANTS on it, which is the genuine escalation surface — a grant is the only
 * thing that turns a `.own` permission into reach over a specific resource.
 *
 * The base gate is `forms.collaborators.manage`, held by Owner and Admin only. RBAC §5 records why it is
 * deliberately not delegable to a form's own editors: "to prevent a Form Editor from adding themselves or
 * others to additional forms they weren't granted access to by an administrator." G10b is that
 * permission's first consumer — it and `FormPolicy::manageCollaborators` had been dead code since D3.
 *
 * Two rules sit on top of the base gate, and neither is expressible as ordinary CRUD because both depend
 * on the capacity being granted and the person receiving it:
 *
 *   2. No self-grant. Without it, "may manage collaborators" silently means "may give myself anything".
 *   3. Anti-amplification — the actor must hold the `.any` counterpart of the capacity being handed out
 *      ({@see ResourceCapacity::anyPermission()}).
 *
 * On rule 3's reachability, stated plainly so it does not get filed as dead code the way
 * `forms.collaborators.manage` was: under the seeded 5-role matrix it never fires, because Owner and Admin
 * are the only holders of `forms.collaborators.manage` and both hold `forms.edit.any` AND
 * `submissions.review.any`. It is forward-looking — it is what stops a future custom role, or any
 * delegation of `forms.collaborators.manage`, from quietly becoming "may mint any authority". Its tests
 * therefore build permission subsets directly rather than through a seeded role.
 */
final class ResourceGrantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('forms.collaborators.manage');
    }

    public function view(User $user, ResourceGrant $grant): bool
    {
        return $user->can('forms.collaborators.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('forms.collaborators.manage');
    }

    /**
     * Revoking is de-escalation, so it needs only the base gate — including revoking your own grant,
     * which can only reduce what the actor holds.
     */
    public function delete(User $user, ResourceGrant $grant): bool
    {
        return $user->can('forms.collaborators.manage');
    }

    /**
     * The real gate for a grant WRITE. Invoked with the extra arguments the decision needs:
     *
     *     Gate::forUser($actor)->authorize('grantCapacity', [ResourceGrant::class, $capacity, $recipient]);
     */
    public function grantCapacity(User $actor, ResourceCapacity $capacity, User $recipient): bool
    {
        if (! $actor->can('forms.collaborators.manage')) {
            return false;
        }

        if ((string) $actor->getKey() === (string) $recipient->getKey()) {
            return false;
        }

        return $actor->can($capacity->anyPermission());
    }
}
