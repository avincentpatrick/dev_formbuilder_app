<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Exceptions\Expressions\ExpressionSyntaxException;

/**
 * M240 (`builder-copy-section`) — re-points the `${key}` references in an expression through an old ⇒ new key map,
 * leaving every other byte exactly as written.
 *
 * Token-aware, never a text replace: {@see ExpressionLexer} reads a reference as exactly `${key}` starting at the
 * token's byte position (no whitespace is allowed inside the braces), and a string literal has no escapes, so a
 * quoted `'${a}'` is a String token and is never touched. The splice runs right to left, so earlier positions stay
 * valid. Only tokenize() runs, not parse(): a draft expression that lexes but does not yet parse is still re-pointed.
 *
 * Text that will not tokenize (half-typed, or over the lexer's 2000-byte / 500-token budget) comes back unchanged.
 * For a copy that is right — the copy is exactly as broken as its source, and publish refuses both — but a caller
 * that must not leave a reference behind (`D226`'s key rename) reads {@see rewriteReporting()}'s skipped flag.
 */
final class ExpressionKeyRewriter
{
    public function __construct(private readonly ExpressionLexer $lexer = new ExpressionLexer) {}

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     */
    public function rewrite(?string $expression, array $map): ?string
    {
        return $this->rewriteReporting($expression, $map)[0];
    }

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     * @return array{0: ?string, 1: bool} the text, and whether it held `${` but could not be read, so a reference in it may be left behind
     */
    public function rewriteReporting(?string $expression, array $map): array
    {
        if ($expression === null || $map === [] || ! str_contains($expression, '${')) {
            return [$expression, false];
        }

        try {
            $tokens = $this->lexer->tokenize($expression);
        } catch (ExpressionSyntaxException) {
            return [$expression, true];
        }

        $out = $expression;

        foreach (array_reverse($tokens) as $token) {
            if ($token->type !== TokenType::FieldRef || ! isset($map[$token->lexeme])) {
                continue;
            }

            $out = substr_replace($out, '${'.$map[$token->lexeme].'}', $token->position, strlen($token->lexeme) + 3);
        }

        return [$out, false];
    }
}
