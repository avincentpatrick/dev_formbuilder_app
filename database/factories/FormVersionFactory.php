<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 *
 * Defaults to a v1 draft with an empty snapshot. tenant_id is auto-filled by BelongsToTenant under the
 * active context. Use the published()/superseded() states to move a version through its lifecycle.
 */
class FormVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'version_number' => 1,
            'status' => FormVersionStatus::Draft,
            'title' => fake()->sentence(3),
            'schema_snapshot' => [],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FormVersionStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FormVersionStatus::Superseded,
            'published_at' => now()->subDay(),
            'superseded_at' => now(),
        ]);
    }
}
