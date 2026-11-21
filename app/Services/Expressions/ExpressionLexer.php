<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Exceptions\Expressions\ExpressionSyntaxException;

/**
 * Source string → list<Token> (technical-architecture.md §4.3 §4). Left-to-right, position-tracked, no
 * backtracking. Enforces the anti-DoS length/token budget BEFORE the parser runs. Recognises: grouping,
 * the comparison punctuation (`= != > < >= <=`, grammar v2.0), the arithmetic operators (`+ - * /`,
 * grammar v2.0), `${key}` references, single/double-quoted strings (no escapes), numbers (at most one dot;
 * no exponent/sign/bare-dot), the `.` self-reference, and lowercase words (keywords/functions). Any
 * unrecognised character is an `unexpected_token`. M246 adds the grid cell references `${key.row.col}`,
 * `${key.row}` and the row-relative `${.col}` (see lexReference()).
 *
 * Caller contract: a null or blank/whitespace-only expression means "always relevant / no constraint"
 * and MUST be short-circuited by the caller before tokenize() — so `empty_expression` is a genuine
 * authoring error here, never a normal blank-field state.
 */
final class ExpressionLexer
{
    private const MAX_EXPRESSION_LENGTH = 2000;

    private const MAX_TOKENS = 500;

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $length = strlen($source);

        if ($length > self::MAX_EXPRESSION_LENGTH) {
            throw ExpressionSyntaxException::expressionTooLong($length);
        }

        if (trim($source) === '') {
            throw ExpressionSyntaxException::emptyExpression();
        }

        $tokens = [];
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            $tokens[] = match (true) {
                $char === '(' => $this->single(TokenType::LParen, '(', $i),
                $char === ')' => $this->single(TokenType::RParen, ')', $i),
                $char === ',' => $this->single(TokenType::Comma, ',', $i),
                $char === '=' => $this->single(TokenType::Eq, '=', $i),
                $char === '>' => $this->lexRelational($source, $length, $i, TokenType::Gte, TokenType::Gt, '>'),
                $char === '<' => $this->lexRelational($source, $length, $i, TokenType::Lte, TokenType::Lt, '<'),
                $char === '+' => $this->single(TokenType::Plus, '+', $i),
                $char === '-' => $this->single(TokenType::Minus, '-', $i),
                $char === '*' => $this->single(TokenType::Star, '*', $i),
                $char === '/' => $this->single(TokenType::Slash, '/', $i),
                $char === '!' => $this->lexNeq($source, $length, $i),
                ctype_digit($char) => $this->lexNumber($source, $length, $i),
                $char === '.' => $this->single(TokenType::SelfRef, '.', $i),
                $char === "'" || $char === '"' => $this->lexString($source, $length, $i),
                $char === '$' => $this->lexReference($source, $length, $i),
                ctype_lower($char) || $char === '_' => $this->lexWord($source, $length, $i),
                default => throw ExpressionSyntaxException::unexpectedToken($char, $i),
            };

