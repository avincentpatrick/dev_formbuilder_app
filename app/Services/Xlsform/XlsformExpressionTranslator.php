<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Enums\ValueShape;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\ExpressionLexer;
use App\Services\Expressions\ExpressionParser;
use App\Services\Expressions\FunctionRegistry;
use App\Services\Expressions\Token;
use App\Services\Expressions\TokenType;
use App\Services\Validation\StructuredRuleEvaluator;
use App\Services\Xlsform\Dto\ValidationSpec;

/**
 * Reads an imported formula in ODK's spelling (M244, `R-bb470c3a`, `D228`).
 *
 * ⛔ A KOBO FORMULA IMPORTED, THEN PUBLISH REFUSED IT WITH A PARSER CODE. `count-selected(.) <= 2` lexes here as
 * `count` minus `selected`, so publish said *"invalid (unexpected_token)"*, and `regex(…)` said `unknown_function`;
 * the import itself said nothing. Three things change, all at import:
 *
 *   1. {@see self::translate()} renames the ODK spellings this product has under another name. There is one:
 *      `count-selected` is `count`, which already counts a multi-select's ticks in both engines (the
 *      `count_multiselect` golden vector). It is a splice on the text, never a re-print, and it never touches a
 *      quoted string or a `${…}` reference.
 *   2. {@see self::unsupported()} names every function, operator and bare word the grammar does not have, so the
 *      import can warn naming each one rather than leave publish to report a code.
 *   3. {@see self::constraint()} turns a whole `regex(., 'R')` or `string-length(.) op N` into this product's own
 *      Pattern or length rule (`D228` A), where the question's shape takes that rule and the pattern compiles. A
 *      constraint is split on its top-level `and` first, which is exact: every constraint row must pass, and both
 *      a constraint and a structured rule skip a blank answer. It is not split when it also has a top-level `or`,
 *      because `and` binds tighter. Adding `regex`/`string-length` to the grammar itself is its own row.
 *
 * ⚠️ ODK's `regex()` SEARCHES; a Pattern rule matches the whole answer (`^(?:body)$`). An anchored `^…$` keeps its
 * inside; an unanchored side is padded with `[\s\S]*`, so the rule means what the Kobo author wrote.
 */
final class XlsformExpressionTranslator
{
    /** ODK spellings this product has under another name. */
    private const RENAMES = ['count-selected' => 'count'];

    /** Words the grammar reads as operators, never as a name. */
    private const KEYWORDS = ['and', 'or'];

    /** ODK operator words this product's grammar does not have. */
    private const ODK_OPERATORS = ['div', 'mod'];

    /** A name in ODK's spelling: letters, digits and `_`, joined by `-` or `:` (`count-selected`, `jr:choice-name`). */
    private const NAME = '/(?<![A-Za-z0-9_:])([A-Za-z_][A-Za-z0-9_]*(?:[:\-][A-Za-z_][A-Za-z0-9_]*)*)(\s*\()?/';

    /**
     * PCRE-only constructs a browser's `RegExp` refuses or reads differently. A Pattern rule runs in both engines, and
     * a pattern only PHP can compile would refuse every answer in the browser, so such a regex stays as written.
     */
    private const PCRE_ONLY = ['(?i', '(?m', '(?s', '(?x', '(?U', '(?J', '\A', '\Z', '\z', '\h', '\H', '\K', '\R', '*+', '++', '?+', '}+', '(?<', '(?P', '(?|', '(?>', '\Q'];

    public function __construct(
        private readonly FunctionRegistry $functions = new FunctionRegistry,
        private readonly ExpressionLexer $lexer = new ExpressionLexer,
    ) {}

