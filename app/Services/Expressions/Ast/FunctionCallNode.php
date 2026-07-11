<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\NodeKind;

/**
 * A whitelisted function call (technical-architecture.md §4.3). Public (parser-accepted) names are
 * `selected/2` and the grammar-v2.0 library `if/3`, `count/1`, `int/1`, `today/0`, `now/0`; `contains/2`
 * is internal (only constructible by F3 lowering / AstBuilders, never by the parser). Dispatched by a hard
 * `match` over the name in the evaluator — no `call_user_func`.
 *
 * `kind()` depends on the function: the membership predicates (`selected`/`contains`) are Boolean; every
 * value-returning function (`if`/`count`/`int`/`today`/`now`) is a Value, so `count(${roster}) > 0` and
 * `if(${a}, 1, 0) + 1` type-check as comparison / arithmetic operands.
 */
final readonly class FunctionCallNode implements Node
{
    private const BOOLEAN_FUNCTIONS = ['selected', 'contains'];

    /** @param list<Node> $args */
    public function __construct(
        public string $name,
        public array $args,
    ) {}

    public function kind(): NodeKind
    {
        return in_array($this->name, self::BOOLEAN_FUNCTIONS, true) ? NodeKind::Boolean : NodeKind::Value;
    }
}