            if (count($tokens) > self::MAX_TOKENS) {
                throw ExpressionSyntaxException::expressionTooLong($length);
            }
        }

        $tokens[] = new Token(TokenType::Eof, '', $length);

        return $tokens;
    }

    private function single(TokenType $type, string $lexeme, int &$i): Token
    {
        $token = new Token($type, $lexeme, $i);
        $i += strlen($lexeme);

        return $token;
    }

    private function lexNeq(string $source, int $length, int &$i): Token
    {
        if ($i + 1 >= $length || $source[$i + 1] !== '=') {
            throw ExpressionSyntaxException::unexpectedToken('!', $i);
        }

        $token = new Token(TokenType::Neq, '!=', $i);
        $i += 2;

        return $token;
    }

    /**
     * A `>`/`<` becomes its `>=`/`<=` form when the next character is `=` (grammar v2.0). The single-char and
     * two-char lexemes carry their exact source so positions stay correct.
     */
    private function lexRelational(string $source, int $length, int &$i, TokenType $withEq, TokenType $bare, string $bareLexeme): Token
    {
        if ($i + 1 < $length && $source[$i + 1] === '=') {
            $token = new Token($withEq, $bareLexeme.'=', $i);
            $i += 2;

            return $token;
        }

        return $this->single($bare, $bareLexeme, $i);
    }

    private function lexNumber(string $source, int $length, int &$i): Token
    {
        $start = $i;

        while ($i < $length && ctype_digit($source[$i])) {
            $i++;
        }

        // At most ONE fractional part, and only if a digit follows the dot ("5." / ".5" are not numbers;
        // a second dot terminates the number so "1.2.3" splits into 1.2 · . · 3).
        if ($i + 1 < $length && $source[$i] === '.' && ctype_digit($source[$i + 1])) {
            $i++;
            while ($i < $length && ctype_digit($source[$i])) {
                $i++;
            }
        }

        return new Token(TokenType::Number, substr($source, $start, $i - $start), $start);
    }

    private function lexString(string $source, int $length, int &$i): Token
    {
        $start = $i;
        $quote = $source[$i];
        $i++;
        $value = '';

        while ($i < $length && $source[$i] !== $quote) {
            $value .= $source[$i];
            $i++;
        }

        if ($i >= $length) {
            throw ExpressionSyntaxException::unterminatedString($start);
        }

        $i++; // closing quote

        return new Token(TokenType::String, $value, $start);
    }

    /**
     * `${key}` → FieldRef. M246 (`grid-question-table`, `R-ffd10da2`) adds two more shapes inside the same braces:
     *  - `${key.row}` / `${key.row.col}` → CellRef, a Likert row or a Grid cell, at most two segments;
     *  - `${.col}` → RowCellRef, this row's cell in a grid column's own rules.
     * A segment is `[A-Za-z0-9_][A-Za-z0-9_-]*` (a row or column value; the key keeps its own production), so
     * `${a.}`, `${a..b}`, `${.}`, `${a.b.c.d}` and `${a-b}` stay malformed. The CellRef and RowCellRef lexeme is the
     * WHOLE text inside the braces, so a key rename splices `strlen(lexeme) + 3` bytes exactly as for a FieldRef.
     * Mirrored byte for byte by `lexer.ts` and by `TemplateParser::readKey()`.
     */
    private function lexReference(string $source, int $length, int &$i): Token
    {
        $start = $i;

        if ($i + 1 >= $length || $source[$i + 1] !== '{') {
            throw ExpressionSyntaxException::unexpectedToken('$', $i);
        }

        $keyStart = $i + 2;
        $j = $keyStart;

        // `${.col}` — a row-relative cell: exactly one segment after the dot.
        if ($j < $length && $source[$j] === '.') {
            $end = self::segmentEnd($source, $length, $j + 1);
            if ($end === null || $end >= $length || $source[$end] !== '}') {
                throw ExpressionSyntaxException::malformedReference($this->rawReference($source, $length, $start));
            }
            $i = $end + 1;

            return new Token(TokenType::RowCellRef, substr($source, $keyStart, $end - $keyStart), $start);
        }

        if ($j >= $length || ! (ctype_alpha($source[$j]) || $source[$j] === '_')) {
            throw ExpressionSyntaxException::malformedReference($this->rawReference($source, $length, $start));
        }

        $j++;
        while ($j < $length && (ctype_alnum($source[$j]) || $source[$j] === '_')) {
            $j++;
        }

        // At most two `.segment` parts after the key.
        $segments = 0;
        while ($j < $length && $source[$j] === '.' && $segments < 2) {
            $end = self::segmentEnd($source, $length, $j + 1);
            if ($end === null) {
                throw ExpressionSyntaxException::malformedReference($this->rawReference($source, $length, $start));
            }
            $j = $end;
            $segments++;
        }

        if ($j >= $length || $source[$j] !== '}') {
            throw ExpressionSyntaxException::malformedReference($this->rawReference($source, $length, $start));
        }

        $text = substr($source, $keyStart, $j - $keyStart);
        $i = $j + 1; // past the closing brace

        return new Token($segments === 0 ? TokenType::FieldRef : TokenType::CellRef, $text, $start);
    }

    /**
     * M246: one path segment starting at `$at` — `[A-Za-z0-9_][A-Za-z0-9_-]*`, byte-wise `ctype_*`. Returns the
     * offset just past it, or null when no segment starts there.
     */
    public static function segmentEnd(string $source, int $length, int $at): ?int
    {
        if ($at >= $length || ! (ctype_alnum($source[$at]) || $source[$at] === '_')) {
            return null;
        }

        $j = $at + 1;
        while ($j < $length && (ctype_alnum($source[$j]) || $source[$j] === '_' || $source[$j] === '-')) {
            $j++;
        }

        return $j;
    }

    private function lexWord(string $source, int $length, int &$i): Token
    {
        $start = $i;

        while ($i < $length && (ctype_lower($source[$i]) || ctype_digit($source[$i]) || $source[$i] === '_')) {
            $i++;
        }

        return new Token(TokenType::Word, substr($source, $start, $i - $start), $start);
    }

    private function rawReference(string $source, int $length, int $start): string
    {
        $window = min($length - $start, 24);

        return substr($source, $start, $window);
    }
}
