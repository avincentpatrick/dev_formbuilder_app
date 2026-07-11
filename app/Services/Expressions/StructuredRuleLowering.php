<?php

declare(strict_types=1);

namespace App\Services\Expressions;

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Models\FormFieldValidation;
use App\Services\Expressions\Ast\LogicalNode;
use App\Services\Expressions\Ast\Node;

/**
 * Lowers a structured `form_field_validations` row into the SAME AST the parser would emit
 * (technical-architecture.md §4.3 §6.7), so structured and expression rules share one evaluator. Rows are
 * discriminated by `rule_type` (a DB CHECK makes it non-null whenever `expression` is null). F2 lowers
 * exactly the two field-vs-field comparisons — `greater_than_field` / `less_than_field` — using
 * `related_form_field_id`; every other rule type is F3's (a caller-contract violation here).
 *
 * Compound `logic_group` folding is FLAT and LEFT-ASSOCIATIVE (rows carry no parentheses, so — unlike the
 * expression grammar — `and`/`or` are NOT re-precedenced): fold ascending by `sequence`, ties by `id`;
 * each row (from the second) joins with ITS OWN `logic_operator`.
 */
final class StructuredRuleLowering
{
    /**
     * @param  array<string, string>  $fieldKeysById  form_field id → key, from the version's field set
     */
    public function lower(FormFieldValidation $row, array $fieldKeysById): Node
    {
        return match ($row->rule_type) {
            ValidationRuleType::GreaterThanField => $this->fieldComparison($row, $fieldKeysById, true),
            ValidationRuleType::LessThanField => $this->fieldComparison($row, $fieldKeysById, false),
            default => throw ExpressionEvaluationException::unlowerableRuleType($this->ruleTypeLabel($row)),
        };
    }

    /**
     * @param  list<FormFieldValidation>  $rows  one logic group's rows
     * @param  array<string, string>  $fieldKeysById
     */
    public function lowerGroup(array $rows, array $fieldKeysById): Node
    {
        if ($rows === []) {
            throw ExpressionEvaluationException::unevaluable('empty logic group');
        }

        usort($rows, static fn (FormFieldValidation $a, FormFieldValidation $b): int => [$a->sequence, $a->id] <=> [$b->sequence, $b->id]);

        $accumulator = $this->lower($rows[0], $fieldKeysById);

        foreach (array_slice($rows, 1) as $row) {
            $operator = $this->requireOperator($row->logic_operator, $row->id);
            $accumulator = new LogicalNode($operator, $accumulator, $this->lower($row, $fieldKeysById));
        }

        return $accumulator;
    }

    /**
     * Lower a conditional-family row (required_if / required_with / skip_if / skip_with) to its CONDITION
     * AST — the boolean the caller (F3's SemanticValidator) reads to gate requiredness or field skipping.
     * The condition compares the RELATED field (`related_form_field_id`), not the owning field. An `_if`
     * row always dispatches by `operator`; a `_with` row defaults to `isNotNull(related)` ("the related
     * field is answered") when no operator is set. For F3 (F2 lowered only the two field-vs-field rules).
     *
     * @param  array<string, string>  $fieldKeysById  form_field id → key
     */
    public function lowerCondition(FormFieldValidation $row, array $fieldKeysById): Node
    {
        $relatedKey = $this->relatedKeyOrThrow($row, $fieldKeysById);
        $value = (string) ($row->rule_value ?? '');

        return match ($row->rule_type) {
            ValidationRuleType::RequiredIf, ValidationRuleType::SkipIf => $this->conditionForOperator($row->operator, $relatedKey, $value, $row->id),
            ValidationRuleType::RequiredWith, ValidationRuleType::SkipWith => $row->operator === null
                ? AstBuilders::isNotNull($relatedKey)
                : $this->conditionForOperator($row->operator, $relatedKey, $value, $row->id),
            default => throw ExpressionEvaluationException::unlowerableRuleType($this->ruleTypeLabel($row)),
        };
    }

