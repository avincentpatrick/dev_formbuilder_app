<?php

declare(strict_types=1);

namespace App\Services\Scoping;

use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use App\Enums\TenantUserStatus;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Forms\FormPresenter;

/**
 * Read model for the scoping hierarchy page (Increment G10b2). Keeps ScopeNodeController thin, mirroring
 * {@see FormPresenter}.
 *
 * The whole page is gated on `scopes.manage`, which under the shipped 5-role matrix is Owner + Admin only —
 * the same audience as /members and /forms, both of which already expose every form title and every member
 * identity in the tenant. So the counts and grantee names here disclose nothing new; listing who holds
 * access on a node IS the page's purpose.
 */
final class ScopeNodePresenter
{
    /**
     * The tree is shipped whole (see {@see tree()}), so the payload has to be bounded somewhere. 2,000 nodes
     * is comfortably above any hand-authored hierarchy and far below the ~44k-node PSGC shape the resolver
     * is designed for. A tenant past this cap gets an honest notice rather than a silently truncated tree;
     * expand-on-demand is the follow-up increment.
     */
    public const MAX_NODES = 2000;

    /**
     * @return array<string, mixed>
     */
    public function index(User $user): array
    {
        $nodes = $this->tree($user);

        return [
            'nodes' => $nodes,
            'truncated' => ScopeNode::query()->count() > self::MAX_NODES,
            'recipients' => $this->recipients($user),
            'capacities' => $this->capacities($user),
            'can' => [
                // Two DISTINCT catalog permissions that merely travel together under today's 5-role matrix.
                // Collapsing them into one flag would let a future custom role holding only `scopes.manage`
                // see a grant surface every write of which the ResourceGrantPolicy would refuse.
                'create' => $user->can('create', ScopeNode::class),
                'grant' => $user->can('create', ResourceGrant::class),
            ],
        ];
    }

    /**
     * The hierarchy as a FLAT, path-ordered list.
     *
     * `orderBy('path')` is load-bearing, not cosmetic: under `COLLATE "C"` a self-inclusive materialized path
     * sorts into depth-first pre-order, which is what lets the client compute visibility, sibling sets and
     * ancestor walks in one linear pass with no tree building. Every algorithm in ScopeTree.vue assumes it.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(User $user): array
    {
        $nodes = ScopeNode::query()
            ->orderBy('path')
            ->limit(self::MAX_NODES)
            ->get();

        if ($nodes->isEmpty()) {
            return [];
        }

        // Two grouped aggregates for the whole page, NOT a count per row — and deliberately NOT
        // ResourceGrantResolver::nodeReach(), which issues prefix-LIKE queries per node. Blast radius is an
        // on-demand, one-node-at-a-time question (the /impact sidecar); a badge is not worth O(n) LIKEs.
        $formCounts = $this->formCountsByNode();
        $grantCounts = $this->grantCountsByNode();

        // aria-setsize/aria-posinset describe the PER-PARENT sibling set — never the index in the flattened
        // visible list. That distinction is the classic flat-tree defect and no automated tool catches it, so
        // it is computed once here rather than trusted to the client.
        $setsize = [];
        foreach ($nodes as $node) {
            $key = $node->parent_id ?? '';
            $setsize[$key] = ($setsize[$key] ?? 0) + 1;
        }

        $seen = [];
        $rows = [];

        foreach ($nodes as $node) {
            $key = $node->parent_id ?? '';
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $rows[] = [
                'id' => (string) $node->id,
                'parent_id' => $node->parent_id !== null ? (string) $node->parent_id : null,
                'name' => $node->name,
                'code' => $node->code,
                'node_type' => $node->node_type,
                'depth' => $node->depth,
                'is_active' => $node->is_active,
                'has_children' => ($setsize[(string) $node->id] ?? 0) > 0,
                'setsize' => $setsize[$key],
                'posinset' => $seen[$key],
                'form_count' => $formCounts[(string) $node->id] ?? 0,
                'grant_count' => $grantCounts[(string) $node->id] ?? 0,
                'can' => [
                    'update' => $user->can('update', $node),
                    'delete' => $user->can('delete', $node),
                ],
            ];
        }

        return $rows;
    }

    /**
     * The minimal node list the form scope picker needs (Increment G10b2).
     *
     * Separate from {@see tree()} because the picker is on a different page with a different audience — it
     * needs only enough to build a breadcrumb label, and shipping the full tree row (per-node counts, `can`
     * flags) onto /forms would be a payload nobody there reads.
     *
     * @return list<array<string, mixed>>
     */
    public function pickerOptions(): array
    {
        return array_values(
            ScopeNode::query()
                ->orderBy('path')
                ->limit(self::MAX_NODES)
                ->get(['id', 'parent_id', 'name', 'is_active'])
                ->map(fn (ScopeNode $node): array => [
                    'id' => (string) $node->id,
                    'parent_id' => $node->parent_id !== null ? (string) $node->parent_id : null,
                    'name' => $node->name,
                    'is_active' => $node->is_active,
                ])
                ->all()
        );
    }

