<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The closed function whitelist (technical-architecture.md §4.3) — the mechanism that makes "no eval(),
 * no dynamic dispatch" provable. Two disjoint lists: PUBLIC (the parser will accept a call to it) and
 * INTERNAL (only constructible by F3 lowering / {@see AstBuilders}, never parseable — so `contains(…)`
 * as source is an `unknown_function`). The parser consults ONLY the public list.
 *
 * Grammar v2.0 (Increment G3) opens the public surface to the function library: `selected/2` (membership),
 * `if/3` (conditional value), `count/1` (array/instance count), `int/1` (truncating cast), `today/0` and
 * `now/0` (the injected clock). Any other name (`regex`, …) stays an `unknown_function`.
 *
 * M180 (`R-da02fe03`, `D155`, `D156`) adds the date and number library — `date_diff/3`, `date_add/3`, `age/1`,
 * `round/2`, `coalesce/2`, and the variadic `sum/1+` and `concat/1+` — so an arity is now a range: `[min, max]`, a
 * null max meaning "any number from min". `tests/Unit/Expressions/FunctionRegistryMirrorTest.php` holds the
 * TypeScript twin to this list, name for name.
 */
final class FunctionRegistry
{
    /** @var array<string, array{0: int, 1: ?int}> function name → [min, max] arguments (null max: no upper bound) */
    private const PUBLIC_FUNCTIONS = [
        'selected' => [2, 2],
        'if' => [3, 3],
        'count' => [1, 1],
        'int' => [1, 1],
        'today' => [0, 0],
        'now' => [0, 0],
        'date_diff' => [3, 3],
        'date_add' => [3, 3],
        'age' => [1, 1],
        'round' => [2, 2],
        'sum' => [1, null],
        'coalesce' => [2, 2],
        'concat' => [1, null],
    ];

    /** @var array<string, int> lowering-only function name → required arity */
    private const INTERNAL_FUNCTIONS = [
        'contains' => 2,
    ];

    public function isPublic(string $name): bool
    {
        return array_key_exists($name, self::PUBLIC_FUNCTIONS);
    }

    /** @return array{0: int, 1: ?int}|null [min, max] arguments, or null for a name the parser does not accept */
    public function arity(string $name): ?array
    {
        return self::PUBLIC_FUNCTIONS[$name] ?? null;
    }

    /** @return array<string, array{0: int, 1: ?int}> every public function with its [min, max] arguments */
    public function publicFunctions(): array
    {
        return self::PUBLIC_FUNCTIONS;
    }

    public function isKnown(string $name): bool
    {
        return array_key_exists($name, self::PUBLIC_FUNCTIONS)
            || array_key_exists($name, self::INTERNAL_FUNCTIONS);
    }
}
