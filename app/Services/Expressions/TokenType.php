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
    case Minus = 'minus';
    case Number = 'number';
    case String = 'string';
    case FieldRef = 'field_ref';
    case SelfRef = 'self_ref';
    case Word = 'word';
    case Eof = 'eof';
}
