<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreResourceGrantRequest;
use App\Http\Resources\Api\V1\ResourceGrantResource;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Authorization\ResourceGrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Granting and revoking access over /api/v1 (Increment G10b) — the write surface that makes the Reviewer
 * role assignable, and the first consumer of `forms.collaborators.manage` since it was defined.
 *
 * Two authorization layers, both required. The route carries `ability:manage:scopes` +
 * `can:viewAny|create|delete` on ResourceGrantPolicy; `store()` then adds the `grantCapacity` check,
 * because the no-self-grant and anti-amplification rules depend on the capacity and the recipient, which
 * route middleware cannot bind.
 */
final class ResourceGrantApiController extends Controller
{
    public function __construct(private readonly ResourceGrantService $service) {}

    /**
     * List grants, optionally filtered by target or recipient.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $grants = ResourceGrant::query()
            ->when($request->filled('scopeable_type'), fn ($q) => $q->where('scopeable_type', $request->string('scopeable_type')))
            ->when($request->filled('scopeable_id'), fn ($q) => $q->where('scopeable_id', $request->string('scopeable_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => ResourceGrantResource::collection($grants->getCollection()),
            'meta' => [
                'next_cursor' => $grants->nextCursor()?->encode(),
                'has_more' => $grants->hasMorePages(),
            ],
        ]);
    }

    /**
     * Grant a capacity on a form or scope node, replacing any capacity the recipient already holds there.
     */
    public function store(StoreResourceGrantRequest $request): ResourceGrantResource
    {
        /** @var User $actor */
        $actor = $request->user();

        $capacity = ResourceCapacity::from((string) $request->validated('capacity'));
        $recipient = User::query()->whereKey($request->validated('user_id'))->firstOrFail();

        Gate::forUser($actor)->authorize('grantCapacity', [ResourceGrant::class, $capacity, $recipient]);

        $grant = $this->service->grant(
            $actor,
            $recipient,
            $this->resolveTarget($request),
            $capacity,
            $request->boolean('includes_descendants'),
        );

        return ResourceGrantResource::make($grant);
    }

    /**
     * Revoke a grant.
     */
    public function destroy(ResourceGrant $resourceGrant): Response
    {
        $this->service->revoke($resourceGrant);

        return response()->noContent();
    }

    /**
     * Resolve the morph target through its own tenant-scoped model, so a cross-tenant id 404s here rather
     * than reaching the service as an unvalidated pair.
     */
    private function resolveTarget(StoreResourceGrantRequest $request): Form|ScopeNode
    {
        $alias = ResourceScopeable::from((string) $request->validated('scopeable_type'));
        $id = $request->validated('scopeable_id');

        return match ($alias) {
            ResourceScopeable::Form => Form::query()->whereKey($id)->firstOrFail(),
            ResourceScopeable::ScopeNode => ScopeNode::query()->whereKey($id)->firstOrFail(),
        };
    }
}
