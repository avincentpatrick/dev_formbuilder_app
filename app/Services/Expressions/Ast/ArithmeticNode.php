<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\ArithmeticOperator;
use App\Services\Expressions\NodeKind;

/**
 * A binary arithmetic operation `+ - * /` (technical-architecture.md §4.3, grammar v2.0 / Increment G3).
 * `*`/`/` bind tighter than `+`/`-`; both levels are left-associative. Operands must be Value-kind (the
 * parser rejects a Boolean-kind operand as an `arithmetic operand`), and the result is a Value (a number),
 * so `${a} + ${b}` can itself be a comparison or arithmetic operand. Evaluation coerces both sides with
 * `toNumber`; a NaN operand (empty / non-numeric) or a division by zero yields NaN — normalised to `null`
 * at the value boundary, never an exception.
 */
final readonly class ArithmeticNode implements Node
{
    public function __construct(
        public ArithmeticOperator $op,
        public Node $left,
        public Node $right,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Value;
    }
}
