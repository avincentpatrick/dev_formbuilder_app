<?php

declare(strict_types=1);

namespace App\Services\Scoping;

use App\Enums\ResourceScopeable;
use App\Exceptions\Scoping\ScopeNodeException;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `scope_nodes.path` and `.depth` (Increment G10a; move/update/delete added in G10b).
 *
 * Both columns are derived from `parent_id` and are AUTHORIZATION INPUTS — the resolver decides access by
 * prefix-matching `path` — so they are excluded from the model's `$fillable` and written only here.
 * Centralizing that write is what makes "a request payload cannot graft a node onto another branch and
 * inherit its grants" a structural property rather than a review convention.
 *
 * {@see ScopeNode::booted()} still hard-rejects a dirty `parent_id`/`path`/`depth` on any Eloquent SAVE.
 * G10b does not weaken it: `move()` re-paths through a query-builder statement, which fires no model
 * events, so the guard keeps covering every ordinary write while the one audited writer sits behind the
 * locking and cycle checks below. See the guard's own docblock.
 */
final class ScopeNodeService
{
    /** Mirrors the `scope_nodes_depth_max` CHECK. Kept in sync deliberately — see move(). */
    private const MAX_DEPTH = 12;

    /** Advisory-lock class id for tenant-scoped move serialization. 0x5C09 ≈ "SCOP". */
    private const MOVE_LOCK_CLASS = 0x5C09;

    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * Create a node, computing its path from the parent's.
     *
     * The uuid is minted UP FRONT — `HasUuidv7` generates client-side, which is exactly what makes a
     * SELF-INCLUSIVE path computable before the insert. A self-inclusive path is what lets the resolver
     * treat "granted on this node" and "granted on an ancestor" as one prefix comparison.
     *
     * `$parent` is resolved through the tenant-scoped model, so a node from another tenant is invisible
     * and the new node becomes a ROOT rather than silently inheriting a foreign path. The composite FK
     * would reject the write anyway; this makes the failure mode benign rather than a 500.
     *
     * @param  array<string, mixed>  $attributes  name/code/node_type/position/is_active overrides
     */
    public function create(
        ?ScopeNode $parent,
        string $name,
        array $attributes = [],
        ?User $creator = null,
    ): ScopeNode {
        // whereKey()->first() rather than find(): find()'s return type widens to a Collection when handed
        // an array, which Larastan level 8 cannot narrow here.
        $parent = $parent !== null
            ? ScopeNode::query()->whereKey($parent->getKey())->first()
            : null;

        if ($parent !== null && $parent->depth + 1 > self::MAX_DEPTH) {
            throw ScopeNodeException::depthCapExceeded($parent->depth + 1, self::MAX_DEPTH);
        }

        $node = new ScopeNode([
            'name' => $name,
            'code' => $attributes['code'] ?? null,
            'node_type' => $attributes['node_type'] ?? null,
            'position' => $attributes['position'] ?? 0,
            'is_active' => $attributes['is_active'] ?? true,
            'parent_id' => $parent?->getKey(),
            'created_by' => $creator?->getKey(),
        ]);

        // BelongsToTenant's creating hook fills tenant_id, but the id must exist before we can build the
        // self-inclusive path, so mint it here rather than waiting for the insert.
        $node->setAttribute($node->getKeyName(), $node->newUniqueId());
        $node->setAttribute('tenant_id', $node->tenant_id ?? TenantContext::currentTenantId());

        $node->forceFill([
            'path' => self::pathFor($parent, (string) $node->getKey()),
            'depth' => $parent === null ? 0 : $parent->depth + 1,
        ]);

        $node->save();

        return $node;
    }

    /**
     * Edit a node's own labels. Deliberately cannot touch `parent_id` (that is {@see self::move()}),
     * `is_active` ({@see self::setActive()}), or `path`/`depth` (derived, never authored).
     *
     * No cache invalidation: none of these columns is an authorization input.
     *
     * @param  array<string, mixed>  $attributes  name/code/node_type/position
     */
    public function update(ScopeNode $node, array $attributes): ScopeNode
    {
        $node->fill(array_intersect_key($attributes, array_flip(['name', 'code', 'node_type', 'position'])));
        $node->save();

        return $node;
    }

    /**
     * Activate or deactivate a node. Deactivation cuts off the node's ENTIRE subtree, not just the node —
     * the resolver applies that in both of its query shapes, and getting it wrong is the bug the G10a
     * end-to-end walkthrough caught.
     *
     * Clears the WHOLE resolver memo (`forget()` with no argument): `$inactive` is memoized per tenant and
     * gates every user's decisions, so a per-user `forget($id)` would leave the rest of the request
     * deciding on the old active-set. See the class docblock on ResourceGrantResolver::forget().
     */
    public function setActive(ScopeNode $node, bool $active): ScopeNode
    {
        $node->is_active = $active;
        $node->save();

        $this->grants->forget();

        return $node;
    }

