<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Models\FormFieldValidation;
use App\Services\Expressions\EvaluationContext;
use App\Services\Validation\StructuredRuleEvaluator;

/*
|--------------------------------------------------------------------------
| F3 StructuredRuleEvaluator — the three rule families.
|--------------------------------------------------------------------------
| Self-vs-literal constraints evaluate via direct Coercion (no engine); field-vs-field + conditional
| families lower to the F2 AST. An empty answer PASSES every constraint (emptiness is the required-check's
| job). All value-typing is Coercion-driven so the TypeScript mirror stays byte-identical.
*/

function selfLiteralRow(ValidationRuleType $ruleType, string $ruleValue): FormFieldValidation
{
    return makeValidationRow(['id' => 'r', 'form_field_id' => 'fx', 'rule_type' => $ruleType, 'rule_value' => $ruleValue]);
}

it('checks min_value / max_value inclusively, fail-closed on non-numeric, empty passes', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $context = new EvaluationContext([]);

    $min = selfLiteralRow(ValidationRuleType::MinValue, '5');
    expect($rules->passesConstraint($min, 'x', 5, $context, []))->toBeTrue();      // inclusive
    expect($rules->passesConstraint($min, 'x', 6, $context, []))->toBeTrue();
    expect($rules->passesConstraint($min, 'x', 4, $context, []))->toBeFalse();
    expect($rules->passesConstraint($min, 'x', '4', $context, []))->toBeFalse();   // numeric-like string
    expect($rules->passesConstraint($min, 'x', 'abc', $context, []))->toBeFalse(); // fail-closed
    expect($rules->passesConstraint($min, 'x', null, $context, []))->toBeTrue();   // empty passes
    expect($rules->passesConstraint($min, 'x', '', $context, []))->toBeTrue();

    $max = selfLiteralRow(ValidationRuleType::MaxValue, '10');
    expect($rules->passesConstraint($max, 'x', 10, $context, []))->toBeTrue();
    expect($rules->passesConstraint($max, 'x', 11, $context, []))->toBeFalse();
});

it('checks min_length / max_length by code points (astral-aware), empty passes', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $context = new EvaluationContext([]);

    $min = selfLiteralRow(ValidationRuleType::MinLength, '3');
    expect($rules->passesConstraint($min, 'x', 'abc', $context, []))->toBeTrue();
    expect($rules->passesConstraint($min, 'x', 'ab', $context, []))->toBeFalse();
    expect($rules->passesConstraint($min, 'x', '', $context, []))->toBeTrue(); // empty passes

    // Two astral code points: byte length is 8, but mb_strlen is 2 — must count code points.
    $minTwo = selfLiteralRow(ValidationRuleType::MinLength, '2');
    expect($rules->passesConstraint($minTwo, 'x', '😀😀', $context, []))->toBeTrue();
    expect($rules->passesConstraint($minTwo, 'x', '😀', $context, []))->toBeFalse();

    $max = selfLiteralRow(ValidationRuleType::MaxLength, '5');
    expect($rules->passesConstraint($max, 'x', 'abcde', $context, []))->toBeTrue();
    expect($rules->passesConstraint($max, 'x', 'abcdef', $context, []))->toBeFalse();
});

it('anchors the pattern rule to a full match, escapes delimiters, and treats a bad regex as no match', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $context = new EvaluationContext([]);

    $digits = selfLiteralRow(ValidationRuleType::Pattern, '[0-9]{3}');
    expect($rules->passesConstraint($digits, 'x', '123', $context, []))->toBeTrue();
    expect($rules->passesConstraint($digits, 'x', '12', $context, []))->toBeFalse();   // anchored: not a partial match
    expect($rules->passesConstraint($digits, 'x', '1234', $context, []))->toBeFalse();
    expect($rules->passesConstraint($digits, 'x', 'abc', $context, []))->toBeFalse();
    expect($rules->passesConstraint($digits, 'x', '', $context, []))->toBeTrue();       // empty passes

    $withSlash = selfLiteralRow(ValidationRuleType::Pattern, 'a/b');
    expect($rules->passesConstraint($withSlash, 'x', 'a/b', $context, []))->toBeTrue();
    expect($rules->passesConstraint($withSlash, 'x', 'axb', $context, []))->toBeFalse();

    $broken = selfLiteralRow(ValidationRuleType::Pattern, '(');
    expect($rules->passesConstraint($broken, 'x', 'x', $context, []))->toBeFalse();
});

