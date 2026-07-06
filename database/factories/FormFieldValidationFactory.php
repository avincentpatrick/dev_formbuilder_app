<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ValidationRuleType;
use App\Models\FormFieldValidation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormFieldValidation>
 *
 * Defaults to a structured (non-expression) rule so the `expression XOR rule_type` CHECK passes. The
 * caller supplies form_version_id + form_field_id so the rule and its field share one version (the
 * pre-publish validation gate, D2, enforces that invariant at the app layer).
 */
class FormFieldValidationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rule_type' => ValidationRuleType::MinLength,
            'rule_value' => '1',
            'sequence' => 0,
        ];
    }

    public function expression(string $expression = '. != \'\''): static
    {
        return $this->state(fn (array $attributes): array => [
            'rule_type' => null,
            'rule_value' => null,
            'expression' => $expression,
        ]);
    }
}
