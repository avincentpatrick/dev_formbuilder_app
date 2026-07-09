<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\Marker;
use App\Services\Expressions\NodeKind;

/**
 * The `.` self-reference (technical-architecture.md §4.3) — the current field's own value, legal only in
 * a `constraint` expression (enforced at publish time by ExpressionParser::assertReferencesResolve). If
 * no self is set it resolves to {@see Marker::Absent} (fail-safe, never throws).
 */
final readonly class SelfReferenceNode implements Node
{
    public function kind(): NodeKind
    {
        return NodeKind::Value;
    }
}
