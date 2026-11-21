<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * Lexical token kinds produced by {@see ExpressionLexer} (technical-architecture.md §4.3).
 * Package-internal — deliberately not in app/Enums, which holds domain enums.
 */
enum TokenType: string
{
    case LParen = 'lparen';
    case RParen = 'rparen';
    case Comma = 'comma';
    case Eq = 'eq';
    case Neq = 'neq';
    case Gt = 'gt';
    case Lt = 'lt';
    case Gte = 'gte';
    case Lte = 'lte';
    case Plus = 'plus';
    case Minus = 'minus';
    case Star = 'star';
    case Slash = 'slash';
    case Number = 'number';
    case String = 'string';
    case FieldRef = 'field_ref';
    /** M246: `${grid.row.col}` or `${grid.row}` — the lexeme is the whole text inside the braces. */
    case CellRef = 'cell_ref';
    /** M246: `${.col}` — this row's cell, inside a grid column's own rules; the lexeme keeps the leading dot. */
    case RowCellRef = 'row_cell_ref';
    case SelfRef = 'self_ref';
    case Word = 'word';
    case Eof = 'eof';
}