it('exposes pattern compilability for the publish gate', function (): void {
    expect(StructuredRuleEvaluator::isCompilablePattern('[0-9]{3}'))->toBeTrue();
    expect(StructuredRuleEvaluator::isCompilablePattern('a/b'))->toBeTrue();
    expect(StructuredRuleEvaluator::isCompilablePattern('('))->toBeFalse();
    expect(StructuredRuleEvaluator::isCompilablePattern(''))->toBeFalse();
});

it('evaluates greater_than_field / less_than_field against the related field', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $map = ['fa' => 'a', 'fb' => 'b'];
    $row = makeValidationRow(['id' => 'r', 'form_field_id' => 'fa', 'related_form_field_id' => 'fb', 'rule_type' => ValidationRuleType::GreaterThanField]);

    expect($rules->passesConstraint($row, 'a', 5, new EvaluationContext(['a' => 5, 'b' => 3]), $map))->toBeTrue();
    expect($rules->passesConstraint($row, 'a', 2, new EvaluationContext(['a' => 2, 'b' => 3]), $map))->toBeFalse();
});

it('evaluates a free-text constraint expression with the field answer as self', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $row = makeValidationRow(['id' => 'r', 'form_field_id' => 'fx', 'expression' => '. > 5']);

    expect($rules->passesConstraint($row, 'x', 6, new EvaluationContext([], 6), []))->toBeTrue();
    expect($rules->passesConstraint($row, 'x', 3, new EvaluationContext([], 3), []))->toBeFalse();
});

it('treats a blank free-text constraint expression as a no-op pass', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $row = makeValidationRow(['id' => 'r', 'form_field_id' => 'fx', 'expression' => '   ']);

    expect($rules->passesConstraint($row, 'x', 'anything', new EvaluationContext([], 'anything'), []))->toBeTrue();
});

it('folds a constraint logic group over the boolean results (self-vs-literal rows cannot lower)', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $context = new EvaluationContext([]);
    // "value >= 5 OR value <= 1"
    $unit = [
        makeValidationRow(['id' => 'r1', 'form_field_id' => 'fx', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5', 'sequence' => 0, 'logic_group' => 'g']),
        makeValidationRow(['id' => 'r2', 'form_field_id' => 'fx', 'rule_type' => ValidationRuleType::MaxValue, 'rule_value' => '1', 'sequence' => 1, 'logic_operator' => LogicOperator::Or, 'logic_group' => 'g']),
    ];

    expect($rules->passesConstraintGroup($unit, 'x', 0, $context, []))->toBeTrue();  // 0 <= 1
    expect($rules->passesConstraintGroup($unit, 'x', 6, $context, []))->toBeTrue();  // 6 >= 5
    expect($rules->passesConstraintGroup($unit, 'x', 3, $context, []))->toBeFalse(); // neither
});

it('reads a conditional row through conditionHolds', function (): void {
    $rules = makeStructuredRuleEvaluator();
    $map = ['fown' => 'own', 'fstatus' => 'status'];
    $row = makeValidationRow([
        'id' => 'r', 'form_field_id' => 'fown', 'related_form_field_id' => 'fstatus',
        'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'employed',
    ]);

    expect($rules->conditionHolds($row, new EvaluationContext(['status' => 'employed']), $map))->toBeTrue();
    expect($rules->conditionHolds($row, new EvaluationContext(['status' => 'student']), $map))->toBeFalse();
});
