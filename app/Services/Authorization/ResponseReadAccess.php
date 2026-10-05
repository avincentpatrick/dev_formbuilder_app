<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Policies\SubmissionPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Whether a person may read a form's responses — asked of someone who need not be the one acting (M133,
 * `R-5da4a30f` — Connect project v1).
 *
 * There is no `forms.view` permission. Reading a form is {@see FormPolicy::viewOverview()} and
 * reading its responses is {@see SubmissionPolicy::viewAny()}; the hub's own responses route stacks
 * exactly those two (`can:viewOverview,form` then `can:viewAny,Submission`). This is that conjunction, asked
 * through `Gate::forUser()` as `NotificationPresenter` asks it for a reader who is not the actor.
 *
 * Asked for two reasons, both Connect project's consent keys:
 *   - `D87`: only someone who can read a form's responses may switch on sharing them;
 *   - key 2: a destination form's OWNER (`forms.owner_user_id` — Kobo's rule, not whoever is editing) must be able
 *     to read the source's responses, at the destination's publish and on every serve of the list, so revoking
 *     the owner's access stops the list.
 *
 * ⚠️ TWO PIECES OF CONTEXT THE GATE DOES NOT SUPPLY FOR A PERSON WHO IS NOT THE ACTOR:
 *   - **membership.** A removed or suspended member can still carry a role (`NotificationRecipientResolver`
 *     records the same gap), and `forms.owner_user_id` outlives the owner's membership. So an ACTIVE `tenant_users`
 *     row is required first — RLS scopes that read to the current workspace. ⚠️ HONESTY NOTE, measured in M133: a
 *     mutation that drops this check stays GREEN, because row security on `users` (`users_users_visibility`) already
 *     hides anyone who is not an active member of the current workspace, so `ownerCanRead()` finds no owner to ask
 *     about on either path, authenticated or guest. It is kept as defence in depth, for a caller that hands this
 *     class a `User` it loaded some other way;
 *   - **the permissions team.** Spatie resolves roles against the team id `EstablishTenantDatabaseContext` sets,
 *     and the guest middleware sets none: in a guest request every role lookup would answer from no roles, and an
 *     owner who CAN read would read as one who cannot. The team id is set from the tenant context when it is
 *     unset, as `AnalyticsExporter` does for a queued export.
 */
final class ResponseReadAccess
{
    public function canRead(User $user, Form $form): bool
    {
        if (! $this->isActiveMember($user)) {
            return false;
        }

        $this->ensurePermissionsTeam();

        $gate = Gate::forUser($user);

        return $gate->allows('viewAny', Submission::class) && $gate->allows('viewOverview', $form);
    }

    /** Key 2: whether the destination form's owner may read the source form's responses. */
    public function ownerCanRead(Form $destination, Form $source): bool
    {
        $owner = User::query()->whereKey($destination->owner_user_id)->first();

        return $owner !== null && $this->canRead($owner, $source);
    }

    private function isActiveMember(User $user): bool
    {
        return DB::table('tenant_users')
            ->where('user_id', $user->getKey())
            ->where('status', TenantUserStatus::Active->value)
            ->exists();
    }

    private function ensurePermissionsTeam(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId !== null && $registrar->getPermissionsTeamId() === null) {
            $registrar->setPermissionsTeamId($tenantId);
        }
    }
}
