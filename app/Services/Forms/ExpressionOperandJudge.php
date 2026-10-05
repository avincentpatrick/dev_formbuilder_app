<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\OperandKind;
use App\Exceptions\Forms\PublishValidationException;
use App\Services\Expressions\Ast\ArithmeticNode;
use App\Services\Expressions\Ast\FieldReferenceNode;
use App\Services\Expressions\Ast\FunctionCallNode;
use App\Services\Expressions\Ast\LiteralNode;
use App\Services\Expressions\Ast\Node;
use App\Services\Expressions\Ast\SelfReferenceNode;
use App\Services\Expressions\Temporal;

/**
 * Does one question's use in one expression ever change with the answer? (Increments M126 and M134.) The pure
 * judge behind {@see ExpressionValidationGate::orderingViolations()}: it reads the use {@see ExpressionKeyUse}
 * recorded — the one walker — and the question's {@see OperandKind}, and returns the refusal, if any. It walks no
 * expression itself, so the census and the gate cannot disagree about what a use is.
 *
 * WHAT IT REFUSES, each because the comparison is CONSTANT in both engines:
 *   - a number use (ordering, arithmetic, `int()`) of a question that is never a number (`M126`);
 *   - for a date, time or date-time (`M134`, `R-62b638e1`, `D71`, `D89`): arithmetic and `int()` ("not supported
 *     yet"); an ordering against `now()` or against a value carrying a time zone ("not supported yet" — an answer
 *     carries no zone and `now()` is UTC); and an ordering against something of another kind — a number, a time
 *     against a date, text that is not an ISO date — which the engines' {@see Temporal} reads as never holding.
 *
 * WHAT IT ALLOWS: a temporal question ordered against a compatible temporal question, `today()` (for a date or a
 * date-time), a literal of the same kind, or a text, hidden, single-choice or calculated question — those can hold
 * an ISO date, so the gate does not guess. A question refused elsewhere (grid and geo by `check()`; a never-number
 * question by its own arm) is not reported a second time from the other side of a pair.
 */
final class ExpressionOperandJudge
{
    /**
     * @param  array{present: bool, numeric: bool, list: bool, ordering: bool, arithmetic: bool, equality: bool, against: list<Node>}  $use
     * @param  array<string, FieldType>  $typeByKey
     */
    public static function judge(string $ownerKey, string $key, FieldType $type, array $use, ?string $selfKey, array $typeByKey): ?PublishValidationException
    {
        $kind = OperandKind::for($type);

        if ($kind->isTemporal()) {
            return self::temporal($ownerKey, $key, $type, $kind, $use, $selfKey, $typeByKey);
        }

        if ($kind->numberNeverHeld() === 'non_number' && $use['numeric']) {
            return PublishValidationException::expressionOrdersNonNumber($ownerKey, $key, $type->label());
        }

        return null;
    }

    /**
     * @param  array{present: bool, numeric: bool, list: bool, ordering: bool, arithmetic: bool, equality: bool, against: list<Node>}  $use
     * @param  array<string, FieldType>  $typeByKey
     */
    private static function temporal(string $ownerKey, string $key, FieldType $type, OperandKind $kind, array $use, ?string $selfKey, array $typeByKey): ?PublishValidationException
    {
        if ($use['arithmetic']) {
            return PublishValidationException::expressionOrdersDate($ownerKey, $key, $type->label());
        }

        foreach ($use['against'] as $other) {
            $otherKey = match (true) {
                $other instanceof FieldReferenceNode => $other->key,
                $other instanceof SelfReferenceNode => $selfKey,
                default => null,
            };

            if ($otherKey !== null) {
                $otherType = $typeByKey[$otherKey] ?? null;

                if ($otherType === null || $otherKey === $key) {
                    continue; // an unknown key is check()'s refusal; a question against itself is the same kind
                }

                $otherKind = OperandKind::for($otherType);

                if ($otherKind->isTemporal()) {
                    // Both sides are dates or times: report once, under the key that sorts first.
                    if (! $kind->ordersTemporallyWith($otherKind) && strcmp($key, $otherKey) < 0) {
                        return PublishValidationException::expressionOrdersTemporalMismatch($ownerKey, $key, $type->label(), "the {$otherType->label()} question “{$otherKey}”", self::accepts($kind));
                    }

                    continue;
                }

                if ($otherKind === OperandKind::Number) {
                    return PublishValidationException::expressionOrdersTemporalMismatch($ownerKey, $key, $type->label(), "the {$otherType->label()} question “{$otherKey}”", self::accepts($kind));
                }

                continue; // text, hidden, a single choice or a calculation may hold a date; anything else is refused by its own arm
            }

            $verdict = self::againstValue($kind, $other);

            if ($verdict === 'clock') {
                return PublishValidationException::expressionOrdersDateAgainstClock($ownerKey, $key, $type->label());
            }

            if ($verdict !== null) {
                return PublishValidationException::expressionOrdersTemporalMismatch($ownerKey, $key, $type->label(), $verdict, self::accepts($kind));
            }
        }

        return null;
    }

    /**
     * A temporal question ordered against something that is not a question: null when it can hold, `clock` when it
     * is a time-zone question (`D89`), or the phrase naming what it was compared with.
     */
    private static function againstValue(OperandKind $kind, Node $other): ?string
    {
        if ($other instanceof FunctionCallNode) {
            return match ($other->name) {
                'now' => 'clock',
                'today' => $kind === OperandKind::Time ? 'today()' : null,
                'if' => null, // its value is whichever branch is taken; the gate does not guess
                'count', 'int' => "{$other->name}(), which is a number",
                default => "{$other->name}(), which is true or false",
            };
        }

        if ($other instanceof LiteralNode) {
            if (! $other->isStringLiteral()) {
                return 'the number '.self::number((float) $other->value);
            }

            $parsed = Temporal::parse($other->value);

            if ($parsed === null) {
                return "the text “{$other->value}”";
            }

            if ($parsed['zoned']) {
                return 'clock';
            }

            $literalKind = match ($parsed['kind']) {
                'date' => OperandKind::Date,
                'time' => OperandKind::Time,
                'datetime' => OperandKind::Datetime,
            };

            return $kind->ordersTemporallyWith($literalKind) ? null : "the {$parsed['kind']} “{$other->value}”";
        }

        if ($other instanceof ArithmeticNode) {
            return 'a calculation, which is a number';
        }

        return 'a condition, which is true or false';
    }

    /** What a question of this kind CAN be compared with — the refusal's suggestion. */
    private static function accepts(OperandKind $kind): string
    {
        return $kind === OperandKind::Time
            ? 'another time question or a time typed as HH:MM'
            : 'another date question, a date typed as YYYY-MM-DD, or today()';
    }

    private static function number(float $value): string
    {
        return $value === floor($value) && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
    }
}
