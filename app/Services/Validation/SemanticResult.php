<?php

declare(strict_types=1);

namespace App\Services\Validation;

/**
 * The reusable Stage-3 result (technical-architecture.md §4.1) — the whole output of semantic validation,
 * consumed by the Submission Pipeline (F4): `!passed()` maps to `422` + the errors; on success the
 * `effectiveAnswers` (relevance-pruned) are what Stage 4 persists, and `computed` carries each relevant
 * calculated field's formula result (grammar v2.0 / Increment G3) — the pipeline merges these into the
 * persisted answer document + the typed index. Immutable.
 *
 * Repeat groups (Increment G1): `effectiveAnswers` may now hold a repeatable section's pruned instances as
 * a native list under the section key (`section => [instanceMap, …]`); `repeatFieldRelevance` exposes the
 * per-instance relevance masks (`section => [mask, …]`, index-aligned with the stored instances) for the
 * G2 runtime. `fieldRelevance`/`sectionRelevance` stay flat (top-level fields + every section).
 */
final readonly class SemanticResult
{
    /**
     * @param  array<string, bool>  $fieldRelevance  top-level field key → visible/relevant
     * @param  array<string, bool>  $sectionRelevance  section key → visible/relevant
     * @param  list<SemanticError>  $errors
     * @param  array<string, mixed>  $effectiveAnswers  relevant answers only (nested per-instance for repeats); what Stage 4 persists
     * @param  array<string, mixed>  $computed  calculated field key => computed value (grammar v2.0)
     * @param  array<string, list<array<string, bool>>>  $repeatFieldRelevance  section key → per-instance field-relevance masks
     */
    public function __construct(
        public array $fieldRelevance,
        public array $sectionRelevance,
        public array $errors,
        public array $effectiveAnswers,
        public array $computed = [],
        public array $repeatFieldRelevance = [],
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
