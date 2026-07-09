<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Models\FormFieldValidation;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\StructuredRuleLowering;

/**
 * Evaluates one structured `form_field_validations` row (or a folded `logic_group`) — the low-level rule
 * engine {@see SemanticValidator} composes (technical-architecture.md §4.3 §6.7). Three families:
 *
 *  1. Free-text `expression` rows + the two field-vs-field comparisons (`greater_than_field` /
 *     `less_than_field`) → the F2 {@see ExpressionEvaluator} (lowered to the SAME AST a parsed string
 *     would yield, so structured ≡ expression).
 *  2. Self-vs-literal constraints (`min_value` / `max_value` / `min_length` / `max_length` / `pattern`) →
 *     DIRECT {@see Coercion} primitives — the grammar has no `>=`/`<=`, length, or regex, and reusing the
 *     same primitives keeps the TypeScript mirror byte-identical. An empty answer PASSES every one of
 *     these (a constraint is only checked on an answered field; emptiness is the required-check's job).
 *  3. Conditional-family conditions (`required_if` / `_with` / `skip_if` / `_with`) → lowered via
 *     {@see StructuredRuleLowering::lowerCondition()} and read as a boolean gate (NOT a pass/fail).
 *
 * No `eval()`. A false result is a validation RESULT, never an exception; only a structurally-impossible
 * rule (e.g. a conditional row routed to passesConstraint) raises {@see ExpressionEvaluationException}.
 */
final class StructuredRuleEvaluator
{
    private const PATTERN_DELIMITER = '/';

    public function __construct(
        private readonly ExpressionEvaluator $evaluator,
        private readonly StructuredRuleLowering $lowering,
    ) {}

    /**
     * One constraint-producing rule → true = the answer passes. `$context` must carry `self` = the field's
     * own answer so a free-text expression's `.` reference resolves.
     *
     * @param  array<string, string>  $fieldKeysById
     */
    public function passesConstraint(
        FormFieldValidation $row,
        string $fieldKey,
        mixed $answer,
        EvaluationContext $context,
        array $fieldKeysById,
    ): bool {
        // A free-text expression supersedes the structured columns (the DB XOR CHECK); a blank one is a no-op.
        if ($row->expression !== null) {
            return trim($row->expression) === ''
                || Coercion::toBool($this->evaluator->evaluate($row->expression, $context));
        }

        return match ($row->rule_type) {
            ValidationRuleType::GreaterThanField, ValidationRuleType::LessThanField => Coercion::toBool(
                $this->evaluator->evaluateNode($this->lowering->lower($row, $fieldKeysById), $context),
            ),
            ValidationRuleType::MinValue => Coercion::isEmpty($answer)
                || (Coercion::isNumericLike($answer) && Coercion::toNumber($answer) >= Coercion::toNumber($this->ruleValue($row))),
            ValidationRuleType::MaxValue => Coercion::isEmpty($answer)
                || (Coercion::isNumericLike($answer) && Coercion::toNumber($answer) <= Coercion::toNumber($this->ruleValue($row))),
            ValidationRuleType::MinLength => Coercion::isEmpty($answer)
                || mb_strlen(Coercion::toStr($answer), 'UTF-8') >= (int) $this->ruleValue($row),
            ValidationRuleType::MaxLength => Coercion::isEmpty($answer)
                || mb_strlen(Coercion::toStr($answer), 'UTF-8') <= (int) $this->ruleValue($row),
            ValidationRuleType::Pattern => Coercion::isEmpty($answer)
                || $this->matchesPattern($this->ruleValue($row), Coercion::toStr($answer)),
            // Conditional-family rows are not constraints; reaching here is a caller-contract violation.
            default => throw ExpressionEvaluationException::unlowerableRuleType($this->ruleLabel($row)),
        };
    }

    /**
     * A `logic_group` of constraint rows → one boolean, folded flat + left-associative by `[sequence, id]`
     * with each non-first row's own `logic_operator` (same discipline as the lowering fold, but over the
     * boolean RESULTS — self-vs-literal rows are not AST-lowerable, so they cannot fold through the engine).
     *
     * @param  list<FormFieldValidation>  $rows
     * @param  array<string, string>  $fieldKeysById
     */
    public function passesConstraintGroup(
        array $rows,
        string $fieldKey,
        mixed $answer,
        EvaluationContext $context,
        array $fieldKeysById,
    ): bool {
        usort($rows, static fn (FormFieldValidation $a, FormFieldValidation $b): int => [$a->sequence, $a->id] <=> [$b->sequence, $b->id]);

        $result = $this->passesConstraint($rows[0], $fieldKey, $answer, $context, $fieldKeysById);

        foreach (array_slice($rows, 1) as $row) {
            $connective = $this->groupConnective($row->logic_operator, $row->id);
            $rhs = $this->passesConstraint($row, $fieldKey, $answer, $context, $fieldKeysById);
            $result = $connective === LogicOperator::And ? ($result && $rhs) : ($result || $rhs);
        }

        return $result;
    }

    /**
     * A single conditional-family row → does its condition hold (over the current answer context)?
     *
     * @param  array<string, string>  $fieldKeysById
     */
    public function conditionHolds(FormFieldValidation $row, EvaluationContext $context, array $fieldKeysById): bool
    {
        return Coercion::toBool($this->evaluator->evaluateNode($this->lowering->lowerCondition($row, $fieldKeysById), $context));
    }

    /**
     * A conditional `logic_group` → does the folded compound condition hold? All conditional rows ARE
     * AST-lowerable, so this folds through the engine via {@see StructuredRuleLowering::lowerConditionGroup()}.
     *
     * @param  list<FormFieldValidation>  $rows
     * @param  array<string, string>  $fieldKeysById
     */
    public function conditionGroupHolds(array $rows, EvaluationContext $context, array $fieldKeysById): bool
    {
        return Coercion::toBool($this->evaluator->evaluateNode($this->lowering->lowerConditionGroup($rows, $fieldKeysById), $context));
    }

    /**
     * Compile a stored `pattern` `rule_value` into an anchored full-match (the HTML `pattern`-attribute
     * convention: the whole answer must match, Unicode-aware). Only UNescaped delimiters are escaped so an
     * already-escaped `\/` is left alone. Shared with the publish gate so both reject/accept identically.
     */
    public static function compilePattern(string $pattern): string
    {
        $body = preg_replace('~(?<!\\\\)/~', '\\/', $pattern) ?? $pattern;

        return self::PATTERN_DELIMITER.'^(?:'.$body.')$'.self::PATTERN_DELIMITER.'u';
    }

    /** Does a stored `pattern` `rule_value` compile to a usable regex? (publish-gate hardening). */
    public static function isCompilablePattern(string $pattern): bool
    {
        return $pattern !== '' && @preg_match(self::compilePattern($pattern), '') !== false;
    }

    private function matchesPattern(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false; // no pattern → cannot match (fail-closed; the publish gate rejects an empty one)
        }

        // `false` (a bad regex or a hit backtrack limit) is treated as a non-match, never truthy.
        return @preg_match(self::compilePattern($pattern), $subject) === 1;
    }

    private function ruleValue(FormFieldValidation $row): string
    {
        return (string) ($row->rule_value ?? '');
    }

    private function ruleLabel(FormFieldValidation $row): string
    {
        $raw = $row->getAttributes()['rule_type'] ?? null;

        return is_string($raw) ? $raw : 'expression';
    }

    private function groupConnective(?LogicOperator $operator, string $rowId): LogicOperator
    {
        return $operator ?? throw ExpressionEvaluationException::malformedLogicGroup($rowId);
    }
}