    /** The formula with the ODK spellings this product has under another name renamed. */
    public function translate(string $expression): string
    {
        $masked = $this->mask($expression);
        preg_match_all(self::NAME, $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach (array_reverse($matches) as $match) {
            [$name, $offset] = $match[1];
            $isCall = isset($match[2]); // the last group: absent, or the bracket a call opens
            $renamed = self::RENAMES[$name] ?? null;
            if ($isCall && $renamed !== null) {
                $expression = substr_replace($expression, $renamed, $offset, strlen($name));
            }
        }

        return $expression;
    }

    /**
     * What the formula uses that this product's grammar does not have, as the author wrote it: functions
     * (`regex`, `jr:choice-name`), ODK operators (`div`, `mod`) and bare words (`AND`, `true`). Read after
     * {@see self::translate()}. Each is named once.
     *
     * @return list<string>
     */
    public function unsupported(string $expression): array
    {
        $masked = $this->mask($expression);
        preg_match_all(self::NAME, $masked, $matches, PREG_SET_ORDER);

        $found = [];
        foreach ($matches as $match) {
            $name = $match[1];
            $isCall = isset($match[2]); // the last group: absent, or the bracket a call opens

            // `and (…)` and `or (…)` are operators before a bracket, never calls.
            if ($isCall) {
                if ($name !== 'not' && ! in_array($name, self::KEYWORDS, true) && ! $this->functions->isPublic($name)) {
                    $found[$name] = true;
                }

                continue;
            }

            if (! in_array($name, self::KEYWORDS, true)) {
                $found[$name] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Why the grammar cannot read the formula, in the parser's words, or null when it can. Asked only when
     * {@see self::unsupported()} named nothing, so a missing function is reported by name rather than by the
     * syntax error it causes.
     */
    public function syntaxProblem(string $expression): ?string
    {
        try {
            (new ExpressionParser($this->lexer, $this->functions))->parse($expression);
        } catch (ExpressionSyntaxException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /** Whether `$name` is an ODK operator word (`div`, `mod`) rather than a function or a field. */
    public static function isOperatorWord(string $name): bool
    {
        return in_array($name, self::ODK_OPERATORS, true);
    }

    /**
     * A constraint as this product's rows: each top-level `and`-part that is a whole `regex(., 'R')` or
     * `string-length(.) op N` becomes a Pattern or length rule; the rest stays one expression, in its own words.
     * Messages are the caller's to add.
     *
     * @return array{0: list<ValidationSpec>, 1: ?string} the structured rows, and the expression left (null when none is)
     */
    public function constraint(string $constraint, FieldType $type): array
    {
        $parts = $this->topLevelAndParts($constraint);
        $shape = ValueShape::for($type);

        $rules = [];
        $kept = [];
        foreach ($parts as $part) {
            $converted = $this->structured($this->stripOuterParens($part), $shape);
            if ($converted === null) {
                $kept[] = trim($part);
            } else {
                array_push($rules, ...$converted);
            }
        }

        if ($rules === []) {
            return [[], $constraint];
        }

        return [$rules, $kept === [] ? null : implode(' and ', $kept)];
    }

    /**
     * The Pattern or length rows one constraint part converts to, or null when it is not one of the two shapes, or
     * the question cannot take the rule, or the pattern would not compile in both engines.
     *
     * @return non-empty-list<ValidationSpec>|null
     */
    private function structured(string $part, ValueShape $shape): ?array
    {
        try {
            $tokens = $this->lexer->tokenize($part);
        } catch (ExpressionSyntaxException) {
            return null;
        }

        $regex = $this->regexBody($tokens);
        if ($regex !== null) {
            if (! $shape->allows(ValidationRuleType::Pattern) || ! self::compilesInBothEngines($regex)) {
                return null;
            }

            return [new ValidationSpec(ruleType: ValidationRuleType::Pattern, ruleValue: $regex)];
        }

        $bounds = $this->lengthBounds($tokens);
        if ($bounds === null || ! $shape->allows(ValidationRuleType::MinLength)) {
            return null;
        }

        $rows = [];
        if ($bounds[0] !== null) {
            $rows[] = new ValidationSpec(ruleType: ValidationRuleType::MinLength, ruleValue: (string) $bounds[0]);
        }
        if ($bounds[1] !== null) {
            $rows[] = new ValidationSpec(ruleType: ValidationRuleType::MaxLength, ruleValue: (string) $bounds[1]);
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * The Pattern body for `regex(., 'R')`, or null. An anchored side keeps the inside; an unanchored side is padded
     * so the whole-answer match still searches, as ODK's does.
     *
     * @param  list<Token>  $tokens
     */
    private function regexBody(array $tokens): ?string
    {
        if (! $this->tokensAre($tokens, [TokenType::Word, TokenType::LParen, TokenType::SelfRef, TokenType::Comma, TokenType::String, TokenType::RParen, TokenType::Eof])
            || $tokens[0]->lexeme !== 'regex') {
            return null;
        }

        $regex = $tokens[4]->lexeme;
        $startAnchored = str_starts_with($regex, '^');
        $endAnchored = str_ends_with($regex, '$') && ! $this->isEscapedAt($regex, strlen($regex) - 1);

        $inner = $regex;
        if ($startAnchored) {
            $inner = substr($inner, 1);
        }
        if ($endAnchored) {
            $inner = substr($inner, 0, -1);
        }

        if ($startAnchored && $endAnchored) {
            return $this->unwrapGroup($inner);
        }

        return ($startAnchored ? '' : '[\s\S]*').'(?:'.$inner.')'.($endAnchored ? '' : '[\s\S]*');
    }

    /**
     * `[min, max]` characters for `string-length(.) op N` or `N op string-length(.)`, or null.
     *
     * @param  list<Token>  $tokens
     * @return array{0: ?int, 1: ?int}|null
     */
    private function lengthBounds(array $tokens): ?array
    {
        $call = [TokenType::Word, TokenType::Minus, TokenType::Word, TokenType::LParen, TokenType::SelfRef, TokenType::RParen];
        $operators = [TokenType::Lte, TokenType::Lt, TokenType::Gte, TokenType::Gt, TokenType::Eq];

        if (count($tokens) !== 9) {
            return null;
        }

        if ($this->tokensAre(array_slice($tokens, 0, 6), $call) && in_array($tokens[6]->type, $operators, true)
            && $tokens[7]->type === TokenType::Number && $this->isStringLength($tokens, 0)) {
            return $this->bounds($tokens[6]->type, $tokens[7]->lexeme);
        }

        if ($tokens[0]->type === TokenType::Number && in_array($tokens[1]->type, $operators, true)
            && $this->tokensAre(array_slice($tokens, 2, 6), $call) && $this->isStringLength($tokens, 2)) {
            return $this->bounds($this->flip($tokens[1]->type), $tokens[0]->lexeme);
        }

        return null;
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function isStringLength(array $tokens, int $at): bool
    {
        return $tokens[$at]->lexeme === 'string'
            && $tokens[$at + 2]->lexeme === 'length'
            && $tokens[$at + 1]->position === $tokens[$at]->position + 6
            && $tokens[$at + 2]->position === $tokens[$at + 1]->position + 1;
    }

    /**
     * @return array{0: ?int, 1: ?int}|null
     */
    private function bounds(TokenType $operator, string $number): ?array
    {
        if (! ctype_digit($number)) {
            return null; // a length is a whole number
        }
        $n = (int) $number;

        return match ($operator) {
            TokenType::Lte => [null, $n],
            TokenType::Lt => $n >= 1 ? [null, $n - 1] : null,
            TokenType::Gte => [$n, null],
            TokenType::Gt => [$n + 1, null],
            TokenType::Eq => [$n, $n],
            default => null,
        };
    }

    private function flip(TokenType $operator): TokenType
    {
        return match ($operator) {
            TokenType::Lt => TokenType::Gt,
            TokenType::Gt => TokenType::Lt,
            TokenType::Lte => TokenType::Gte,
            TokenType::Gte => TokenType::Lte,
            default => $operator,
        };
    }

    /**
     * @param  list<Token>  $tokens
     * @param  list<TokenType>  $types
     */
    private function tokensAre(array $tokens, array $types): bool
    {
        if (count($tokens) !== count($types)) {
            return false;
        }
        foreach ($types as $i => $type) {
            if ($tokens[$i]->type !== $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a Pattern body compiles in PHP and avoids the constructs a browser's `RegExp` reads differently.
     */
    public static function compilesInBothEngines(string $body): bool
    {
        if (! StructuredRuleEvaluator::isCompilablePattern($body)) {
            return false;
        }

        foreach (self::PCRE_ONLY as $construct) {
            if (str_contains($body, $construct)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The constraint's top-level `and`-parts, in its own words. A constraint with a top-level `or` is one part,
     * because `and` binds tighter and a split would change its meaning.
     *
     * @return non-empty-list<string>
     */
    private function topLevelAndParts(string $expression): array
    {
        $masked = $this->mask($expression);
        $length = strlen($masked);
        $depth = 0;
        $cuts = [];
        $hasOr = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $masked[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $this->wordAt($masked, $i, 'and')) {
                $cuts[] = $i;
            } elseif ($depth === 0 && $this->wordAt($masked, $i, 'or')) {
                $hasOr = true;
            }
        }

        if ($cuts === [] || $hasOr) {
            return [$expression];
        }

        $parts = [];
        $start = 0;
        foreach ($cuts as $cut) {
            $parts[] = substr($expression, $start, $cut - $start);
            $start = $cut + 3;
        }
        $parts[] = substr($expression, $start);

        return $parts;
    }

    private function wordAt(string $text, int $i, string $word): bool
    {
        $boundary = static fn (string $char): bool => ! (ctype_alnum($char) || $char === '_' || $char === '-' || $char === ':');

        return substr($text, $i, strlen($word)) === $word
            && ($i === 0 || $boundary($text[$i - 1]))
            && ($i + strlen($word) >= strlen($text) || $boundary($text[$i + strlen($word)]));
    }

    /** `(x)` → `x`, as many times as the whole part is one parenthesised group. */
    private function stripOuterParens(string $part): string
    {
        $part = trim($part);
        while (str_starts_with($part, '(') && $this->closingParen($this->mask($part), 0) === strlen($part) - 1) {
            $part = trim(substr($part, 1, -1));
        }

        return $part;
    }

    private function closingParen(string $masked, int $open): ?int
    {
        $depth = 0;
        $length = strlen($masked);
        for ($i = $open; $i < $length; $i++) {
            if ($masked[$i] === '(') {
                $depth++;
            } elseif ($masked[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * A regex `(?:X)` whose group spans the whole of it → `X`, so a Pattern exported as `^(?:X)$` reads back as `X`
     * rather than growing a group at every round trip. Escapes and character classes are skipped while matching.
     */
    private function unwrapGroup(string $regex): string
    {
        if (! str_starts_with($regex, '(?:')) {
            return $regex;
        }

        $depth = 0;
        $length = strlen($regex);
        for ($i = 0; $i < $length; $i++) {
            $char = $regex[$i];
            if ($char === '\\') {
                $i++;

                continue;
            }
            if ($char === '[') {
                $i = $this->classEnd($regex, $i);

                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i === $length - 1 ? substr($regex, 3, -1) : $regex;
                }
            }
        }

        return $regex;
    }

    private function classEnd(string $regex, int $open): int
    {
        $length = strlen($regex);
        $i = $open + 1;
        if ($i < $length && $regex[$i] === '^') {
            $i++;
        }
        if ($i < $length && $regex[$i] === ']') {
            $i++; // a leading `]` is literal
        }
        for (; $i < $length; $i++) {
            if ($regex[$i] === '\\') {
                $i++;
            } elseif ($regex[$i] === ']') {
                return $i;
            }
        }

        return $length;
    }

    private function isEscapedAt(string $text, int $index): bool
    {
        $backslashes = 0;
        for ($i = $index - 1; $i >= 0 && $text[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /**
     * The formula with every quoted string's contents and every `${…}` reference blanked to spaces, byte for byte, so
     * a scan over it finds only the formula's own words, at the offsets they hold in the original.
     */
    private function mask(string $expression): string
    {
        $masked = $expression;
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if ($char === "'" || $char === '"') {
                $end = strpos($expression, $char, $i + 1);
                $stop = $end === false ? $length : $end;
                for ($j = $i + 1; $j < $stop; $j++) {
                    $masked[$j] = ' ';
                }
                $i = $stop + 1;

                continue;
            }

            if ($char === '$' && ($expression[$i + 1] ?? '') === '{') {
                $end = strpos($expression, '}', $i + 2);
                $stop = $end === false ? $length - 1 : $end;
                for ($j = $i; $j <= $stop; $j++) {
                    $masked[$j] = ' ';
                }
                $i = $stop + 1;

                continue;
            }

            $i++;
        }

        return $masked;
    }
}
