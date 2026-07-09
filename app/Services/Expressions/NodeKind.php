<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The static result-kind of an AST node (technical-architecture.md §4.3). The parser uses it to reject
 * a Boolean-kind node used as a comparison operand or `selected()` argument (§3) — e.g.
 * `selected(${m},'a') = 1` — since such a coercion has no cross-engine-stable meaning.
 */
enum NodeKind: string
{
    case Value = 'value';
    case Boolean = 'boolean';
}
