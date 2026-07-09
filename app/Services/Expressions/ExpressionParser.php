<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Enums\ComparisonOperator;
use App\Enums\ExpressionKind;
use App\Enums\LogicOperator;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\Ast\ComparisonNode;
use App\Services\Expressions\Ast\FieldReferenceNode;
use App\Services\Expressions\Ast\FunctionCallNode;
use App\Services\Expressions\Ast\LiteralNode;
use App\Services\Expressions\Ast\LogicalNode;
use App\Services\Expressions\Ast\Node;
use App\Services\Expressions\Ast\NotNode;
use App\Services\Expressions\Ast\SelfReferenceNode;

/**
 * Recursive-descent parser: Token[] → AST (technical-architecture.md §4.3 §3). Precedence, lowest→highest:
 * `or` < `and` < `not(…)` < comparison (non-associative — a single comparator, no chaining) < primary.
 * Enforces operand result-kind typing (a Boolean-kind node — a nested comparison, and/or, not, or
 * selected — is rejected as a comparison operand or a `selected()` first argument, §3) and an anti-DoS
 * parse-depth cap. Parses are memoised in a bounded LRU keyed by source string — sound because a
 * published version is immutable so an expression's parse never changes (§9).
 */
final class ExpressionParser
{
    private const MAX_PARSE_DEPTH = 64;

    private const MAX_MEMO_ENTRIES = 1000;

    /** @var list<Token> */
    private array $tokens = [];

    private int $pos = 0;

    private int $depth = 0;

    /** @var array<string, Node> */
    private array $memo = [];

    public function __construct(
        private readonly ExpressionLexer $lexer,
        private readonly FunctionRegistry $functions,
    ) {}

    /** @throws ExpressionSyntaxException */
    public function parse(string $expression): Node
    {
        return $this->parseWithKey($expression, $expression);
    }

    /** Same, memo key namespaced by form_version_id (for telemetry/clarity; parsing is pure). */
    public function parseForVersion(string $formVersionId, string $expression): Node
    {
        return $this->parseWithKey($formVersionId."\0".$expression, $expression);
    }

    /**
     * Publish-time integrity checks over an already-parsed AST (never at submission time, where an
     * unknown key → EMPTY and an unset self → EMPTY, without throwing):
     *  - every `${key}` must be a known field key of the version being published;
     *  - a `.` self-reference is legal only in a constraint.
     *
     * @param  array<string, bool>  $knownKeys
     *
     * @throws ExpressionSyntaxException
     */
    public function assertReferencesResolve(Node $node, array $knownKeys, ExpressionKind $kind, ?string $fieldKey = null): void
    {
        if ($node instanceof FieldReferenceNode) {
            if (! array_key_exists($node->key, $knownKeys)) {
                throw ExpressionSyntaxException::unknownFieldReference($node->key, $fieldKey);
            }

            return;
        }

        if ($node instanceof SelfReferenceNode) {
            if ($kind !== ExpressionKind::Constraint) {
                throw ExpressionSyntaxException::selfReferenceInNonConstraint($fieldKey);
            }

            return;
        }

        if ($node instanceof ComparisonNode) {
            $this->assertReferencesResolve($node->left, $knownKeys, $kind, $fieldKey);
            $this->assertReferencesResolve($node->right, $knownKeys, $kind, $fieldKey);

            return;
        }

        if ($node instanceof LogicalNode) {
            $this->assertReferencesResolve($node->left, $knownKeys, $kind, $fieldKey);
            $this->assertReferencesResolve($node->right, $knownKeys, $kind, $fieldKey);

            return;
        }

        if ($node instanceof NotNode) {
            $this->assertReferencesResolve($node->operand, $knownKeys, $kind, $fieldKey);

            return;
        }

        if ($node instanceof FunctionCallNode) {
            foreach ($node->args as $arg) {
                $this->assertReferencesResolve($arg, $knownKeys, $kind, $fieldKey);
            }
        }
    }

    private function parseWithKey(string $memoKey, string $source): Node
    {
        if (array_key_exists($memoKey, $this->memo)) {
            $node = $this->memo[$memoKey];
            unset($this->memo[$memoKey]);
            $this->memo[$memoKey] = $node; // LRU touch: move to most-recent

            return $node;
        }

        $this->tokens = $this->lexer->tokenize($source);
        $this->pos = 0;
        $this->depth = 0;

        $ast = $this->parseExpr();
        $this->expect(TokenType::Eof); // trailing tokens (e.g. a chained comparison) are unexpected

        return $this->remember($memoKey, $ast);
    }

    private function remember(string $key, Node $node): Node
    {
        $this->memo[$key] = $node;

        if (count($this->memo) > self::MAX_MEMO_ENTRIES) {
            array_shift($this->memo); // evict least-recently-used
        }

        return $node;
    }

    private function parseExpr(): Node
    {
        return $this->parseOr();
    }

    private function parseOr(): Node
    {
        $left = $this->parseAnd();

        while ($this->isWord('or')) {
            $this->advance();
            $left = new LogicalNode(LogicOperator::Or, $left, $this->parseAnd());
        }

        return $left;
    }

