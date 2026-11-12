<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\ExplorerChartPolicy;
use App\Support\Explorer\ExplorerQuery;
use Database\Factories\ExplorerChartFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A chart a member built and kept on `/explore` (M236, ADR-0025 §D8).
 *
 * `definition` is an {@see ExplorerQuery} payload: a declaration resolved every time the chart is opened,
 * as the member opening it. `strict` RLS scopes the table to the TENANT only, so whose chart it is and who
 * else may see it are application predicates — {@see scopeVisibleTo()} on every list read and
 * {@see ExplorerChartPolicy} on every single-row action.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property bool $is_shared
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ExplorerChart extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<ExplorerChartFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'definition',
        'is_shared',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The charts a member's board lists: their own, and the ones a colleague shared with the workspace.
     *
     * @param  Builder<ExplorerChart>  $query
     * @return Builder<ExplorerChart>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('user_id', $user->getKey())->orWhere('is_shared', true);
        });
    }

    /**
     * @param  Builder<ExplorerChart>  $query
     * @return Builder<ExplorerChart>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