    /**
     * Delete a node and its whole subtree (the composite self-FK cascades the children).
     *
     * `resource_grants` is a MORPH, so it has no FK to `scope_nodes` and nothing cascades the grants —
     * they would survive as orphans pointing at ids that no longer exist. G10a made that state *survivable*
     * on purpose (SELECT/DELETE policies stay tenant-only so an orphan is still readable and revokable),
     * but leaving them behind here would quietly accumulate authorization rows nobody can see in any UI.
     * Deleting them in the same transaction is cleanup at the site that creates them — not the orphan
     * sweeper that is deferred out of G10b.
     *
     * The grant delete uses a SUBQUERY, not a `whereIn` over collected ids: a tenant hierarchy can run to
     * tens of thousands of nodes and Postgres caps bind parameters at 65535.
     */
    public function delete(ScopeNode $node): void
    {
        DB::transaction(function () use ($node): void {
            $prefix = ResourceGrantResolver::escapeLike($node->path);

            ResourceGrant::query()
                ->where('scopeable_type', ResourceScopeable::ScopeNode->value)
                ->whereIn('scopeable_id', function (QueryBuilder $sub) use ($prefix): void {
                    $sub->select('id')->from('scope_nodes')->where('path', 'like', $prefix.'%');
                })
                ->delete();

            $node->delete();
        });

        // Paths, the active-set, and grant sets can all have changed. Clear everything.
        $this->grants->forget();
    }

    /**
     * Re-parent a node, re-pathing its entire subtree. `$newParent` of null moves it to a root.
     *
     * ── Why the tenant-wide lock ──────────────────────────────────────────────────────────────────────
     * Locking only the node and the target parent is NOT sufficient, which is worth stating because it is
     * the obvious design and it is wrong. Consider T1 = move(A under B) racing T2 = move(C under D) where
     * C is an ANCESTOR of B. The lock sets {A,B} and {C,D} are disjoint, so both proceed; T2 re-paths C's
     * subtree — which contains B — while T1 is still computing A's new prefix from the B path it read at
     * lock time. T1 then grafts A's subtree onto a stale prefix. `path` is exactly what authorization
     * prefix-matches, so a corrupted path is a corrupted permission.
     *
     * A transaction-scoped advisory lock keyed on the tenant serializes moves within a workspace. It is a
     * single lock, so there is no acquisition order to get wrong and no deadlock to avoid; moves are rare
     * administrative operations, so the coarseness is free. This is the only advisory lock in the codebase
     * — every other service serializes on a row (`forms`, `submissions`) — because this is the only
     * operation whose correctness spans an unbounded set of rows rather than one aggregate root.
     *
     * The `FOR UPDATE` re-reads below are still load-bearing on top of it: they return authoritative
     * post-lock rows and block concurrent renames/deactivations of the two nodes involved.
     */
    public function move(ScopeNode $node, ?ScopeNode $newParent): ScopeNode
    {
        return DB::transaction(function () use ($node, $newParent): ScopeNode {
            $tenantId = (string) TenantContext::currentTenantId();
            $this->lockTenantForMoves($tenantId);

            // Re-read through the tenant-scoped model AFTER the lock, and act on these rows — never on the
            // stale arguments (the house idiom: FormBuilderService::lockDraft, PublishService, RestoreService).
            // A cross-tenant target is invisible here, so it 404s rather than 500ing on the composite FK.
            $node = ScopeNode::query()->whereKey($node->getKey())->lockForUpdate()->firstOrFail();

            $target = $newParent === null
                ? null
                : ScopeNode::query()->whereKey($newParent->getKey())->lockForUpdate()->firstOrFail();

            // Self-inclusive paths make this ONE test cover both "under its own descendant" and "onto
            // itself". This — not the DB — is what enforces acyclicity now that re-parenting is reachable;
            // `scope_nodes_no_self_parent` only ever covered the single-node case.
            if ($target !== null && str_starts_with($target->path, $node->path)) {
                throw ScopeNodeException::wouldCycle();
            }

            if ($node->parent_id === $target?->getKey()) {
                return $node;
            }

            $newDepth = $target === null ? 0 : $target->depth + 1;
            $delta = $newDepth - $node->depth;

            // Measured across the whole SUBTREE: a shallow node can carry a deep one, so checking only the
            // moved node would let a move slip past into a 23514 from the depth CHECK mid-statement.
            $deepest = (int) ScopeNode::query()
                ->where('path', 'like', ResourceGrantResolver::escapeLike($node->path).'%')
                ->max('depth');

            if ($deepest + $delta > self::MAX_DEPTH) {
                throw ScopeNodeException::depthCapExceeded($deepest + $delta, self::MAX_DEPTH);
            }

            $this->rePath($node, $target, $delta, $tenantId);

            // Paths just changed for a whole subtree. This MUST be the argument-less forget(): the per-user
            // variant clears only that user's grant set and would leave the memoized form=>path map serving
            // pre-move paths for the rest of the request.
            $this->grants->forget();

            return $node->refresh();
        });
    }

