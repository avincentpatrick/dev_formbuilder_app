<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScopeNode;
use App\Services\Scoping\ScopeNodeService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScopeNode>
 *
 * `path` and `depth` are non-fillable authorization inputs, so they are computed in `afterMaking()` using
 * the SAME {@see ScopeNodeService::pathFor()} the production writer uses — a factory that derived them
 * independently would let the two drift and quietly invalidate every resolver test built on it.
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook (unlike the nullable-global factories),
 * so the active TenantContext is what decides ownership.
 */
class ScopeNodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'code' => null,
            'node_type' => fake()->randomElement(['region', 'province', 'district', 'site']),
            'parent_id' => null,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ScopeNode $node): void {
            // The id must exist before a self-inclusive path can be built (HasUuidv7 mints client-side).
            if ($node->getKey() === null) {
                $node->setAttribute($node->getKeyName(), $node->newUniqueId());
            }

            $parent = $node->parent_id !== null ? ScopeNode::query()->find($node->parent_id) : null;

            $node->forceFill([
                'path' => ScopeNodeService::pathFor($parent, (string) $node->getKey()),
                'depth' => $parent === null ? 0 : $parent->depth + 1,
            ]);
        });
    }

    /** A child of an existing node — inherits its path prefix and depth + 1. */
    public function childOf(ScopeNode $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }

    /** A deactivated node. The resolver must treat grants on it as granting nothing. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
