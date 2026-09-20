<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ReadsKeywordFilter;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorResetService;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Authorization\AssignableRoles;
use App\Support\Search\ListEmptyReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant member administration (multi-tenancy-rbac-design.md §7) — the Owner/Admin side of the
 * lifecycle. Authorization is enforced by the `can:` route middleware (Spatie permission abilities);
 * this controller stays thin and delegates the atomic side effects to {@see TenantMembershipService}.
 */
final class MemberController extends Controller
{
    use ReadsKeywordFilter;
    use ResolvesTenant;

    public function __construct(
        private readonly TenantMembershipService $memberships,
        private readonly TwoFactorResetService $twoFactorResets,
    ) {}

    /**
     * The roster, optionally narrowed by a keyword (J1e).
     *
     * ⚠️ THE FILTER IS APPLIED IN PHP INSIDE {@see TenantMembershipService::listMembers()}, NOT IN A QUERY,
     * and that method's ⚠️ block is the required reading before touching this call. The short version: the
     * identities come off `pgsql_auth`, where there is no tenant boundary of any kind, and a user-supplied
     * predicate on that connection is a measured cross-tenant leak.
     */
    public function index(Request $request): Response
    {
        $terms = $this->keyword($request);
        $members = $this->memberships->listMembers($this->currentTenant(), $terms);

        return Inertia::render('members/Index', [
            'members' => $members,
            'assignableRoles' => AssignableRoles::options(),
            'filters' => ['applied' => ['q' => $terms->raw()]],
            'empty_reason' => ListEmptyReason::for($members !== [], ! $terms->isEmpty()),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            // Owner is deliberately not invitable — it is established only by ownership transfer (§5).
            'role' => ['required', 'string', Rule::in(AssignableRoles::values())],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->memberships->invite($this->currentTenant(), $validated['email'], $validated['role'], $actor);

        return back()
            ->with('status', 'invitation-sent')
            ->with('toast', ['type' => 'success', 'message' => "Invitation sent to {$validated['email']}"]);
    }

    /**
     * Change an active member's role (I8a, PRD Feature #14). Gated by `can:tenant.roles.assign` — a key
     * seeded to Owner/Admin since Phase 0 with no code behind it until now — plus `step-up`, so a live
     * session alone is not enough.
     *
     * The allowed values are {@see AssignableRoles}, the same list the invite form and the SSO default-role
     * picker offer, which is what keeps `owner` off all three surfaces — and, since P1a, the same expression
     * the `sso_connections_default_role_check` CHECK is compiled from. The four domain refusals (Owner's
     * role, self, no-op, non-member) live in {@see TenantMembershipService::changeRole()} — a request cannot
     * know any of them.
     */
    public function changeRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(AssignableRoles::values())],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $this->memberships->changeRole($this->currentTenant(), $user, $validated['role'], $actor);

        return back()
            ->with('status', 'member-role-changed')
            ->with('toast', ['type' => 'success', 'message' => 'Role updated']);
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->memberships->remove($this->currentTenant(), $user, $actor);

        return back()
            ->with('status', 'member-removed')
            ->with('toast', ['type' => 'success', 'message' => 'Member removed']);
    }

    /**
     * Clear a member's two-factor enrolment so somebody who has lost their device can sign in again
     * (Increment M107, answering `D37`).
     *
     * Thin, like its siblings. All four refusals — self, a super-admin target, a non-member, an account
     * that was never enrolled — live in {@see TwoFactorResetService::resetForMember()}, because a request
     * cannot know any of them. The route carries `can:tenant.members.two_factor_reset` (Owner only) and
     * `step-up`, matching the three other member mutations.
     */
    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->twoFactorResets->resetForMember($this->currentTenant(), $user, $actor);

        return back()
            ->with('status', 'member-two-factor-reset')
            ->with('toast', ['type' => 'success', 'message' => 'Two-step sign-in reset']);
    }

    public function transferOwnership(Request $request): RedirectResponse
    {
        $validated = $request->validate(['user' => ['required', 'uuid']]);

        // RLS: only a visible co-tenant member resolves. firstOrFail (not findOrFail) so the type is a
        // single User, never a Collection.
        $newOwner = User::whereKey($validated['user'])->firstOrFail();
        /** @var User $actor */
        $actor = $request->user();
        $this->memberships->transferOwnership($this->currentTenant(), $newOwner, $actor);

        return back()
            ->with('status', 'ownership-transferred')
            ->with('toast', ['type' => 'success', 'message' => 'Ownership transferred']);
    }
}
