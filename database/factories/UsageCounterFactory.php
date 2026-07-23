<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageMetric;
use App\Models\UsageCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageCounter>
 *
 * `tenant_id` auto-fills from the active TenantContext (BelongsToTenant). The default is a zeroed
 * submissions counter for the current calendar month; use {@see forMetric()}/{@see withValue()} to shape it.
 */
class UsageCounterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => null,
            'metric' => UsageMetric::SubmissionsCount,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'value' => 0,
            'limit_snapshot' => null,
            'last_incremented_at' => null,
        ];
    }

    public function forMetric(UsageMetric $metric): static
    {
        return $this->state(fn (): array => ['metric' => $metric]);
    }

    public function withValue(int $value): static
    {
        return $this->state(fn (): array => [
            'value' => $value,
            'last_incremented_at' => now(),
        ]);
    }
}