    /**
     * Serialize this tenant's moves for the rest of the transaction.
     *
     * The two-argument `(classid, objid)` form with a key computed in PHP, rather than the one-argument
     * form over `hashtext()`: `hashtext` is an undocumented internal function with no stability contract
     * across major versions, and this lock is load-bearing for authorization correctness.
     *
     * The `_xact_` variant is mandatory — it releases with the transaction, so a crashed request cannot
     * strand the lock. The session-scoped `pg_advisory_lock` would be a production hang.
     *
     * crc32 folded into int4 range; a collision between two tenants only means their moves serialize
     * against each other, which is harmless for an operation this rare.
     */
    private function lockTenantForMoves(string $tenantId): void
    {
        $key = crc32('scope_nodes.move:'.$tenantId);
        $key = $key >= 2 ** 31 ? $key - 2 ** 32 : $key;

        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [self::MOVE_LOCK_CLASS, (int) $key]);
    }

    /**
     * The subtree re-path — deliberately ONE statement.
     *
     * Atomic (no window in which half the subtree is grafted and a concurrent authorization read sees a
     * mixed tree), index-assisted by `(tenant_id, path)` — a constant-prefix LIKE compiles to an indexable
     * range qual only under `COLLATE "C"`, which is why G10a chose that collation — and O(1) statements
     * regardless of subtree size. Row-by-row would be tens of thousands of model saves on a real hierarchy,
     * each one firing the `booted` guard it would then have to be exempted from.
     *
     * `substr(path, :len + 1)` keeps each descendant's tail and swaps only the moved node's prefix.
     * The `CASE`s re-parent and re-position the moved node itself in the same pass; every descendant keeps
     * its own parent and position. The node lands LAST among its new siblings rather than keeping an
     * ordinal that already belongs to one of them.
     *
     * `substr(path, ?::int)` — NOT the SQL-standard `substring(path from ?)` — is load-bearing. With an
     * untyped bind parameter Postgres resolves `substring(text from ?)` to the POSIX-REGEX overload
     * `substring(text, text)`, which returns NULL for a non-matching pattern. That would null the `path` of
     * every row it touched. Here the NOT NULL constraint turns it into a loud failure, but the same trap in
     * a nullable column is silent data loss, so both numeric binds are cast explicitly.
     */
    private function rePath(ScopeNode $node, ?ScopeNode $target, int $delta, string $tenantId): void
    {
        $position = (int) ScopeNode::query()
            ->where('parent_id', $target?->getKey())
            ->max('position') + 1;

        DB::update(
            <<<'SQL'
                UPDATE scope_nodes
                   SET path       = ? || substr(path, ?::int),
                       depth      = depth + ?::int,
                       parent_id  = CASE WHEN id = ? THEN ? ELSE parent_id END,
                       position   = CASE WHEN id = ? THEN ?::int ELSE position END,
                       updated_at = now()
                 WHERE tenant_id = ?
                   AND path LIKE ?
                SQL,
            [
                self::pathFor($target, (string) $node->getKey()),
                strlen($node->path) + 1,
                $delta,
                $node->getKey(),
                $target?->getKey(),
                $node->getKey(),
                $position,
                $tenantId,
                ResourceGrantResolver::escapeLike($node->path).'%',
            ],
        );
    }

    /**
     * What deleting this node would take with it — powers the confirmation warning (G10b).
     *
     * `forms` is the count the `SET NULL` un-scopes (they survive, they just stop being scoped anywhere),
     * `nodes` counts the descendants the FK cascade removes, and `grants` the access rows deleted with them.
     *
     * Counts through `withTrashed()`, matching {@see ResourceGrantResolver::nodeReach()} and the resolver
     * itself: one rule — every count reports what the operation actually touches — rather than a
     * per-caller judgement about which rows are interesting. A soft-deleted form really does get
     * un-scoped here, and would come back unscoped on restore.
     *
     * @return array{forms: int, nodes: int, grants: int}
     */
    public function deletionImpact(ScopeNode $node): array
    {
        $prefix = ResourceGrantResolver::escapeLike($node->path).'%';

        $subtreeIds = function (QueryBuilder $sub) use ($prefix): void {
            $sub->select('id')->from('scope_nodes')->where('path', 'like', $prefix);
        };

        return [
            'forms' => Form::withTrashed()->whereIn('scope_node_id', $subtreeIds)->count(),
            // Excludes the node itself — the warning is about what ELSE goes.
            'nodes' => ScopeNode::query()->where('path', 'like', $prefix)->count() - 1,
            'grants' => ResourceGrant::query()
                ->where('scopeable_type', ResourceScopeable::ScopeNode->value)
                ->whereIn('scopeable_id', $subtreeIds)
                ->count(),
        ];
    }

    /** '/{root}/…/{self}/' — the parent's path with this node's id appended. */
    public static function pathFor(?ScopeNode $parent, string $id): string
    {
        return ($parent === null ? '/' : $parent->path).$id.'/';
    }
}
