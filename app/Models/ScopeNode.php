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
     * `parent_id`, `path` and `depth` may never be written by an ordinary Eloquent save. They are
     * AUTHORIZATION INPUTS — the resolver decides access by prefix-matching `path` — so a model-level
     * write is how a request payload would graft a node onto another branch and inherit its grants.
     *
     * G10a rejected re-parenting outright because re-pathing a subtree needs locks and a cycle check that
     * did not exist yet. G10b ships them in {@see ScopeNodeService::move()}, and this guard is KEPT
     * verbatim rather than relaxed: `move()` re-paths through a single query-builder UPDATE, which fires
     * no model events, so the guard still covers every save while the one audited writer sits behind a
     * tenant-wide advisory lock, `FOR UPDATE` re-reads, a cycle check and a subtree depth check.
     *
     * That is deliberate in preference to an `unguarded`/re-entrancy flag: a flag is something a future
     * caller can switch off from anywhere, which converts a structural invariant back into a convention.
     * The invariant here reads "no model save ever touches these columns" and has no exceptions.
     *
     * Note what this means for acyclicity: `scope_nodes_no_self_parent` only ever covered the single-node
     * case, so from G10b on the ONLY thing preventing a longer cycle is `move()`'s post-lock check.
     *
     * At INSERT a node has no descendants, so no cycle check is needed there.
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
