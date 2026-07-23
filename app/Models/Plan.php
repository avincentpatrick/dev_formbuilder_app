<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform pricing tier (data-dictionary §16, ADR-0008) — the global, RLS-exempt catalog. Deliberately
 * does NOT use {@see BelongsToTenant} and does NOT implement `TenantScoped`: `plans`
 * has no `tenant_id` at all (it is shared reference data every tenant reads), so there is no tenant scope
 * to apply — even simpler than the nullable-global {@see FormTemplate}. Assigned to tenants by a super-admin
 * (ADR-0008 §D1); a tenant's *current* plan is the plan of its active {@see Subscription}.
 *
 * `feature_flags` and `quotas` are JSONB maps validated at the application layer (there is no DB CHECK on
 * their keys) — {@see featureEnabled} and {@see quotaFor} are the read accessors.
 *
 * @property string $id
 * @property PlanTier $code
 * @property string $name
 * @property ?string $description
 * @property ?string $stripe_price_id
 * @property ?int $monthly_price_cents
 * @property ?int $yearly_price_cents
 * @property string $currency
 * @property array<int, string> $billing_interval_options
 * @property array<string, bool> $feature_flags
 * @property array<string, int|null> $quotas
 * @property bool $is_active
 * @property int $sort_order
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'code',
        'name',
        'description',
        'stripe_price_id',
        'monthly_price_cents',
        'yearly_price_cents',
        'currency',
        'billing_interval_options',
        'feature_flags',
        'quotas',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => PlanTier::class,
            'billing_interval_options' => 'array',
            'feature_flags' => 'array',
            'quotas' => 'array',
            'is_active' => 'boolean',
            'monthly_price_cents' => 'integer',
            'yearly_price_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** Whether this plan enables a feature-gate key (absent key ⇒ disabled). */
    public function featureEnabled(string $key): bool
    {
        return (bool) ($this->feature_flags[$key] ?? false);
    }

    /** This plan's quota for a metric, or null for unlimited (absent key ⇒ unlimited). */
    public function quotaFor(UsageMetric $metric): ?int
    {
        $value = $this->quotas[$metric->value] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
