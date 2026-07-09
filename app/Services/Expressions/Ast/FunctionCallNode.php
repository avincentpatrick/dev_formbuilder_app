<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\NodeKind;

/**
 * A whitelisted function call (technical-architecture.md §4.3). Two names exist: `selected` (public —
 * the parser accepts it) and `contains` (internal — only constructible by F3 lowering / AstBuilders,
 * never by the parser). Dispatched by a hard `match` over the name in the evaluator — no `call_user_func`.
 */
final readonly class FunctionCallNode implements Node
{
    /** @param list<Node> $args */
    public function __construct(
        public string $name,
        public array $args,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Boolean;
    }
}
