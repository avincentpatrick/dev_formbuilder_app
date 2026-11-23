<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\Marker;
use App\Services\Expressions\NodeKind;

/**
 * M246 (`grid-question-table`, `R-ffd10da2`) — `${key.row.col}`, one cell of a Grid, or `${key.row}`, one row of a
 * Likert grid (`column` null). It resolves against the grid's answer in the same relevance-pruned map a
 * {@see FieldReferenceNode} reads; a grid, row or cell that is not there resolves to {@see Marker::Absent} — never an
 * exception. Deliberately NOT a FieldReferenceNode with a path: every reader that matches a FieldReferenceNode by its
 * key (the builder's condition model, the calculation presets, the operand judge) must miss a cell rather than read
 * it as the whole grid.
 */
final readonly class CellReferenceNode implements Node
{
    public function __construct(
        public string $key,
        public string $row,
        public ?string $column,
    ) {}

    /** The reference as written inside its braces — `key.row.col` or `key.row`. */
    public function path(): string
    {
        return $this->column === null
            ? $this->key.'.'.$this->row
            : $this->key.'.'.$this->row.'.'.$this->column;
    }

    public function kind(): NodeKind
    {
        return NodeKind::Value;
    }
}
