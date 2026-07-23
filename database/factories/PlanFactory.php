<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanTier;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 *
 * `plans` has no tenant_id and no RLS, so a plan can be created on the ordinary connection with no context.
 * `code` is unique + CHECK-constrained, so the default is the `free` tier; a test needing several plans
 * picks distinct tiers via {@see tier()}. Real production rows come from PlanSeeder, not this factory.
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => PlanTier::Free,
            'name' => 'Free',
            'description' => fake()->optional()->sentence(),
            'stripe_price_id' => null,
            'monthly_price_cents' => null,
            'yearly_price_cents' => null,
            'currency' => 'usd',
            'billing_interval_options' => ['monthly', 'yearly'],
            'feature_flags' => [],
            'quotas' => [],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function tier(PlanTier $tier): static
    {
        return $this->state(fn (): array => [
            'code' => $tier,
            'name' => ucfirst($tier->value),
        ]);
    }

    /**
     * @param  array<string, bool>  $flags
     */
    public function withFeatures(array $flags): static
    {
        return $this->state(fn (): array => ['feature_flags' => $flags]);
    }

    /**
     * @param  array<string, int|null>  $quotas
     */
    public function withQuotas(array $quotas): static
    {
        return $this->state(fn (): array => ['quotas' => $quotas]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
