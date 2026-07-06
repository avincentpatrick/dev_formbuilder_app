<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 *
 * tenant_id is intentionally not set here — BelongsToTenant auto-fills it from the active
 * TenantContext, so callers create forms inside an applyLocal() context (as the RLS write policy
 * requires anyway).
 */
class FormFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'status' => FormStatus::Draft,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'capability_flags' => [],
            'owner_user_id' => User::factory(),
            'created_by' => User::factory(),
        ];
    }
}
