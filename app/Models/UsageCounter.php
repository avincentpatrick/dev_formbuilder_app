<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageMetric;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScoped;
use Database\Factories\UsageCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A metering row for one `(tenant, metric, period)` window (data-dictionary §18, ADR-0008). Strictly
 * tenant-scoped via {@see BelongsToTenant}. Deliberately does NOT use `HasUuidv7`: the PK is a bigint
 * identity — these are internal aggregation rows never addressed externally (data-dictionary PK strategy).
 *
 * H5a ships the read side; the increment/rollup logic is H5b (a cross-tenant MaintenanceJob per ADR-0007
 * §D3), so counters read as 0 until then. `limit_snapshot` is the quota that applied at the row's last
 * update, denormalized so historical usage reporting survives a later plan-quota change.
 *
 * @property int $id
 * @property string $tenant_id
 * @property ?string $subscription_id
 * @property UsageMetric $metric
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $value
 * @property ?int $limit_snapshot
 * @property ?Carbon $last_incremented_at
 */
class UsageCounter extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<UsageCounterFactory> */
    use HasFactory;

    /**
     * `tenant_id` is EXCLUDED — BelongsToTenant auto-fills it (bypassing $fillable). The rest is what the
     * H5b metering upsert writes.
     */
    protected $fillable = [
        'subscription_id',
        'metric',
        'period_start',
        'period_end',
        'value',
        'limit_snapshot',
        'last_incremented_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => UsageMetric::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'value' => 'integer',
            'limit_snapshot' => 'integer',
            'last_incremented_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
