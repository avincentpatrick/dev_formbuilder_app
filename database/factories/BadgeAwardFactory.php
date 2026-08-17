<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BadgeKey;
use App\Models\BadgeAward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BadgeAward>
 *
 * `tenant_id` auto-fills from the active TenantContext (BelongsToTenant), so a test must already be inside
 * `enterTenant()`. `user_id` is NOT defaulted — a badge with no member is meaningless and a generated one
 * would silently pass every "the shelf has a row" assertion without proving the attribution works. Same
 * reasoning, and the same omission, as {@see PointAwardFactory}.
 *
 * ⚠️ Unlike its sibling there is NO fresh-subject default to fall back on: the unique key is
 * `(tenant_id, user_id, badge)` with no subject in it, so two `create()` calls for one member **collide by
 * design** unless the caller varies the badge. That is the guard working, not a factory defect — a test that
 * wants two rows names two badges.
 */
class BadgeAwardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'badge' => BadgeKey::Welcome,
            'awarded_at' => now(),
        ];
    }

    /**
     * Shape the award as a different badge.
     *
     * The ONLY state, deliberately — the {@see PointAwardFactory::forRule()} posture. A factory state with
     * no caller is dead API that reads as supported; K1c and K1d add what they actually need when they do.
     */
    public function forBadge(BadgeKey $badge): static
    {
        return $this->state(fn (): array => ['badge' => $badge]);
    }
}
