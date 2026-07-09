<?php

declare(strict_types=1);

namespace App\Services\Validation;

/**
 * The reusable Stage-3 result (technical-architecture.md §4.1) — the whole output of semantic validation,
 * consumed by the Submission Pipeline (F4): `!passed()` maps to `422` + the errors; on success the
 * `effectiveAnswers` (relevance-pruned) are what Stage 4 persists, and `computed` carries calculated
 * values (Phase 1: always empty — `calculate` needs the Phase-2 arithmetic grammar). Immutable.
 */
final readonly class SemanticResult
{
    /**
     * @param  array<string, bool>  $fieldRelevance  field key → visible/relevant
     * @param  array<string, bool>  $sectionRelevance  section key → visible/relevant
     * @param  list<SemanticError>  $errors
     * @param  array<string, mixed>  $effectiveAnswers  answers of relevant fields only (what Stage 4 persists)
     * @param  array<string, mixed>  $computed  computed `calculate` values (Phase 1: empty)
     */
    public function __construct(
        public array $fieldRelevance,
        public array $sectionRelevance,
        public array $errors,
        public array $effectiveAnswers,
        public array $computed = [],
    ) {}

    public function passed(): bool
    {
        return $this->errors === [];
    }

    /** @return list<SemanticError> */
    public function errorsFor(string $fieldKey): array
    {
        return array_values(array_filter(
            $this->errors,
            static fn (SemanticError $error): bool => $error->fieldKey === $fieldKey,
        ));
    }
}
