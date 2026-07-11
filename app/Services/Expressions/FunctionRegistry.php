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
 * `now/0` (the injected clock). Any other name (`sum`, `regex`, …) stays an `unknown_function`.
 */
final class FunctionRegistry
{
    /** @var array<string, int> function name → required arity */
    private const PUBLIC_FUNCTIONS = [
        'selected' => 2,
        'if' => 3,
        'count' => 1,
        'int' => 1,
        'today' => 0,
        'now' => 0,
    ];

    /** @var array<string, int> lowering-only function name → required arity */
    private const INTERNAL_FUNCTIONS = [
        'contains' => 2,
    ];

    public function isPublic(string $name): bool
    {
        return array_key_exists($name, self::PUBLIC_FUNCTIONS);
    }

    public function publicArity(string $name): ?int
    {
        return self::PUBLIC_FUNCTIONS[$name] ?? null;
    }

    public function isKnown(string $name): bool
    {
        return array_key_exists($name, self::PUBLIC_FUNCTIONS)
            || array_key_exists($name, self::INTERNAL_FUNCTIONS);
    }
}
