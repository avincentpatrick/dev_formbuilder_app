<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ComparisonOperator;
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
 * How one parsed expression USES one key: whether it is named at all, whether it is read as a NUMBER (an ordering,
 * arithmetic, `int()`), and whether it is read as a LIST (`contains`, `count()`, an equality against a value).
 *
 * ⛔ ONE WALKER, TWO READERS. Moved verbatim out of {@see ConversionCensus} by `M126` (`R-87160c81`), so that the
 * census — which asks what a type change would re-mean — and {@see ExpressionValidationGate} — which refuses an
 * ordering that can never hold — classify a use the same way. A second copy of this walk in the gate would be one
 * more unguarded mirror.
 */
final class ExpressionKeyUse
{
    /**
     * @param  bool  $selfIsKey  whether `.` names $key too — true only on that field's own constraint
     * @return array{present: bool, numeric: bool, list: bool}
     */
    public static function of(Node $node, string $key, bool $selfIsKey): array
    {
        $use = ['present' => false, 'numeric' => false, 'list' => false];
        self::walk($node, $key, $selfIsKey, $use);

        return $use;
    }

    /**
     * Mark how $node uses the key. A direct use is classified by the position it sits in; anything else is
     * walked, so `${k} + 1 > 3` is a numeric use through the arithmetic, and parentheses are already gone.
     *
     * @param  array{present: bool, numeric: bool, list: bool}  $use
     */
    private static function walk(Node $node, string $key, bool $selfIsKey, array &$use): void
    {
        if ($node instanceof ComparisonNode) {
            self::classify($node->left, self::comparisonUse($node->op, $node->right), $key, $selfIsKey, $use);
            self::classify($node->right, self::comparisonUse($node->op, $node->left), $key, $selfIsKey, $use);
        } elseif ($node instanceof ArithmeticNode) {
            self::classify($node->left, 'numeric', $key, $selfIsKey, $use);
            self::classify($node->right, 'numeric', $key, $selfIsKey, $use);
        } elseif ($node instanceof FunctionCallNode) {
            $kind = match ($node->name) {
                'contains', 'count' => 'list',
                'int' => 'numeric',
                default => null, // `selected()` reads a list and a value alike; `if()` passes its value through
            };

            foreach ($node->args as $arg) {
                self::classify($arg, $kind, $key, $selfIsKey, $use);
            }
        } elseif ($node instanceof LogicalNode) {
            self::classify($node->left, null, $key, $selfIsKey, $use);
            self::classify($node->right, null, $key, $selfIsKey, $use);
        } elseif ($node instanceof NotNode) {
            self::classify($node->operand, null, $key, $selfIsKey, $use);
        }
    }

    /**
     * @param  'numeric'|'list'|null  $kind
     * @param  array{present: bool, numeric: bool, list: bool}  $use
     */
    private static function classify(Node $operand, ?string $kind, string $key, bool $selfIsKey, array &$use): void
    {
        $named = ($operand instanceof FieldReferenceNode && $operand->key === $key)
            || ($selfIsKey && $operand instanceof SelfReferenceNode);

        if (! $named) {
            self::walk($operand, $key, $selfIsKey, $use);

            return;
        }

        $use['present'] = true;

        if ($kind !== null) {
            $use[$kind] = true;
        }
    }

    /**
     * How a comparison uses one side, given the other. `= ''` and `!= ''` are emptiness tests, valid on a
     * list and a value alike (`ExpressionEvaluator` decides emptiness before its array rule), so only an
     * equality against something else reads a list differently from one value.
     *
     * @return 'numeric'|'list'|null
     */
    private static function comparisonUse(ComparisonOperator $operator, Node $other): ?string
    {
        return match ($operator) {
            ComparisonOperator::Gt, ComparisonOperator::Lt,
            ComparisonOperator::Gte, ComparisonOperator::Lte => 'numeric',
            ComparisonOperator::Eq, ComparisonOperator::Neq,
            ComparisonOperator::Contains => $other instanceof LiteralNode && $other->isEmptyStringLiteral() ? null : 'list',
            ComparisonOperator::IsNull => null,
        };
    }
}
