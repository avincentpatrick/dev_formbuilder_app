<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\StructuredRuleLowering;

/*
|--------------------------------------------------------------------------
| F3 conditional-family lowering (required_if / required_with / skip_if / skip_with).
|--------------------------------------------------------------------------
| Each structured condition must lower to the SAME AST result the equivalent parsed expression yields —
| that identity (built on AstBuilders::literalFor) is what keeps a no-code condition and its `${…}` string
| form behaviorally interchangeable across the PHP + TypeScript engines.
*/

it('lowers required_if / skip_if eq to the parsed comparison (numeric + string literals)', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fown' => 'own', 'fage' => 'age', 'fstatus' => 'status'];

    $numeric = makeValidationRow([
        'id' => 'r1', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown',
        'related_form_field_id' => 'fage', 'operator' => ComparisonOperator::Eq, 'rule_value' => '18',
    ]);
    foreach ([['age' => 18], ['age' => 20], ['age' => '18']] as $answers) {
        $context = new EvaluationContext($answers);
        expect($engine->evaluateNode($lowering->lowerCondition($numeric, $map), $context))
            ->toBe($engine->evaluate('${age} = 18', $context));
    }

    $string = makeValidationRow([
        'id' => 'r2', 'rule_type' => ValidationRuleType::SkipIf, 'form_field_id' => 'fown',
        'related_form_field_id' => 'fstatus', 'operator' => ComparisonOperator::Eq, 'rule_value' => 'employed',
    ]);
    foreach ([['status' => 'employed'], ['status' => 'student']] as $answers) {
        $context = new EvaluationContext($answers);
        expect($engine->evaluateNode($lowering->lowerCondition($string, $map), $context))
            ->toBe($engine->evaluate('${status} = \'employed\'', $context));
    }
});

it('lowers neq / gt / lt conditions to the parsed comparison', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fown' => 'own', 'fage' => 'age'];
    $context = new EvaluationContext(['age' => 18]);

    $cases = [
        [ComparisonOperator::Neq, '18', '${age} != 18'],
        [ComparisonOperator::Gt, '10', '${age} > 10'],
        [ComparisonOperator::Lt, '10', '${age} < 10'],
    ];

    foreach ($cases as [$operator, $value, $expression]) {
        $row = makeValidationRow([
            'id' => 'r', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown',
            'related_form_field_id' => 'fage', 'operator' => $operator, 'rule_value' => $value,
        ]);
        expect($engine->evaluateNode($lowering->lowerCondition($row, $map), $context))
            ->toBe($engine->evaluate($expression, $context));
    }
});

it('lowers is_null to the empty-string emptiness test', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fown' => 'own', 'fx' => 'x'];
    $row = makeValidationRow([
        'id' => 'r', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown',
        'related_form_field_id' => 'fx', 'operator' => ComparisonOperator::IsNull,
    ]);

    foreach ([['x' => ''], ['x' => 'a'], []] as $answers) {
        $context = new EvaluationContext($answers);
        expect($engine->evaluateNode($lowering->lowerCondition($row, $map), $context))
            ->toBe($engine->evaluate('${x} = \'\'', $context));
    }
});

it('lowers contains to array membership', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fown' => 'own', 'fx' => 'x'];
    $row = makeValidationRow([
        'id' => 'r', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown',
        'related_form_field_id' => 'fx', 'operator' => ComparisonOperator::Contains, 'rule_value' => 'b',
    ]);

    expect($engine->evaluateNode($lowering->lowerCondition($row, $map), new EvaluationContext(['x' => ['a', 'b']])))->toBeTrue();
    expect($engine->evaluateNode($lowering->lowerCondition($row, $map), new EvaluationContext(['x' => ['a']])))->toBeFalse();
    expect($engine->evaluateNode($lowering->lowerCondition($row, $map), new EvaluationContext(['x' => 'abc'])))->toBeTrue();
});

it('defaults a required_with / skip_with with no operator to "related is answered"', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fown' => 'own', 'fx' => 'x'];
    $row = makeValidationRow([
        'id' => 'r', 'rule_type' => ValidationRuleType::RequiredWith, 'form_field_id' => 'fown',
        'related_form_field_id' => 'fx',
    ]);

    expect($engine->evaluateNode($lowering->lowerCondition($row, $map), new EvaluationContext(['x' => 'yes'])))->toBeTrue();
    expect($engine->evaluateNode($lowering->lowerCondition($row, $map), new EvaluationContext(['x' => ''])))->toBeFalse();
    expect($engine->evaluateNode($lowering->lowerCondition($row, $map), new EvaluationContext([])))->toBeFalse();
});

it('folds a conditional logic group flat and left-associative', function (): void {
    $lowering = new StructuredRuleLowering;
    $engine = makeExpressionEvaluator();
    $map = ['fown' => 'own', 'fa' => 'a', 'fb' => 'b'];
    $rows = [
        makeValidationRow(['id' => 'r2', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown', 'related_form_field_id' => 'fb', 'operator' => ComparisonOperator::Eq, 'rule_value' => '2', 'sequence' => 1, 'logic_operator' => LogicOperator::Or, 'logic_group' => 'g1']),
        makeValidationRow(['id' => 'r1', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown', 'related_form_field_id' => 'fa', 'operator' => ComparisonOperator::Eq, 'rule_value' => '1', 'sequence' => 0, 'logic_group' => 'g1']),
    ];
    $context = new EvaluationContext(['a' => 1, 'b' => 9]);

    expect($engine->evaluateNode($lowering->lowerConditionGroup($rows, $map), $context))
        ->toBe($engine->evaluate('${a} = 1 or ${b} = 2', $context));
});

it('rejects a conditional row with no related field', function (): void {
    $row = makeValidationRow(['id' => 'r', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown', 'operator' => ComparisonOperator::Eq, 'rule_value' => '1']);

    try {
        (new StructuredRuleLowering)->lowerCondition($row, ['fown' => 'own']);
        $this->fail('expected missing_related_field');
    } catch (ExpressionEvaluationException $e) {
        expect($e->slug())->toBe('missing_related_field');
    }
});

it('rejects a required_if row with no operator', function (): void {
    $row = makeValidationRow(['id' => 'r', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown', 'related_form_field_id' => 'fx', 'rule_value' => '1']);

    try {
        (new StructuredRuleLowering)->lowerCondition($row, ['fown' => 'own', 'fx' => 'x']);
        $this->fail('expected unevaluable');
    } catch (ExpressionEvaluationException $e) {
        expect($e->slug())->toBe('unevaluable');
    }
});

it('rejects a grouped conditional row missing its connective', function (): void {
    $rows = [
        makeValidationRow(['id' => 'r1', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown', 'related_form_field_id' => 'fx', 'operator' => ComparisonOperator::IsNull, 'sequence' => 0, 'logic_group' => 'g1']),
        makeValidationRow(['id' => 'r2', 'rule_type' => ValidationRuleType::RequiredIf, 'form_field_id' => 'fown', 'related_form_field_id' => 'fx', 'operator' => ComparisonOperator::IsNull, 'sequence' => 1, 'logic_group' => 'g1']),
    ];

    try {
        (new StructuredRuleLowering)->lowerConditionGroup($rows, ['fown' => 'own', 'fx' => 'x']);
        $this->fail('expected malformed_logic_group');
    } catch (ExpressionEvaluationException $e) {
        expect($e->slug())->toBe('malformed_logic_group');
    }
});
