<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Services\Expressions\Ast\ArithmeticNode;
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
 *
 * Grammar v2.0 (Increment G3) adds arithmetic (`+ - * /`, NaN-propagating, division-by-zero → NaN → blank),
 * the `>=`/`<=` numeric comparators, and the value function library (`if`, `count`, `int`, `today`, `now`).
 * A NaN arithmetic result normalises to `null` at the value boundary (a blank computed value).
 */
final class ExpressionEvaluator
{
    public const GRAMMAR_VERSION = '2.0';

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
        if ($value === Marker::Absent) {
            return null;
        }

        // A NaN arithmetic result (empty/non-numeric operand or division by zero) is a blank value.
        if (is_float($value) && is_nan($value)) {
            return null;
        }

        return $value;
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

        if ($node instanceof ArithmeticNode) {
            return $this->evalArithmetic($node, $context);
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
            ComparisonOperator::Gt, ComparisonOperator::Lt,
            ComparisonOperator::Gte, ComparisonOperator::Lte => $this->numericCompare($left, $right, $node->op),
            ComparisonOperator::Eq => $this->equals($node->left, $node->right, $left, $right),
            ComparisonOperator::Neq => ! $this->equals($node->left, $node->right, $left, $right),
            default => throw ExpressionEvaluationException::unevaluable('comparison operator '.$node->op->value),
        };
    }

    /** Numeric-only ordering: a non-numeric (or NaN) operand makes any of `> < >= <=` false. */
    private function numericCompare(mixed $left, mixed $right, ComparisonOperator $op): bool
    {
        $a = Coercion::toNumber($left);
        $b = Coercion::toNumber($right);

        if (is_nan($a) || is_nan($b)) {
            return false;
        }

        return match ($op) {
            ComparisonOperator::Gt => $a > $b,
            ComparisonOperator::Lt => $a < $b,
            ComparisonOperator::Gte => $a >= $b,
            ComparisonOperator::Lte => $a <= $b,
            default => false,
        };
    }

    /**
     * `+ - * /` over `toNumber` of each operand. A NaN operand (empty / non-numeric) or a division by zero
     * yields NaN — a blank value at the boundary — never an exception (the interpreter stays total).
     */
    private function evalArithmetic(ArithmeticNode $node, EvaluationContext $context): float
    {
        $a = Coercion::toNumber($this->eval($node->left, $context));
        $b = Coercion::toNumber($this->eval($node->right, $context));

        if (is_nan($a) || is_nan($b)) {
            return NAN;
        }

        return match ($node->op) {
            ArithmeticOperator::Add => $a + $b,
            ArithmeticOperator::Sub => $a - $b,
            ArithmeticOperator::Mul => $a * $b,
            ArithmeticOperator::Div => $b === 0.0 ? NAN : $a / $b,
        };
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

    private function evalFunction(FunctionCallNode $node, EvaluationContext $context): mixed
    {
        return match ($node->name) {
            'selected', 'contains' => $this->evalMembershipFunction($node, $context),
            'if' => $this->evalIf($node, $context),
            'count' => $this->countOf($this->eval($this->arg($node, 0), $context)),
            'int' => $this->intCast($this->eval($this->arg($node, 0), $context)),
            'today' => $this->today($context),
            'now' => $context->hasNow() ? $context->now : Marker::Absent,
            default => throw ExpressionEvaluationException::unevaluable('function '.$node->name),
        };
    }

    private function evalMembershipFunction(FunctionCallNode $node, EvaluationContext $context): bool
    {
        $value = $this->eval($this->arg($node, 0), $context);
        $needle = Coercion::toStr($this->eval($this->arg($node, 1), $context));

        return match ($node->name) {
            'selected' => $this->membership($value, $needle),
            // Internal, lowering-only (F3): array membership, else substring on a scalar.
            'contains' => is_array($value)
                ? $this->membership($value, $needle)
                : (! Coercion::isEmpty($value) && str_contains(Coercion::toStr($value), $needle)),
            default => throw ExpressionEvaluationException::unevaluable('function '.$node->name),
        };
    }

    /** `if(cond, then, else)` — short-circuit: only the taken branch is evaluated. */
    private function evalIf(FunctionCallNode $node, EvaluationContext $context): mixed
    {
        return Coercion::toBool($this->eval($this->arg($node, 0), $context))
            ? $this->eval($this->arg($node, 1), $context)
            : $this->eval($this->arg($node, 2), $context);
    }

    /** `count(x)` — an array's length (repeat instances / multi-select), 0 for empty, 1 for a non-empty scalar. */
    private function countOf(mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        return Coercion::isEmpty($value) ? 0 : 1;
    }

    /** `int(x)` — the PHP `(int)` truncation-toward-zero of x's numeric value; a non-numeric value → 0. */
    private function intCast(mixed $value): int
    {
        $number = Coercion::toNumber($value);

        return is_nan($number) ? 0 : (int) $number;
    }

    /** `today()` — the date portion (YYYY-MM-DD) of the injected clock; Absent when no clock is set. */
    private function today(EvaluationContext $context): mixed
    {
        return $context->hasNow() ? substr((string) $context->now, 0, 10) : Marker::Absent;
    }

    private function arg(FunctionCallNode $node, int $index): Node
    {
        return $node->args[$index] ?? throw ExpressionEvaluationException::unevaluable("{$node->name} is missing argument ".($index + 1));
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
