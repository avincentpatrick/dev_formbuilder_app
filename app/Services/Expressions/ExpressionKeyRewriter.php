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
 *
 * M246: a grid cell `${grid.row.col}` is one token whose lexeme is the whole reference, so it is spliced the same
 * way; only its key changes.
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
            $replacement = self::renamed($token, $map);
            if ($replacement === null) {
                continue;
            }

            $out = substr_replace($out, '${'.$replacement.'}', $token->position, strlen($token->lexeme) + 3);
        }

        return [$out, false];
    }

    /**
     * The new text inside the braces, or null when the token is not a reference the map renames. M246: a cell
     * `${key.row.col}` keeps its path and renames only its key; a row-relative `${.col}` names no key at all.
     *
     * @param  array<string, string>  $map
     */
    private static function renamed(Token $token, array $map): ?string
    {
        if ($token->type === TokenType::FieldRef) {
            return $map[$token->lexeme] ?? null;
        }

        if ($token->type === TokenType::CellRef) {
            $dot = (int) strpos($token->lexeme, '.');
            $key = substr($token->lexeme, 0, $dot);

            return isset($map[$key]) ? $map[$key].substr($token->lexeme, $dot) : null;
        }

        return null;
    }
}