    /**
     * Fold a conditional `logic_group`'s rows into one condition AST — same flat, left-associative,
     * `[sequence, id]`-ordered discipline as {@see lowerGroup()} (rows carry no parentheses).
     *
     * @param  list<FormFieldValidation>  $rows  one logic group's rows
     * @param  array<string, string>  $fieldKeysById
     */
    public function lowerConditionGroup(array $rows, array $fieldKeysById): Node
    {
        if ($rows === []) {
            throw ExpressionEvaluationException::unevaluable('empty logic group');
        }

        usort($rows, static fn (FormFieldValidation $a, FormFieldValidation $b): int => [$a->sequence, $a->id] <=> [$b->sequence, $b->id]);

        $accumulator = $this->lowerCondition($rows[0], $fieldKeysById);

        foreach (array_slice($rows, 1) as $row) {
            $operator = $this->requireOperator($row->logic_operator, $row->id);
            $accumulator = new LogicalNode($operator, $accumulator, $this->lowerCondition($row, $fieldKeysById));
        }

        return $accumulator;
    }

    /**
     * Map a conditional row's comparison `operator` (+ related key + literal value) to the SAME AST the
     * equivalent parsed condition would yield. Nullable so a missing operator on an `_if` row survives
     * Larastan's non-null enum-cast inference and is caught here rather than mis-lowered.
     */
    private function conditionForOperator(?ComparisonOperator $operator, string $relatedKey, string $value, string $rowId): Node
    {
        return match ($operator) {
            ComparisonOperator::Eq, ComparisonOperator::Neq,
            ComparisonOperator::Gt, ComparisonOperator::Lt,
            ComparisonOperator::Gte, ComparisonOperator::Lte => AstBuilders::comparison($operator, $relatedKey, $value),
            ComparisonOperator::IsNull => AstBuilders::isNull($relatedKey),
            ComparisonOperator::Contains => AstBuilders::contains($relatedKey, $value),
            null => throw ExpressionEvaluationException::unevaluable("conditional rule {$rowId} has no operator"),
        };
    }

    /**
     * @param  array<string, string>  $fieldKeysById
     */
    private function relatedKeyOrThrow(FormFieldValidation $row, array $fieldKeysById): string
    {
        $relatedId = $row->related_form_field_id;
        $relatedKey = $relatedId !== null ? ($fieldKeysById[$relatedId] ?? null) : null;

        if ($relatedKey === null) {
            throw ExpressionEvaluationException::missingRelatedField($fieldKeysById[$row->form_field_id] ?? $row->form_field_id);
        }

        return $relatedKey;
    }

    /**
     * A non-first grouped row must carry its own connective (the fold has nothing to join it with
     * otherwise). Widening the parameter to nullable lets a NULL survive Larastan's non-null enum-cast
     * inference so the malformed-data guard stays reachable.
     */
    private function requireOperator(?LogicOperator $operator, string $rowId): LogicOperator
    {
        return $operator ?? throw ExpressionEvaluationException::malformedLogicGroup($rowId);
    }

    /** Reads the raw stored rule_type (bypassing the enum cast → mixed) so the error label needs no enum-nullability juggling. */
    private function ruleTypeLabel(FormFieldValidation $row): string
    {
        $raw = $row->getAttributes()['rule_type'] ?? null;

        return is_string($raw) ? $raw : 'expression';
    }

    /**
     * @param  array<string, string>  $fieldKeysById
     */
    private function fieldComparison(FormFieldValidation $row, array $fieldKeysById, bool $greater): Node
    {
        $fieldKey = $fieldKeysById[$row->form_field_id] ?? null;
        $relatedId = $row->related_form_field_id;
        $relatedKey = $relatedId !== null ? ($fieldKeysById[$relatedId] ?? null) : null;

        if ($fieldKey === null || $relatedKey === null) {
            throw ExpressionEvaluationException::missingRelatedField($fieldKey ?? $row->form_field_id);
        }

        return $greater
            ? AstBuilders::fieldGt($fieldKey, $relatedKey)
            : AstBuilders::fieldLt($fieldKey, $relatedKey);
    }
}
