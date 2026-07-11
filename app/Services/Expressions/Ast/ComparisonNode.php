<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Enums\ComparisonOperator;
use App\Services\Expressions\NodeKind;

/**
 * A single comparison (technical-architecture.md §4.3, §6.2). The parser constructs this with
 * `op ∈ {Eq, Neq, Gt, Lt, Gte, Lte}` (the `>=`/`<=` cases added with grammar v2.0 / Increment G3); the
 * remaining ComparisonOperator cases (IsNull, Contains) are lowering targets for F3 (IsNull → `Eq` against
 * an empty string literal; Contains → a `contains` function node), not emitted by the parser. Comparison is
 * non-associative — `1 < 2 < 3` is a syntax error. `>`/`<`/`>=`/`<=` are numeric-only (a non-numeric
 * operand yields false); `=`/`!=` follow the §6.2 equality rules.
 */
final readonly class ComparisonNode implements Node
{
    public function __construct(
        public ComparisonOperator $op,
        public Node $left,
        public Node $right,
    ) {}

    public function kind(): NodeKind
    {
        return NodeKind::Boolean;
    }
}
