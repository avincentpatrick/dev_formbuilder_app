<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Notifications\NotificationDispatcher;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One in-app notification for one person (data-dictionary §22, PRD Feature #13, Increment I3).
 *
 * NOT `Illuminate\Notifications\Notification` — the framework's database channel is deliberately unused.
 * Rows here are raised from post-commit domain events through {@see NotificationDispatcher}, never from a
 * controller and never from `$user->notify()`, so that a submission's notification and its webhook fire
 * from ONE event rather than two divergent write paths (§22 design note, technical-architecture §4.1).
 * Every mail class under `App\Notifications` is the framework kind; this is the durable in-app record.
 *
 * `strict` RLS scopes every query to the TENANT, not to the recipient — see the migration docblock for why
 * `belongs_to_user` is forbidden. "Mine" is therefore the application predicate {@see scopeForUser()},
 * which every read path must apply.
 *
 * `data` carries identifiers and display labels only. **Never a token or a signed URL** — under the strict
 * policy these rows are readable by every member of the tenant, and nothing redacts them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property NotificationType $type
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $emailed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Notification extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'data',
        'read_at',
        'emailed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'emailed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The per-user predicate RLS does not supply — see the class docblock. Applied on every read path,
     * including the unread count I4's bell polls for.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
