<?php

declare(strict_types=1);

namespace App\Services\Expressions\Ast;

use App\Services\Expressions\LiteralKind;
use App\Services\Expressions\NodeKind;

/**
 * A number or string literal (technical-architecture.md §4.3). A Number literal's value is a float; a
 * String literal's value is the raw (never lowercased, never coerced) string. A String literal forces
 * string comparison in Eq (§6.2 rule 4); an empty String literal drives the emptiness test (§6.2 rule 1/2).
 */
final readonly class LiteralNode implements Node
{
    public function __construct(
        public LiteralKind $literalKind,
        public float|string $value,
    ) {}

    public function isEmptyStringLiteral(): bool
    {
        return $this->literalKind === LiteralKind::String && $this->value === '';
    }

    public function isStringLiteral(): bool
    {
        return $this->literalKind === LiteralKind::String;
    }

    public function kind(): NodeKind
    {
        return NodeKind::Value;
    }
}