    private function parseAnd(): Node
    {
        $left = $this->parseNot();

        while ($this->isWord('and')) {
            $this->advance();
            $left = new LogicalNode(LogicOperator::And, $left, $this->parseNot());
        }

        return $left;
    }

    private function parseNot(): Node
    {
        if (! $this->isWord('not')) {
            return $this->parseComparison();
        }

        $this->advance(); // 'not'

        if (! $this->check(TokenType::LParen)) {
            throw $this->unexpected();
        }

        $this->descend();
        $this->advance(); // '('
        $inner = $this->parseExpr();
        $this->expect(TokenType::RParen);
        $this->ascend();

        return new NotNode($inner);
    }

    private function parseComparison(): Node
    {
        $left = $this->parseOperand();

        $op = match ($this->peek()->type) {
            TokenType::Eq => ComparisonOperator::Eq,
            TokenType::Neq => ComparisonOperator::Neq,
            TokenType::Gt => ComparisonOperator::Gt,
            TokenType::Lt => ComparisonOperator::Lt,
            default => null,
        };

        if ($op === null) {
            return $left;
        }

        $this->advance();
        $right = $this->parseOperand();

        if ($left->kind() !== NodeKind::Value || $right->kind() !== NodeKind::Value) {
            throw ExpressionSyntaxException::nonValueOperand('a comparison operand');
        }

        return new ComparisonNode($op, $left, $right);
    }

    private function parseOperand(): Node
    {
        $token = $this->peek();

        if ($token->type === TokenType::LParen) {
            $this->descend();
            $this->advance();
            $inner = $this->parseExpr();
            $this->expect(TokenType::RParen);
            $this->ascend();

            return $inner;
        }

        if ($token->type === TokenType::Word) {
            return $this->parseWordOperand($token);
        }

        if ($token->type === TokenType::FieldRef) {
            $this->advance();

            return new FieldReferenceNode($token->lexeme);
        }

        if ($token->type === TokenType::SelfRef) {
            $this->advance();

            return new SelfReferenceNode;
        }

        if ($token->type === TokenType::Number) {
            $this->advance();

            return new LiteralNode(LiteralKind::Number, (float) $token->lexeme);
        }

        if ($token->type === TokenType::Minus) {
            $this->advance();

            if (! $this->check(TokenType::Number)) {
                throw $this->unexpected();
            }

            $number = $this->advance();

            return new LiteralNode(LiteralKind::Number, -1.0 * (float) $number->lexeme);
        }

        if ($token->type === TokenType::String) {
            $this->advance();

            return new LiteralNode(LiteralKind::String, $token->lexeme);
        }

        throw $this->unexpected();
    }

    private function parseWordOperand(Token $token): Node
    {
        $followedByCall = $this->tokens[$this->pos + 1]->type === TokenType::LParen;

        if (! $followedByCall) {
            throw $this->unexpected(); // a bare word is never a valid operand
        }

        if ($token->lexeme !== 'selected' || ! $this->functions->isPublic('selected')) {
            throw ExpressionSyntaxException::unknownFunction($token->lexeme);
        }

        return $this->parseSelected();
    }

    private function parseSelected(): Node
    {
        $this->descend();
        $this->advance(); // 'selected'
        $this->advance(); // '('

        $first = $this->parseOperand();

        if ($first->kind() !== NodeKind::Value) {
            throw ExpressionSyntaxException::nonValueOperand('a selected() argument');
        }

        if (! $this->check(TokenType::Comma)) {
            throw ExpressionSyntaxException::arityMismatch('selected', 2, 1);
        }
        $this->advance(); // ','

        if (! $this->check(TokenType::String)) {
            throw $this->unexpected(); // the value argument must be a string literal
        }
        $literal = $this->advance();
        $second = new LiteralNode(LiteralKind::String, $literal->lexeme);

        if ($this->check(TokenType::Comma)) {
            throw ExpressionSyntaxException::arityMismatch('selected', 2, 3);
        }

        $this->expect(TokenType::RParen);
        $this->ascend();

        return new FunctionCallNode('selected', [$first, $second]);
    }

    private function descend(): void
    {
        if (++$this->depth > self::MAX_PARSE_DEPTH) {
            throw ExpressionSyntaxException::tooDeeplyNested($this->depth);
        }
    }

    private function ascend(): void
    {
        $this->depth--;
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    /** @phpstan-impure — advances the mutable cursor; an impure point that resets remembered peek/check results. */
    private function advance(): Token
    {
        $token = $this->tokens[$this->pos];

        if ($this->pos < count($this->tokens) - 1) {
            $this->pos++;
        }

        return $token;
    }

    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function isWord(string $lexeme): bool
    {
        $token = $this->peek();

        return $token->type === TokenType::Word && $token->lexeme === $lexeme;
    }

    private function expect(TokenType $type): Token
    {
        if (! $this->check($type)) {
            throw $this->unexpected();
        }

        return $this->advance();
    }

    private function unexpected(): ExpressionSyntaxException
    {
        $token = $this->peek();

        return ExpressionSyntaxException::unexpectedToken($token->lexeme, $token->position);
    }
}
