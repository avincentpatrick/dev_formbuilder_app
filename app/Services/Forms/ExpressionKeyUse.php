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
 *
 * `M134` (`R-62b638e1`, `R-87160c81`) split the two coarse flags without changing them, because the census reads
 * only `present`, `numeric` and `list`: `numeric` is still exactly `ordering` or `arithmetic` (an ordering, or
 * arithmetic / `int()`), and `list` still includes `equality` (an `=` or `!=` against anything but `''`). `against`
 * holds the OTHER side of every ordering that names the key, so the gate can judge a date against what it is
 * compared with — the one place a pair, not a position, decides.
 */
final class ExpressionKeyUse
{
    /**
     * @param  bool  $selfIsKey  whether `.` names $key too — true only on that field's own constraint
     * @return array{present: bool, numeric: bool, list: bool, ordering: bool, arithmetic: bool, equality: bool, against: list<Node>}
     */
    public static function of(Node $node, string $key, bool $selfIsKey): array
    {
        $use = ['present' => false, 'numeric' => false, 'list' => false, 'ordering' => false, 'arithmetic' => false, 'equality' => false, 'against' => []];
        self::walk($node, $key, $selfIsKey, $use);

        return $use;
    }

    /**
     * Mark how $node uses the key. A direct use is classified by the position it sits in; anything else is
     * walked, so `${k} + 1 > 3` is a numeric use through the arithmetic, and parentheses are already gone.
     *
     * @param  array{present: bool, numeric: bool, list: bool, ordering: bool, arithmetic: bool, equality: bool, against: list<Node>}  $use
     */
    private static function walk(Node $node, string $key, bool $selfIsKey, array &$use): void
    {
        if ($node instanceof ComparisonNode) {
            self::classify($node->left, self::comparisonUse($node->op, $node->right), $key, $selfIsKey, $use, $node->right);
            self::classify($node->right, self::comparisonUse($node->op, $node->left), $key, $selfIsKey, $use, $node->left);
        } elseif ($node instanceof ArithmeticNode) {
            self::classify($node->left, ['numeric', 'arithmetic'], $key, $selfIsKey, $use);
            self::classify($node->right, ['numeric', 'arithmetic'], $key, $selfIsKey, $use);
        } elseif ($node instanceof FunctionCallNode) {
            $kinds = match ($node->name) {
                'contains', 'count' => ['list'],
                'int' => ['numeric', 'arithmetic'],
                default => [], // `selected()` reads a list and a value alike; `if()` passes its value through
            };

            foreach ($node->args as $arg) {
                self::classify($arg, $kinds, $key, $selfIsKey, $use);
            }
        } elseif ($node instanceof LogicalNode) {
            self::classify($node->left, [], $key, $selfIsKey, $use);
            self::classify($node->right, [], $key, $selfIsKey, $use);
        } elseif ($node instanceof NotNode) {
            self::classify($node->operand, [], $key, $selfIsKey, $use);
        }
    }

    /**
     * @param  list<'numeric'|'list'|'ordering'|'arithmetic'|'equality'>  $kinds
     * @param  array{present: bool, numeric: bool, list: bool, ordering: bool, arithmetic: bool, equality: bool, against: list<Node>}  $use
     * @param  Node|null  $other  the other side, when $operand is one side of a comparison
     */
    private static function classify(Node $operand, array $kinds, string $key, bool $selfIsKey, array &$use, ?Node $other = null): void
    {
        $named = ($operand instanceof FieldReferenceNode && $operand->key === $key)
            || ($selfIsKey && $operand instanceof SelfReferenceNode);

        if (! $named) {
            self::walk($operand, $key, $selfIsKey, $use);

            return;
        }

        $use['present'] = true;

        foreach ($kinds as $kind) {
            $use[$kind] = true;
        }

        if ($other !== null && in_array('ordering', $kinds, true)) {
            $use['against'][] = $other;
        }
    }

    /**
     * How a comparison uses one side, given the other. `= ''` and `!= ''` are emptiness tests, valid on a
     * list and a value alike (`ExpressionEvaluator` decides emptiness before its array rule), so only an
     * equality against something else reads a list differently from one value.
     *
     * @return list<'numeric'|'list'|'ordering'|'equality'>
     */
    private static function comparisonUse(ComparisonOperator $operator, Node $other): array
    {
        $againstEmpty = $other instanceof LiteralNode && $other->isEmptyStringLiteral();

        return match ($operator) {
            ComparisonOperator::Gt, ComparisonOperator::Lt,
            ComparisonOperator::Gte, ComparisonOperator::Lte => ['numeric', 'ordering'],
            ComparisonOperator::Eq, ComparisonOperator::Neq => $againstEmpty ? [] : ['list', 'equality'],
            ComparisonOperator::Contains => $againstEmpty ? [] : ['list'],
            ComparisonOperator::IsNull => [],
        };
    }
}
