<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResourceCapacity;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\ResourceGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One per-instance access grant (Increment G10a, multi-tenancy-rbac-design.md §8) — the polymorphic
 * successor to `form_collaborators`. Holding the Form Editor or Reviewer role tenant-wide grants nothing
 * by itself; a row here is what scopes that capability class to a specific resource.
 *
 * `scopeable` is the repo's SECOND real `morphTo` (after {@see Attachment}), resolved through the morph
 * map registered in AppServiceProvider — never a hand-rolled switch on a type column, which is the
 * anti-pattern technical-architecture.md §5 row 11 forbids. Targets are `form` (direct access) and
 * `scope_node` (every form on that node, plus descendants when `includes_descendants` is set).
 *
 * Strictly tenant-scoped, but NOT the plain strict RLS shape: a morph has no database foreign key, so
 * `TenantIsolation::resourceGrantGuard` adds a per-alias same-tenant EXISTS to the write policies. Hard
 * deletes only (revoke means gone), matching the table it replaces.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scopeable_type
 * @property string $scopeable_id
 * @property string $user_id
 * @property ResourceCapacity $capacity
 * @property bool $includes_descendants
 * @property ?string $granted_by
 */
class ResourceGrant extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<ResourceGrantFactory> */
    use HasFactory;

    use HasUuidv7;

    /**
     * `scopeable_type`, `scopeable_id`, `granted_by` and `tenant_id` are EXCLUDED on purpose: a request
     * payload must never be able to mass-assign a raw morph pair, which is the whole cross-tenant attack
     * surface here. Every writer goes through `->scopeable()->associate($model)` with a real
     * tenant-scoped instance, so the alias comes from the morph map rather than a caller-supplied string.
     *
     * `tenant_id` still auto-fills: BelongsToTenant's `creating` hook uses setAttribute(), which bypasses
     * `$fillable`. Factories are likewise unaffected — Factory::makeInstance wraps construction in
     * Model::unguarded.
     */
    protected $fillable = [
        'user_id',
        'capacity',
        'includes_descendants',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => ResourceCapacity::class,
            'includes_descendants' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function scopeable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
