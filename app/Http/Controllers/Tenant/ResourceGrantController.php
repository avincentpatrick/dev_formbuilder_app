<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\ResourceCapacity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scoping\StoreResourceGrantRequest;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\User;
use App\Policies\ResourceGrantPolicy;
use App\Services\Authorization\ResourceGrantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Handing out (and revoking) access on a scope node from the tenant-web surface (Increment G10b2).
 *
 * The route's `can:create,ResourceGrant` is the BASE gate (`forms.collaborators.manage`). The escalation rules
 * — no self-grant, and no granting a capacity wider than your own — live in
 * {@see ResourceGrantPolicy::grantCapacity()} and are checked here, per grant, because they
 * depend on the capacity and the recipient rather than on the actor alone. That check is the enforcement
 * point; the presenter's recipient/capacity filtering is only there so the UI cannot ask for a refusal.
 *
 * Revoke gates on `delete` rather than `grantCapacity`: de-escalation is always safe, including revoking a
 * grant you did not issue.
 */
final class ResourceGrantController extends Controller
{
    public function __construct(private readonly ResourceGrantService $grants) {}

    public function store(StoreResourceGrantRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $capacity = ResourceCapacity::from((string) $request->validated('capacity'));

        // Both resolved through the TENANT-SCOPED models, so a foreign id 404s rather than reaching the
        // service. For `user_id` that also means an invited or removed member never gets here: the
        // `users_visibility` RLS policy admits only ACTIVE co-tenants plus self.
        $recipient = User::query()->whereKey($request->validated('user_id'))->firstOrFail();
        $node = ScopeNode::query()->whereKey($request->validated('scope_node_id'))->firstOrFail();

        Gate::forUser($actor)->authorize('grantCapacity', [ResourceGrant::class, $capacity, $recipient]);

        $this->grants->grant(
            $actor,
            $recipient,
            $node,
            $capacity,
            $request->boolean('includes_descendants'),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Access granted.']);
    }

    public function destroy(ResourceGrant $resourceGrant): RedirectResponse
    {
        $this->grants->revoke($resourceGrant);

        return back()->with('toast', ['type' => 'success', 'message' => 'Access revoked.']);
    }
}
