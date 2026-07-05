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

/**
 * Tenant member administration (multi-tenancy-rbac-design.md §7) — the Owner/Admin side of the
 * lifecycle. Authorization is enforced by the `can:` route middleware (Spatie permission abilities);
 * this controller stays thin and delegates the atomic side effects to {@see TenantMembershipService}.
 */
final class MemberController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly TenantMembershipService $memberships) {}

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

        return back()->with('status', 'invitation-sent');
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->memberships->remove($this->currentTenant(), $user, $actor);

        return back()->with('status', 'member-removed');
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

        return back()->with('status', 'ownership-transferred');
    }
}
