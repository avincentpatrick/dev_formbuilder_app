<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Notifications\NotificationPreferenceResolver;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's channel choice for one notification type in one tenant (data-dictionary §23, Increment I3).
 *
 * **A row exists only where someone has diverged from the platform default.** Absence is not "off" and not
 * "unknown" — it is the default, resolved from {@see NotificationType} at read time by
 * {@see NotificationPreferenceResolver}. Nothing seeds rows when a user joins a tenant; anything that
 * iterates this table to answer "what are this user's preferences" is asking the wrong object.
 *
 * `strict` RLS, not `belongs_to_user`: §23 lets one person hold different preferences in two tenants, which
 * is exactly the case the tenant-less `belongs_to_user` policies would collapse. Per-user scoping is
 * {@see scopeForUser()}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property NotificationType $notification_type
 * @property bool $in_app_enabled
 * @property bool $email_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationPreference extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'notification_type',
        'in_app_enabled',
        'email_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_type' => NotificationType::class,
            'in_app_enabled' => 'boolean',
            'email_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<NotificationPreference>  $query
     * @return Builder<NotificationPreference>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
