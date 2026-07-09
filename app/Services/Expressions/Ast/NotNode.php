<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\NodeKind;

/**
 * Logical negation `not( … )` (technical-architecture.md §4.3) — the function-form negation of the
 * grammar. Evaluates to `!toBool(operand)` (§6.1).
 */
final readonly class NotNode implements Node
{
    public function __construct(public Node $operand) {}

    public function kind(): NodeKind
    {
        return NodeKind::Boolean;
    }
}