    /**
     * Grants held on one node, with identities resolved.
     *
     * Loaded for the SELECTED node only — eager-rendering every node's grantee list into page props would
     * put an unbounded, mostly-unread payload on the wire.
     *
     * @return list<array<string, mixed>>
     */
    public function grantsFor(ScopeNode $node): array
    {
        $grants = ResourceGrant::query()
            ->where('scopeable_type', ResourceScopeable::ScopeNode->value)
            ->where('scopeable_id', $node->getKey())
            ->orderBy('created_at')
            ->get();

        if ($grants->isEmpty()) {
            return [];
        }

        // Resolved from the active-member map rather than a direct `users` lookup by an id taken off the
        // grant row: `resource_grants` is a morph with no FK, so a grant can outlive its target or its
        // member. The map is derived from tenant_users (strict RLS) first — the same discipline
        // TenantMembershipService::listMembers documents for its pgsql_auth hop.
        $names = $this->memberNames();

        return array_values($grants->map(fn (ResourceGrant $grant): array => [
            'id' => (string) $grant->id,
            'user_id' => (string) $grant->user_id,
            'user_name' => $names[(string) $grant->user_id] ?? 'Former member',
            'capacity' => $grant->capacity->value,
            'includes_descendants' => $grant->includes_descendants,
            // With no `audits` table until Phase 1, this row is currently the ONLY record of who handed out
            // the access — so it is surfaced rather than dropped as incidental metadata.
            'granted_by_name' => $grant->granted_by !== null
                ? ($names[(string) $grant->granted_by] ?? 'Former member')
                : null,
            'created_at' => $grant->created_at?->toIso8601String(),
        ])->all());
    }

    /**
     * Who a grant may be handed to.
     *
     * Correct BY CONSTRUCTION rather than by filtering: the `users_visibility` RLS policy already restricts
     * this query to active co-tenants plus self, and the model's SoftDeletes scope drops closed accounts. So
     * the list is exactly the set `ResourceGrantService::grant()` will accept — minus self, which
     * `ResourceGrantPolicy::grantCapacity()` refuses. An INVITED member is not merely refused later, they are
     * invisible here: the controller's `firstOrFail()` would 404 on them, never reaching the service's
     * `assertActiveMember()` guard (which is defence in depth — see ResourceGrantServiceTest).
     *
     * @return list<array<string, string>>
     */
    private function recipients(User $user): array
    {
        $memberIds = TenantUser::query()
            ->where('status', TenantUserStatus::Active->value)
            ->pluck('user_id')
            ->all();

        if ($memberIds === []) {
            return [];
        }

        $rows = [];

        foreach (User::query()->whereIn('id', $memberIds)->whereKeyNot($user->getKey())->orderBy('name')->cursor() as $member) {
            $rows[] = [
                'id' => (string) $member->getKey(),
                'name' => (string) $member->getAttribute('name'),
                'email' => (string) $member->getAttribute('email'),
            ];
        }

        return $rows;
    }

    /**
     * The capacities this actor may hand out, each carrying whether it is allowed and why not.
     *
     * Rendered DISABLED rather than hidden. Under the shipped matrix Owner and Admin hold both
     * `forms.edit.any` and `submissions.review.any`, so nothing is ever disabled today — an option that is
     * always present and then silently vanishes for one future custom role reads as a bug, where a disabled
     * option with a reason reads as policy. `ResourceGrantPolicy::grantCapacity()` remains the enforcement
     * point regardless of what this returns.
     *
     * @return list<array<string, mixed>>
     */
    private function capacities(User $user): array
    {
        return array_map(fn (ResourceCapacity $capacity): array => [
            'value' => $capacity->value,
            'label' => ucfirst($capacity->value),
            'allowed' => $user->can($capacity->anyPermission()),
            'reason' => $user->can($capacity->anyPermission())
                ? null
                : 'You cannot grant a capacity wider than your own.',
        ], ResourceCapacity::cases());
    }

    /**
     * Forms per node, counted through `withTrashed()`.
     *
     * Deliberately the SAME filter `ResourceGrantResolver::nodeReach()` and `ScopeNodeService::deletionImpact()`
     * use, so the tree badge cannot disagree with the blast-radius modal opened from it. (This is a different
     * rule from FormPresenter::list()'s `status != archived`, which is a browsing list, not a reach count.)
     *
     * @return array<string, int>
     */
    private function formCountsByNode(): array
    {
        /** @var array<string, int> */
        return Form::withTrashed()
            ->whereNotNull('scope_node_id')
            ->groupBy('scope_node_id')
            // Aliased: pluck() reads the column off the result object by name, and `count(*)` is not a
            // usable property name.
            ->selectRaw('scope_node_id, count(*) as aggregate')
            ->pluck('aggregate', 'scope_node_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function grantCountsByNode(): array
    {
        /** @var array<string, int> */
        return ResourceGrant::query()
            ->where('scopeable_type', ResourceScopeable::ScopeNode->value)
            ->groupBy('scopeable_id')
            ->selectRaw('scopeable_id, count(*) as aggregate')
            ->pluck('aggregate', 'scopeable_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function memberNames(): array
    {
        $memberIds = TenantUser::query()->pluck('user_id')->all();

        if ($memberIds === []) {
            return [];
        }

        /** @var array<string, string> */
        return User::query()
            ->withTrashed()
            ->whereIn('id', $memberIds)
            ->pluck('name', 'id')
            ->all();
    }
}
