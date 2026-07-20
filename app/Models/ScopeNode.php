<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Scoping\ScopeNodeService;
use Database\Factories\ScopeNodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * One node in a tenant's own scoping hierarchy (Increment G10a) — the generic successor to the legacy
 * PSGC/catchment-area model. The platform stores and displays `name`/`code`/`node_type` but NEVER
 * interprets them: a tenant needing Philippine geography, a clinical trial site tree, or a sales
 * territory map models all three the same way (PRD.md:423).
 *
 * Strictly tenant-scoped (strict RLS). Hard deletes only — deliberately NO `SoftDeletes`, because a
 * `deleted_at` the authorization resolver must remember to filter is a landmine. Deactivation is
 * `is_active`, which {@see ResourceGrantResolver} filters in BOTH of its
 * query shapes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $parent_id
 * @property string $name
 * @property ?string $code
 * @property ?string $node_type
 * @property string $path
 * @property int $depth
 * @property int $position
 * @property bool $is_active
 * @property ?string $created_by
 */
class ScopeNode extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<ScopeNodeFactory> */
    use HasFactory;

    use HasUuidv7;

    /**
     * `path` and `depth` are deliberately absent: they are AUTHORIZATION INPUTS, derived from
     * `parent_id` and written only by {@see ScopeNodeService}. Leaving them mass-assignable would let a
     * request payload graft itself onto another branch of the tree and inherit its grants.
     */
    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'code',
        'node_type',
        'position',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'depth' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * Re-parenting is rejected outright in G10a. Moving a node must re-write `path` for its entire
     * subtree, and an unlocked check-then-act cycle guard races into mutual ancestry that corrupts
     * `path` irreversibly — and `path` is precisely what authorization reads. G10b ships `move()` with
     * `SELECT … FOR UPDATE` on both the node and the target parent, plus a concurrent-move test, and
     * must REPLACE this guard rather than bypass it.
     *
     * At INSERT a node has no descendants, so no cycle check is needed there (and the
     * `scope_nodes_no_self_parent` CHECK covers the one-node case at the database).
     */
    protected static function booted(): void
    {
        static::updating(function (self $node): void {
            if ($node->isDirty('parent_id') || $node->isDirty('path') || $node->isDirty('depth')) {
                throw new LogicException(
                    'scope_nodes: re-parenting is not supported in G10a — path/depth are authorization '
                    .'inputs and moving a node requires locked subtree re-pathing (deferred to G10b).'
                );
            }
        });
    }

    /** @return BelongsTo<ScopeNode, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ScopeNode, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Form, $this> */
    public function forms(): HasMany
    {
        return $this->hasMany(Form::class, 'scope_node_id');
    }

    /** @return MorphMany<ResourceGrant, $this> */
    public function grants(): MorphMany
    {
        return $this->morphMany(ResourceGrant::class, 'scopeable');
    }
}
