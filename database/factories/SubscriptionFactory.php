<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 *
 * `tenant_id` auto-fills from the active TenantContext (BelongsToTenant), so a plain
 * `Subscription::factory()->create()` under an applyLocal() context satisfies the strict INSERT policy.
 * The default is a live `default`-slot subscription on a freshly-minted Free plan; pass an existing/shared
 * plan with {@see forPlan()} when several subscriptions must reference one plan (the `plans.code` unique
 * key forbids two Free plans).
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'name' => 'default',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'stripe_status' => 'active',
            'billing_interval' => BillingInterval::Monthly,
            'quantity' => 1,
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn (): array => ['plan_id' => $plan->getKey()]);
    }

    public function status(string $stripeStatus): static
    {
        return $this->state(fn (): array => ['stripe_status' => $stripeStatus]);
    }

    /** A subscription that is no longer live (canceled and ended) — excluded by {@see Subscription::scopeActive}. */
    public function ended(): static
    {
        return $this->state(fn (): array => [
            'stripe_status' => 'canceled',
            'ended_at' => now(),
        ]);
    }
}
