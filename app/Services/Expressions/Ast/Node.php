<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\NodeKind;

/**
 * Marker interface implemented by every AST node (technical-architecture.md §4.3). Nodes are dumb
 * `final readonly` data; ALL evaluation logic lives in {@see ExpressionEvaluator}
 * (external dispatch on `instanceof`) — there is no `evaluate()` on a node, no `eval()`, no
 * `call_user_func`. `kind()` is a compile-time constant per class used by the parser's operand typing (§3).
 */
interface Node
{
    public function kind(): NodeKind;
}
