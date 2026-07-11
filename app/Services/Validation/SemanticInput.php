<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use Illuminate\Support\Collection;

/**
 * The pure boundary DTO for {@see SemanticValidator::evaluate()} (technical-architecture.md §4.1 §4.3) —
 * a published version's schema (already loaded) + the respondent's raw answer map + the locale. Decoupling
 * the validator from Eloquent lets the golden-vector runner drive it from unsaved models with no database,
 * the same files the Phase-1 TypeScript engine will run (the validation drift contract, Risk R3).
 */
final readonly class SemanticInput
{
    /**
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @param  Collection<int, FormFieldValidation>  $validations
     * @param  array<string, mixed>  $answers  raw (un-pruned) answers; field key => value, plus (Increment G1)
     *                                         repeatable-section key => list<field key => value> for repeat instances
     * @param  ?string  $now  the injected clock (ISO-8601) that `today()`/`now()` read (Increment G3); the
     *                        caller stamps it (server time in the pipeline, a fixed value in golden vectors)
     */
    public function __construct(
        public Collection $fields,
        public Collection $sections,
        public Collection $validations,
        public array $answers,
        public string $locale = 'en',
        public ?string $now = null,
    ) {}
}
