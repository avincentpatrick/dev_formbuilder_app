<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Services\Expressions\Ast\LiteralNode;

/**
 * The static kind of a {@see LiteralNode} (technical-architecture.md §4.3).
 * A `String` literal forces string comparison in Eq (§6.2 rule 4); a `Number` literal is a float.
 */
enum LiteralKind
{
    case Number;
    case String;
}
