<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Services\Expressions\Ast\ArithmeticNode;

/**
 * The four binary arithmetic operators of grammar v2.0 (technical-architecture.md §4.3). Package-internal
 * (like {@see TokenType}), constructed only by the parser onto an {@see ArithmeticNode}; every operator is
 * evaluated over `toNumber(operand)` with NaN-propagation and division-by-zero → NaN (a blank result, never
 * an exception — the interpreter stays total over data). The TypeScript mirror reproduces these byte-for-byte.
 */
enum ArithmeticOperator: string
{
    case Add = 'add';
    case Sub = 'sub';
    case Mul = 'mul';
    case Div = 'div';
}
