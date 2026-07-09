<?php

declare(strict_types=1);

use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Models\FormFieldValidation;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\StructuredRuleLowering;

/** An in-memory (unsaved) validation row for lowering tests. */
function ffvRow(
    string $id,
    ValidationRuleType $ruleType,
    string $fieldId,
    ?string $relatedId = null,
    int $sequence = 0,
    ?LogicOperator $operator = null,
): FormFieldValidation {
    $row = new FormFieldValidation;
    $row->id = $id;
    $row->rule_type = $ruleType;
    $row->form_field_id = $fieldId;
    $row->related_form_field_id = $relatedId;
    $row->sequence = $sequence;
    $row->logic_operator = $operator;

    return $row;
}

it('lowers greater_than_field / less_than_field to the same result as the parsed expression', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fa' => 'a', 'fb' => 'b'];
    $context = new EvaluationContext(['a' => 5, 'b' => 3]);

    $gt = $lowering->lower(ffvRow('r1', ValidationRuleType::GreaterThanField, 'fa', 'fb'), $map);
    expect($engine->evaluateNode($gt, $context))->toBe($engine->evaluate('${a} > ${b}', $context));

    $lt = $lowering->lower(ffvRow('r2', ValidationRuleType::LessThanField, 'fa', 'fb'), $map);
    expect($engine->evaluateNode($lt, $context))->toBe($engine->evaluate('${a} < ${b}', $context));
});

it('folds a mixed logic group flat and left-associative (no re-precedencing)', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fa' => 'a', 'fb' => 'b', 'fc' => 'c', 'fd' => 'd', 'fe' => 'e', 'ff' => 'f'];
    $context = new EvaluationContext(['a' => 5, 'b' => 3, 'c' => 1, 'd' => 9, 'e' => 8, 'f' => 2]);

    // Deliberately out of order to prove sequence-ordering of the fold.
    $rows = [
        ffvRow('r3', ValidationRuleType::GreaterThanField, 'fe', 'ff', 2, LogicOperator::And),
        ffvRow('r1', ValidationRuleType::GreaterThanField, 'fa', 'fb', 0),
        ffvRow('r2', ValidationRuleType::LessThanField, 'fc', 'fd', 1, LogicOperator::Or),
    ];

    $folded = $lowering->lowerGroup($rows, $map);

    // Flat fold: ((a>b OR c<d) AND e>f) — the canonical string must be explicitly parenthesized.
    $canonical = $engine->evaluate('(${a} > ${b} or ${c} < ${d}) and ${e} > ${f}', $context);
    expect($engine->evaluateNode($folded, $context))->toBe($canonical);
});

it('rejects a rule type it does not own', function (): void {
    try {
        (new StructuredRuleLowering)->lower(ffvRow('r1', ValidationRuleType::MinValue, 'fa'), ['fa' => 'a']);
        $this->fail('expected unlowerable_rule_type');
    } catch (ExpressionEvaluationException $e) {
        expect($e->slug())->toBe('unlowerable_rule_type');
    }
});

it('rejects a field-comparison row with no related field', function (): void {
    try {
        (new StructuredRuleLowering)->lower(ffvRow('r1', ValidationRuleType::GreaterThanField, 'fa'), ['fa' => 'a']);
        $this->fail('expected missing_related_field');
    } catch (ExpressionEvaluationException $e) {
        expect($e->slug())->toBe('missing_related_field');
    }
});

it('rejects a grouped row missing its connective', function (): void {
    $rows = [
        ffvRow('r1', ValidationRuleType::GreaterThanField, 'fa', 'fb', 0),
        ffvRow('r2', ValidationRuleType::GreaterThanField, 'fa', 'fb', 1), // no logic_operator
    ];

    try {
        (new StructuredRuleLowering)->lowerGroup($rows, ['fa' => 'a', 'fb' => 'b']);
        $this->fail('expected malformed_logic_group');
    } catch (ExpressionEvaluationException $e) {
        expect($e->slug())->toBe('malformed_logic_group');
    }
});
