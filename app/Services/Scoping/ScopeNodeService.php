<?php

declare(strict_types=1);

namespace App\Services\Scoping;

use App\Models\ScopeNode;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * The only writer of `scope_nodes.path` and `.depth` (Increment G10a).
 *
 * Both columns are derived from `parent_id` and are AUTHORIZATION INPUTS — the resolver decides access by
 * prefix-matching `path` — so they are excluded from the model's `$fillable` and set here via
 * `forceFill()`. Centralizing that write is what makes "a request payload cannot graft a node onto
 * another branch and inherit its grants" a structural property rather than a review convention.
 *
 * `move()` is deliberately absent: re-pathing a subtree needs row locks and a cycle check, and
 * {@see ScopeNode::booted()} hard-rejects a dirty `parent_id` until G10b ships that properly.
 */
final class ScopeNodeService
{
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

    /** '/{root}/…/{self}/' — the parent's path with this node's id appended. */
    public static function pathFor(?ScopeNode $parent, string $id): string
    {
        return ($parent === null ? '/' : $parent->path).$id.'/';
    }
}
