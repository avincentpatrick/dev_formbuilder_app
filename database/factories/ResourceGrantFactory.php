<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceGrant>
 *
 * The morph pair is excluded from the model's `$fillable`, but `Factory::makeInstance` wraps construction
 * in `Model::unguarded`, so the `forForm()`/`forNode()` states below can set it directly. Production code
 * must still go through `->scopeable()->associate()`.
 *
 * There is no default target: a grant with no scopeable is meaningless and would violate the RLS guard,
 * so a caller must pick a state. `tenant_id` auto-fills from the active TenantContext.
 */
class ResourceGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'capacity' => ResourceCapacity::Editor,
            'includes_descendants' => false,
        ];
    }

    public function reviewer(): static
    {
        return $this->state(fn (): array => ['capacity' => ResourceCapacity::Reviewer]);
    }

    /** A direct grant on one form — the shape every backfilled row has. */
    public function forForm(Form $form): static
    {
        return $this->state(fn (): array => [
            'scopeable_type' => ResourceScopeable::Form->value,
            'scopeable_id' => $form->getKey(),
        ]);
    }

    /**
     * A grant on a hierarchy node. `$descendants` decides whether it covers only forms assigned to THIS
     * node or the whole subtree beneath it — the distinction the `includes_descendants` column exists for.
     */
    public function forNode(ScopeNode $node, bool $descendants = false): static
    {
        return $this->state(fn (): array => [
            'scopeable_type' => ResourceScopeable::ScopeNode->value,
            'scopeable_id' => $node->getKey(),
            'includes_descendants' => $descendants,
        ]);
    }
}
