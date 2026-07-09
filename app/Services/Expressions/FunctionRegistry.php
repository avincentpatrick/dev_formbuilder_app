<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The closed function whitelist (technical-architecture.md §4.3) — the mechanism that makes "no eval(),
 * no dynamic dispatch" provable. Two disjoint lists: PUBLIC (the parser will accept a call to it) and
 * INTERNAL (only constructible by F3 lowering / {@see AstBuilders}, never parseable — so `contains(…)`
 * as source is an `unknown_function`). The parser consults ONLY the public list.
 *
 * Phase-1 public surface is `selected/2` and nothing else; `count`, `today`, `now`, `if`, `int`, … are
 * absent by design and therefore rejected at parse time.
 */
final class FunctionRegistry
{
    /** @var array<string, int> function name → required arity */
    private const PUBLIC_FUNCTIONS = [
        'selected' => 2,
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
