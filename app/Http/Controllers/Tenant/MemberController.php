<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
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
    use ResolvesTenant;

    /** Roles an Admin may assign (Owner is established only via ownership transfer, §5). */
    private const ASSIGNABLE_ROLES = [
        ['value' => 'admin', 'label' => 'Admin'],
        ['value' => 'form_editor', 'label' => 'Form Editor'],
        ['value' => 'reviewer', 'label' => 'Reviewer'],
        ['value' => 'viewer', 'label' => 'Viewer'],
    ];

    public function __construct(private readonly TenantMembershipService $memberships) {}

    public function index(): Response
    {
        return Inertia::render('members/Index', [
            'members' => $this->memberships->listMembers($this->currentTenant()),
            'assignableRoles' => self::ASSIGNABLE_ROLES,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            // Owner is deliberately not invitable — it is established only by ownership transfer (§5).
            'role' => ['required', 'string', Rule::in(['admin', 'form_editor', 'reviewer', 'viewer'])],
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
     * The allowed values are ASSIGNABLE_ROLES, the same list the invite form offers, which is what keeps
     * `owner` off both surfaces. The four domain refusals (Owner's role, self, no-op, non-member) live in
     * {@see TenantMembershipService::changeRole()} — a request cannot know any of them.
     */
    public function changeRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(array_column(self::ASSIGNABLE_ROLES, 'value'))],
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
