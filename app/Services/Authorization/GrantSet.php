<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\ResourceCapacity;

/**
 * One user's resolved grants within one tenant, folded into lookup-friendly shapes (Increment G10a).
 *
 * Hash maps, not lists, for the direct-form case: this sits on the authorization hot path — five
 * `can()` calls per row in the forms list — and a linear scan over a user's grants would make the page
 * cost quadratic in grant count.
 *
 * Paths are split by whether the grant cascades, because the two need different predicates: an exact
 * grant matches one node's path, a descendant grant matches any path with that prefix. Splitting here
 * means neither {@see ResourceGrantResolver::holds()} nor the list twin has to re-test the flag.
 */
final readonly class GrantSet
{
    /**
     * @param  array<string, array<string, true>>  $formIds  capacity => set of directly-granted form ids
     * @param  array<string, list<string>>  $exactNodePaths  capacity => paths granted WITHOUT descendants
     * @param  array<string, list<string>>  $prefixPaths  capacity => paths granted WITH descendants
     */
    public function __construct(
        public array $formIds = [],
        public array $exactNodePaths = [],
        public array $prefixPaths = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->formIds === [] && $this->exactNodePaths === [] && $this->prefixPaths === [];
    }

    /**
     * Flatten to the three predicate inputs the list query needs. A null capacity means "any" — the
     * capacity-agnostic rule `SubmissionPolicy` has always used for view/export.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    public function flatten(?ResourceCapacity $capacity): array
    {
        $keys = $capacity !== null ? [$capacity->value] : ResourceCapacity::values();

        $formIds = [];
        $exact = [];
        $prefixes = [];

        foreach ($keys as $key) {
            foreach (array_keys($this->formIds[$key] ?? []) as $formId) {
                $formIds[] = $formId;
            }

            foreach ($this->exactNodePaths[$key] ?? [] as $path) {
                $exact[] = $path;
            }

            foreach ($this->prefixPaths[$key] ?? [] as $path) {
                $prefixes[] = $path;
            }
        }

        return [array_values(array_unique($formIds)), array_values(array_unique($exact)), array_values(array_unique($prefixes))];
    }
}
