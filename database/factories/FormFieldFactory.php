<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_version_id' => FormVersion::factory(),
            'key' => 'fld_'.fake()->unique()->numerify('######'),
            'field_type' => FieldType::ShortText,
            'config' => [],
            'label' => fake()->sentence(4),
            'is_required' => RequiredMode::Optional,
            'sequence' => 0,
            'created_by' => User::factory(),
        ];
    }
}
