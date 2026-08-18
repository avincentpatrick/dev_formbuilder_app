<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PointRule;
use App\Models\PointAward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PointAward>
 *
 * `tenant_id` auto-fills from the active TenantContext (BelongsToTenant), so a test must already be inside
 * `enterTenant()`. `user_id` is NOT defaulted — an award with no member is meaningless and a generated one
 * would silently pass every "the ladder has a row" assertion without proving the attribution works.
 *
 * The default is one collected response, awarded now, against a fresh subject — so two `create()` calls
 * cannot collide on `point_awards_tenant_user_rule_subject_unique` and a test that wanted two rows gets two.
 */
class PointAwardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule' => PointRule::SubmissionCollected,
            'points' => PointRule::SubmissionCollected->points(),
            'subject_type' => 'submission',
            'subject_id' => (string) Str::uuid7(),
            'awarded_at' => now(),
        ];
    }

    /**
     * Shape the award as a different rule, carrying that rule's own weight.
     *
     * The ONLY state, deliberately. A factory state with no caller is dead API that reads as supported —
     * K1b and K1c add what they actually need when they need it.
     */
    public function forRule(PointRule $rule): static
    {
        return $this->state(fn (): array => [
            'rule' => $rule,
            'points' => $rule->points(),
        ]);
    }
}
