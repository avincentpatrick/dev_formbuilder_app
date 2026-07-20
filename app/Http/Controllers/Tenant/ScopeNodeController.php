<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scoping\MoveScopeNodeRequest;
use App\Http\Requests\Scoping\StoreScopeNodeRequest;
use App\Http\Requests\Scoping\UpdateScopeNodeRequest;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Scoping\ScopeNodePresenter;
use App\Services\Scoping\ScopeNodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's scoping hierarchy (Increment G10b2) — the UI half of G10b, over a backend merged in G10b1.
 * Authorization is the route `can:` middleware against ScopeNodePolicy (every method of which is
 * `scopes.manage`); RLS scopes every query to the current tenant.
 *
 * Every MUTATION here is an Inertia visit, never a fetch. That is not stylistic: `bootstrap/app.php` keys its
 * API/web exception split on `$request->is('api/v1/*')`, and Laravel runs custom render callbacks BEFORE the
 * `shouldRenderJsonWhen` check — so a ScopeNodeException raised on a web route returns `back()->with('toast')`,
 * a 302, even to an XHR. A fetch client following that redirect gets HTML with a 200 and blows up parsing it.
 * The two read-only sidecars ({@see impact()}, {@see grants()}) are safe because they raise no domain
 * exception, and a 403/404 on them falls through to the JSON renderer.
 */
final class ScopeNodeController extends Controller
{
    public function __construct(
        private readonly ScopeNodeService $nodes,
        private readonly ScopeNodePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('scopes/Index', $this->presenter->index($user));
    }

    public function store(StoreScopeNodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $parentId = $request->validated('parent_id');
        $parent = $parentId === null
            ? null
            : ScopeNode::query()->whereKey($parentId)->firstOrFail();

        $this->nodes->create($parent, (string) $request->validated('name'), $request->validated(), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Scope added.']);
    }

    /**
     * Rename/re-label and, separately, (de)activate.
     *
     * The two-call shape is required, not incidental: ScopeNodeService::update() hard-filters its input to
     * name/code/node_type/position, so an `is_active` passed to it is silently dropped. setActive() is also
     * the arm that clears the whole resolver memo, which deactivation needs because it cuts off the entire
     * subtree.
     */
    public function update(UpdateScopeNodeRequest $request, ScopeNode $scopeNode): RedirectResponse
    {
        $node = $this->nodes->update($scopeNode, $request->validated());

        if ($request->has('is_active')) {
            $this->nodes->setActive($node, $request->boolean('is_active'));
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Scope updated.']);
    }

    /**
     * Re-parent a node, re-pathing its subtree.
     *
     * May be REFUSED (cycle, depth cap) — and that refusal arrives as a redirect-back carrying an error toast,
     * so the client must re-read the refreshed props rather than assume success.
     */
    public function move(MoveScopeNodeRequest $request, ScopeNode $scopeNode): RedirectResponse
    {
        $parentId = $request->validated('parent_id');
        $parent = $parentId === null
            ? null
            : ScopeNode::query()->whereKey($parentId)->firstOrFail();

        $this->nodes->move($scopeNode, $parent);

        return back()->with('toast', ['type' => 'success', 'message' => 'Scope moved.']);
    }

    public function destroy(ScopeNode $scopeNode): RedirectResponse
    {
        $this->nodes->delete($scopeNode);

        return back()->with('toast', ['type' => 'success', 'message' => 'Scope deleted.']);
    }

    /**
     * Read-only sidecar: what a grant here would reach, and what deleting it would take with it.
     *
     * Bare payload with no `data` envelope, matching the builder's `forms.library-items` sidecar this mirrors.
     */
    public function impact(ScopeNode $scopeNode, ResourceGrantResolver $grants): JsonResponse
    {
        return response()->json([
            'reach' => $grants->nodeReach($scopeNode),
            'deletion' => $this->nodes->deletionImpact($scopeNode),
        ]);
    }

    /**
     * Read-only sidecar: the grants held on one node. Loaded on selection rather than shipped for every node.
     */
    public function grants(ScopeNode $scopeNode): JsonResponse
    {
        return response()->json([
            'grants' => $this->presenter->grantsFor($scopeNode),
        ]);
    }
}
