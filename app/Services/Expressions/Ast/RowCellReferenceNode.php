<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\Marker;
use App\Services\Expressions\NodeKind;

/**
 * M246 (`grid-question-table`) — `${.col}`, this row's cell in column `col`. Legal only inside a Grid column's own
 * rules (its show-when, needed-when and required-when), and there only for a column to the left (enforced at publish
 * by `ExpressionValidationGate`). It reads the evaluation context's current row; with no row in the context it
 * resolves to {@see Marker::Absent}.
 */
final readonly class RowCellReferenceNode implements Node
{
    public function __construct(public string $column) {}

    public function kind(): NodeKind
    {
        return NodeKind::Value;
    }
}
