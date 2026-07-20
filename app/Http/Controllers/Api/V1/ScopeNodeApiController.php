<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MoveScopeNodeRequest;
use App\Http\Requests\Api\V1\StoreScopeNodeRequest;
use App\Http\Requests\Api\V1\UpdateScopeNodeRequest;
use App\Http\Resources\Api\V1\ScopeNodeResource;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Scoping\ScopeNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tenant's scoping hierarchy over /api/v1 (Increment G10b). Authorization is the route's
 * `ability:manage:scopes` + the ScopeNodePolicy `can:` gates; RLS scopes every query to the token's tenant.
 */
final class ScopeNodeApiController extends Controller
{
    public function __construct(
        private readonly ScopeNodeService $nodes,
        private readonly ResourceGrantResolver $grants,
    ) {}

    /**
     * Cursor-paginated hierarchy, in tree order.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 100);

        // Ordered by `path`, so a page arrives in depth-first tree order and a client can render it
        // without re-sorting. Under COLLATE "C" that is a plain byte ordering served by the existing
        // (tenant_id, path) index.
        $nodes = ScopeNode::query()
            ->orderBy('path')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => ScopeNodeResource::collection($nodes->getCollection()),
            'meta' => [
                'next_cursor' => $nodes->nextCursor()?->encode(),
                'has_more' => $nodes->hasMorePages(),
            ],
        ]);
    }

    /**
     * Create a node.
     */
    public function store(StoreScopeNodeRequest $request): ScopeNodeResource
    {
        /** @var User $user */
        $user = $request->user();

        $parentId = $request->validated('parent_id');
        $parent = $parentId === null
            ? null
            : ScopeNode::query()->whereKey($parentId)->firstOrFail();

        return ScopeNodeResource::make(
            $this->nodes->create($parent, (string) $request->validated('name'), $request->validated(), $user),
        );
    }

    /**
     * Rename or re-label a node.
     */
    public function update(UpdateScopeNodeRequest $request, ScopeNode $scopeNode): ScopeNodeResource
    {
        $node = $this->nodes->update($scopeNode, $request->validated());

        if ($request->has('is_active')) {
            $node = $this->nodes->setActive($node, $request->boolean('is_active'));
        }

        return ScopeNodeResource::make($node);
    }

    /**
     * Re-parent a node, re-pathing its subtree.
     */
    public function move(MoveScopeNodeRequest $request, ScopeNode $scopeNode): ScopeNodeResource
    {
        $parentId = $request->validated('parent_id');
        $parent = $parentId === null
            ? null
            : ScopeNode::query()->whereKey($parentId)->firstOrFail();

        return ScopeNodeResource::make($this->nodes->move($scopeNode, $parent));
    }

    /**
     * What a grant on this node would reach, and what deleting it would take with it.
     */
    public function impact(ScopeNode $scopeNode): JsonResponse
    {
        return response()->json([
            'data' => [
                'reach' => $this->grants->nodeReach($scopeNode),
                'deletion' => $this->nodes->deletionImpact($scopeNode),
            ],
        ]);
    }

    /**
     * Delete a node, its descendants, and the grants on them.
     */
    public function destroy(ScopeNode $scopeNode): Response
    {
        $this->nodes->delete($scopeNode);

        return response()->noContent();
    }
}
