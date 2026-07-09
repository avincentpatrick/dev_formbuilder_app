<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Enums\ComparisonOperator;
use App\Services\Expressions\NodeKind;

/**
 * A single comparison (technical-architecture.md §4.3, §6.2). The parser only ever constructs this with
 * `op ∈ {Eq, Neq, Gt, Lt}`; the remaining ComparisonOperator cases (IsNull, Contains) are lowering
 * targets for F3 (IsNull → `Eq` against an empty string literal; Contains → a `contains` function node),
 * not emitted here. Comparison is non-associative — `1 < 2 < 3` is a syntax error.
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
