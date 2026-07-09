<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswerIndex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionAnswerIndex>
 *
 * A single typed projection row. tenant_id is auto-filled by BelongsToTenant. Defaults to a text
 * projection; the schema permits any one value_* column to carry the value per the field's data type.
 */
class SubmissionAnswerIndexFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'form_version_id' => FormVersion::factory(),
            'form_field_id' => FormField::factory(),
            'field_key' => 'fld_'.fake()->unique()->numerify('######'),
            'value_text' => fake()->word(),
        ];
    }
}
