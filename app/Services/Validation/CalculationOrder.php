<?php

declare(strict_types=1);

namespace App\Services\Validation;

/**
 * The order a form's calculations are worked out in (M180, `R-39dbce5b`, `D149`) — the mirror of
 * `resources/public-runtime/engine/calculation-order.ts`, pinned by `tests/fixtures/calculation-order.json`.
 *
 * A calculation depends on every other calculation its formula names. Sweeps run over the calculations in field
 * order, taking each one whose dependencies are all worked out — one taken earlier in the same sweep counts — until
 * a sweep takes nothing. What is never taken is never worked out and reads as blank: a self-reference, every member
 * of a cycle, and anything downstream of one. Publish refuses the first two (`calculation_cycle`); this is what a
 * version published before that refusal does. A calculation with no formula counts as worked out, with no value.
 */
final class CalculationOrder
{
    /**
     * @param  list<array{key: string, formula: ?string}>  $calculations  in field order
     * @param  callable(string): list<string>  $references  the keys a formula names
     * @return list<string> the keys to work out, in order
     */
    public static function order(array $calculations, callable $references): array
    {
        /** @var array<string, true> $calculationKeys */
        $calculationKeys = [];
        foreach ($calculations as $calculation) {
            $calculationKeys[$calculation['key']] = true;
        }

        /** @var array<string, true> $resolved */
        $resolved = [];
        /** @var array<string, list<string>> $pending */
        $pending = [];
        foreach ($calculations as $calculation) {
            if ($calculation['formula'] === null) {
                $resolved[$calculation['key']] = true;

                continue;
            }

            $pending[$calculation['key']] = array_values(array_filter(
                $references($calculation['formula']),
                static fn (string $key): bool => isset($calculationKeys[$key]),
            ));
        }

        $order = [];
        do {
            $progress = false;
            foreach ($pending as $key => $dependencies) {
                foreach ($dependencies as $dependency) {
                    if (! isset($resolved[$dependency])) {
                        continue 2;
                    }
                }

                $resolved[$key] = true;
                $order[] = $key;
                unset($pending[$key]);
                $progress = true;
            }
        } while ($progress);

        return $order;
    }
}
