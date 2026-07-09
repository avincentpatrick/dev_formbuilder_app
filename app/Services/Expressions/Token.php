<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * A single lexeme produced by {@see ExpressionLexer} (technical-architecture.md §4.3). `position` is the
 * 0-based source offset where the lexeme starts, carried so syntax errors can point at the exact spot.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $position,
    ) {}
}
