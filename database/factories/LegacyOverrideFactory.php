<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LegacyOverride;
use App\Support\Migrations\LegacyOverrideBackfill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyOverride>
 *
 * `tenant_id` auto-fills from the active TenantContext (BelongsToTenant). The default grandfathers the full
 * merge-day set (the five ungated Phase-2 features); use {@see withFlags()} to shape a partial override.
 */
class LegacyOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature_flags' => LegacyOverrideBackfill::overrideFlags(),
        ];
    }

    /**
     * @param  array<string, bool>  $flags
     */
    public function withFlags(array $flags): static
    {
        return $this->state(fn (): array => ['feature_flags' => $flags]);
    }
}
