<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormCollaboratorCapacity;
use App\Models\Form;
use App\Models\FormCollaborator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormCollaborator>
 */
class FormCollaboratorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'user_id' => User::factory(),
            'capacity' => FormCollaboratorCapacity::Editor,
        ];
    }

    public function reviewer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'capacity' => FormCollaboratorCapacity::Reviewer,
        ]);
    }
}
