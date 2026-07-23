<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's subscription to a plan (data-dictionary §17, ADR-0008). Strictly tenant-scoped (plain strict
 * RLS), so it uses {@see BelongsToTenant} — the ORM scope + `tenant_id` auto-fill on create.
 *
 * `stripe_status` is deliberately UNCAST free text (see the migration): it mirrors Stripe's own vocabulary,
 * which the app must not constrain. The tenant's current plan is the plan of its ACTIVE subscription —
 * {@see scopeActive} defines "active" once so `EntitlementService` and any future consumer agree.
 * Admin-assigned in Phase 3 (no Cashier); the Stripe id columns are dormant until Phase 4.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $name
 * @property ?string $stripe_customer_id
 * @property ?string $stripe_subscription_id
 * @property string $stripe_status
 * @property BillingInterval $billing_interval
 * @property int $quantity
 * @property ?Carbon $trial_ends_at
 * @property ?Carbon $current_period_starts_at
 * @property ?Carbon $current_period_ends_at
 * @property ?Carbon $cancels_at
 * @property ?Carbon $canceled_at
 * @property ?Carbon $ended_at
 */
class Subscription extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasUuidv7;

    /** The `stripe_status` values that count as a live subscription (ADR-0008 §D2). */
    public const array ACTIVE_STATUSES = ['active', 'trialing'];

    /**
     * `tenant_id` is EXCLUDED — BelongsToTenant auto-fills it via setAttribute() (bypassing $fillable), so a
     * request payload can never mass-assign a foreign tenant. Everything else that admin-assign or a future
     * Cashier sync writes is listed.
     */
    protected $fillable = [
        'plan_id',
        'name',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_status',
        'billing_interval',
        'quantity',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancels_at',
        'canceled_at',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_interval' => BillingInterval::class,
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancels_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            // stripe_status intentionally uncast — free text (see class docblock).
        ];
    }

    /**
     * Live subscriptions: a current Stripe status and not yet ended. The single definition of "active".
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('stripe_status', self::ACTIVE_STATUSES)
            ->whereNull('ended_at');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
