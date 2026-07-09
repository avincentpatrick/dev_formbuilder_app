<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Exceptions\Expressions\ExpressionEvaluationException;
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
 * The PHP authoritative tree-walking interpreter (technical-architecture.md §4.3 §6) — the SOLE authority
 * at submission time. Total over data: empty / missing / type-mismatch yield a defined falsy result,
 * never an exception (a `constraint` returning false is a validation RESULT, not an error). No eval(), no
 * call_user_func: node dispatch is `instanceof`, operator/function dispatch is `match` over a closed set.
 * The §6 rules here are the normative contract the TypeScript client mirror reproduces byte-for-byte.
 */
final class ExpressionEvaluator
{
    public const GRAMMAR_VERSION = '1.0';

    public function __construct(
        private readonly ExpressionParser $parser,
    ) {}

    /**
     * Parse (memoised) + interpret; the raw node value with the internal Absent sentinel normalised to
     * null at the boundary.
     *
     * @throws ExpressionSyntaxException
     */
    public function evaluate(string $expression, EvaluationContext $context): mixed
    {
        return $this->normalize($this->eval($this->parser->parse($expression), $context));
    }

    /** Interpret a pre-parsed AST (raw value, Absent normalised to null). */
    public function evaluateNode(Node $ast, EvaluationContext $context): mixed
    {
        return $this->normalize($this->eval($ast, $context));
    }

    /**
     * The authoritative relevant/constraint form: toBool(evaluate-internal(...)).
     *
     * @throws ExpressionSyntaxException
     */
    public function evaluateBoolean(string $expression, EvaluationContext $context): bool
    {
        return Coercion::toBool($this->eval($this->parser->parse($expression), $context));
    }

    /** Version-scoped convenience (memo namespaced by form_version_id). */
    public function evaluateForVersion(string $formVersionId, string $expression, EvaluationContext $context): mixed
    {
        return $this->normalize($this->eval($this->parser->parseForVersion($formVersionId, $expression), $context));
    }

    private function normalize(mixed $value): mixed
    {
        return $value === Marker::Absent ? null : $value;
    }

    private function eval(Node $node, EvaluationContext $context): mixed
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        if ($node instanceof FieldReferenceNode) {
            return $context->has($node->key) ? $context->answers[$node->key] : Marker::Absent;
        }

        if ($node instanceof SelfReferenceNode) {
            return $context->hasSelf() ? $context->self : Marker::Absent;
        }

        if ($node instanceof NotNode) {
            return ! Coercion::toBool($this->eval($node->operand, $context));
        }

        if ($node instanceof LogicalNode) {
            return $this->evalLogical($node, $context);
        }

        if ($node instanceof ComparisonNode) {
            return $this->evalComparison($node, $context);
        }

        if ($node instanceof FunctionCallNode) {
            return $this->evalFunction($node, $context);
        }

        throw ExpressionEvaluationException::unevaluable($node::class);
    }

    private function evalLogical(LogicalNode $node, EvaluationContext $context): bool
    {
        // Short-circuit: the right operand is not evaluated when the left already decides the result.
        return $node->op === LogicOperator::And
            ? Coercion::toBool($this->eval($node->left, $context)) && Coercion::toBool($this->eval($node->right, $context))
            : Coercion::toBool($this->eval($node->left, $context)) || Coercion::toBool($this->eval($node->right, $context));
    }

    private function evalComparison(ComparisonNode $node, EvaluationContext $context): bool
    {
        $left = $this->eval($node->left, $context);
        $right = $this->eval($node->right, $context);

        return match ($node->op) {
            ComparisonOperator::Gt => $this->numericCompare($left, $right, true),
            ComparisonOperator::Lt => $this->numericCompare($left, $right, false),
            ComparisonOperator::Eq => $this->equals($node->left, $node->right, $left, $right),
            ComparisonOperator::Neq => ! $this->equals($node->left, $node->right, $left, $right),
            default => throw ExpressionEvaluationException::unevaluable('comparison operator '.$node->op->value),
        };
    }

    private function numericCompare(mixed $left, mixed $right, bool $greater): bool
    {
        $a = Coercion::toNumber($left);
        $b = Coercion::toNumber($right);

        if (is_nan($a) || is_nan($b)) {
            return false;
        }

        return $greater ? $a > $b : $a < $b;
    }

    private function equals(Node $leftNode, Node $rightNode, mixed $left, mixed $right): bool
    {
        // 1/2: an empty string literal on either side is an emptiness test.
        if ($leftNode instanceof LiteralNode && $leftNode->isEmptyStringLiteral()) {
            return Coercion::isEmpty($right);
        }

        if ($rightNode instanceof LiteralNode && $rightNode->isEmptyStringLiteral()) {
            return Coercion::isEmpty($left);
        }

        // 3: arrays compare only via emptiness (above) or selected(); anything else is false.
        if (is_array($left) || is_array($right)) {
            return false;
        }

        // 4: a quoted string literal on either side forces string comparison.
        if ($this->isStringLiteral($leftNode) || $this->isStringLiteral($rightNode)) {
            return Coercion::toStr($left) === Coercion::toStr($right);
        }

        // 5: both numeric-like → exact numeric equality (safe in Phase 1: no arithmetic).
        if (Coercion::isNumericLike($left) && Coercion::isNumericLike($right)) {
            return Coercion::toNumber($left) === Coercion::toNumber($right);
        }

        // 6: fall back to string equality.
        return Coercion::toStr($left) === Coercion::toStr($right);
    }

    private function isStringLiteral(Node $node): bool
    {
        return $node instanceof LiteralNode && $node->isStringLiteral();
    }

    private function evalFunction(FunctionCallNode $node, EvaluationContext $context): bool
    {
        $target = $node->args[0] ?? throw ExpressionEvaluationException::unevaluable("{$node->name} requires two arguments");
        $literalNode = $node->args[1] ?? throw ExpressionEvaluationException::unevaluable("{$node->name} requires two arguments");

        $value = $this->eval($target, $context);
        $needle = Coercion::toStr($this->eval($literalNode, $context));

        return match ($node->name) {
            'selected' => $this->membership($value, $needle),
            // Internal, lowering-only (F3): array membership, else substring on a scalar.
            'contains' => is_array($value)
                ? $this->membership($value, $needle)
                : (! Coercion::isEmpty($value) && str_contains(Coercion::toStr($value), $needle)),
            default => throw ExpressionEvaluationException::unevaluable('function '.$node->name),
        };
    }

    private function membership(mixed $value, string $needle): bool
    {
        if (is_array($value)) {
            return in_array($needle, array_map(static fn (mixed $item): string => Coercion::toStr($item), $value), true);
        }

        if (Coercion::isEmpty($value)) {
            return false;
        }

        return Coercion::toStr($value) === $needle;
    }
}
