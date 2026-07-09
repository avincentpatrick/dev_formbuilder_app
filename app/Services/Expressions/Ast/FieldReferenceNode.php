<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\Marker;
use App\Services\Expressions\NodeKind;

/**
 * A `${key}` field reference (technical-architecture.md §4.3). At evaluation time it resolves against the
 * relevance-pruned answer map; an absent key resolves to {@see Marker::Absent}
 * (EMPTY) — never an exception. `key` is the bare field key (braces stripped by the lexer).
 */
final readonly class FieldReferenceNode implements Node
{
    public function __construct(public string $key) {}

    public function kind(): NodeKind
    {
        return NodeKind::Value;
    }
}
