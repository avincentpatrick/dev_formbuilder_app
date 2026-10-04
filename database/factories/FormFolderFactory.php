<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormFolder>
 *
 * `tenant_id` is auto-filled by BelongsToTenant's creating hook, so the active TenantContext decides which
 * workspace the folder belongs to.
 */
class FormFolderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
