<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Enums\LogicOperator;
use App\Services\Expressions\NodeKind;

/**
 * A binary `and` / `or` (technical-architecture.md §4.3). Both are left-associative and short-circuit;
 * `and` binds tighter than `or`. Operands are coerced with toBool (§6) at evaluation time.
 */
final readonly class LogicalNode implements Node
{
    public function __construct(
        public LogicOperator $op,
        public Node $left,
        public Node $right,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Boolean;
    }
}
