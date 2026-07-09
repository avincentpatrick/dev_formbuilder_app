<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ValidationRuleType;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Services\Validation\SemanticInput;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| F3 SemanticValidator — the Stage-3 orchestration (pure, no database).
|--------------------------------------------------------------------------
| Driven from unsaved models through SemanticInput. Covers relevance masking, the section→field cascade,
| the bounded fixed-point settle (upstream-hidden field flips a downstream one), skip rules, the
| effective-required matrix, constraint gating, error aggregation, localization, and the empty computed slot.
*/

/**
 * @param  list<FormField>  $fields
 * @param  list<FormSection>  $sections
 * @param  list<FormFieldValidation>  $validations
 * @param  array<string, mixed>  $answers
 */
function semInput(array $fields, array $sections, array $validations, array $answers, string $locale = 'en'): SemanticInput
{
    return new SemanticInput(new Collection($fields), new Collection($sections), new Collection($validations), $answers, $locale);
}

it('masks an irrelevant field and prunes its answer', function (): void {
    $fields = [
        makeSchemaField(['id' => 'f1', 'key' => 'trigger']),
        makeSchemaField(['id' => 'f2', 'key' => 'dependent', 'relevant_expression' => '${trigger} = \'yes\'']),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], ['trigger' => 'no', 'dependent' => 'hi']));

    expect($result->fieldRelevance)->toBe(['trigger' => true, 'dependent' => false]);
    expect($result->effectiveAnswers)->toBe(['trigger' => 'no']);
    expect($result->passed())->toBeTrue();
    expect($result->computed)->toBe([]);
});

it('cascades an irrelevant section to hide its fields', function (): void {
    $sections = [makeSchemaSection(['id' => 's1', 'key' => 'sec', 'relevant_expression' => '${show} = \'1\''])];
    $fields = [
        makeSchemaField(['id' => 'f0', 'key' => 'show']),
        makeSchemaField(['id' => 'f1', 'key' => 'inside', 'form_section_id' => 's1']),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, $sections, [], ['show' => '0', 'inside' => 'x']));

    expect($result->sectionRelevance)->toBe(['sec' => false]);
    expect($result->fieldRelevance['inside'])->toBeFalse();
    expect($result->effectiveAnswers)->toBe(['show' => '0']);
});

it('settles a chained relevance to a fixed point (upstream-hidden field reads empty downstream)', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fg', 'key' => 'gate']),
        makeSchemaField(['id' => 'fa', 'key' => 'a', 'relevant_expression' => '${gate} = \'1\'']),
        makeSchemaField(['id' => 'fb', 'key' => 'b', 'relevant_expression' => '${a} = \'\'']),
    ];

    // gate=0 ⇒ a hidden ⇒ a pruned ⇒ b sees a empty ⇒ b relevant. A single pass would wrongly keep b hidden.
    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], ['gate' => '0', 'a' => 'something', 'b' => 'hi']));

    expect($result->fieldRelevance)->toBe(['gate' => true, 'a' => false, 'b' => true]);
    expect($result->effectiveAnswers)->toBe(['gate' => '0', 'b' => 'hi']);
});

it('treats a fired skip_if rule as irrelevance', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fs', 'key' => 'src']),
        makeSchemaField(['id' => 'fx', 'key' => 'target']),
    ];
    $validations = [makeValidationRow([
        'id' => 'v', 'form_field_id' => 'fx', 'related_form_field_id' => 'fs',
        'rule_type' => ValidationRuleType::SkipIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'skip',
    ])];

    $skipped = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['src' => 'skip', 'target' => 'val']));
    expect($skipped->fieldRelevance['target'])->toBeFalse();
    expect($skipped->effectiveAnswers)->toBe(['src' => 'skip']);

    $kept = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['src' => 'keep', 'target' => 'val']));
    expect($kept->fieldRelevance['target'])->toBeTrue();
    expect($kept->effectiveAnswers)->toBe(['src' => 'keep', 'target' => 'val']);
});

it('enforces a required field but not an optional one', function (): void {
    $fields = [
        makeSchemaField(['id' => 'f1', 'key' => 'req', 'is_required' => RequiredMode::Required]),
        makeSchemaField(['id' => 'f2', 'key' => 'opt', 'is_required' => RequiredMode::Optional]),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], []));

    expect($result->passed())->toBeFalse();
    expect($result->errorsFor('req'))->toHaveCount(1);
    expect($result->errorsFor('req')[0]->rule)->toBe('field_required');
    expect($result->errorsFor('opt'))->toBe([]);
});

it('resolves conditional requiredness against current answers', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fg', 'key' => 'gate']),
        makeSchemaField(['id' => 'fc', 'key' => 'cond', 'is_required' => RequiredMode::Conditional]),
    ];
    $validations = [makeValidationRow([
        'id' => 'v', 'form_field_id' => 'fc', 'related_form_field_id' => 'fg',
        'rule_type' => ValidationRuleType::RequiredIf, 'operator' => ComparisonOperator::Eq, 'rule_value' => 'yes',
    ])];

    $required = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['gate' => 'yes']));
    expect($required->errorsFor('cond'))->toHaveCount(1);
    expect($required->errorsFor('cond')[0]->rule)->toBe('field_required');

    $notRequired = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['gate' => 'no']));
    expect($notRequired->passed())->toBeTrue();
});

it('does not enforce a required field that is irrelevant', function (): void {
    $fields = [
        makeSchemaField(['id' => 'fg', 'key' => 'g']),
        makeSchemaField(['id' => 'fr', 'key' => 'r', 'is_required' => RequiredMode::Required, 'relevant_expression' => '${g} = \'1\'']),
    ];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], [], ['g' => '0']));

    expect($result->passed())->toBeTrue();
    expect($result->fieldRelevance['r'])->toBeFalse();
});

it('checks a constraint only on a relevant, answered field', function (): void {
    $fields = [makeSchemaField(['id' => 'f', 'key' => 'age', 'field_type' => FieldType::Integer])];
    $validations = [makeValidationRow(['id' => 'v', 'form_field_id' => 'f', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '18'])];
    $validator = makeSemanticValidator();

    expect($validator->evaluate(semInput($fields, [], $validations, ['age' => 10]))->errorsFor('age')[0]->rule)->toBe('min_value');
    expect($validator->evaluate(semInput($fields, [], $validations, ['age' => 20]))->passed())->toBeTrue();
    expect($validator->evaluate(semInput($fields, [], $validations, []))->passed())->toBeTrue(); // empty ⇒ constraint skipped
});

it('aggregates errors across fields', function (): void {
    $fields = [
        makeSchemaField(['id' => 'f1', 'key' => 'a', 'field_type' => FieldType::Integer]),
        makeSchemaField(['id' => 'f2', 'key' => 'b', 'is_required' => RequiredMode::Required]),
    ];
    $validations = [makeValidationRow(['id' => 'v', 'form_field_id' => 'f1', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5'])];

    $result = makeSemanticValidator()->evaluate(semInput($fields, [], $validations, ['a' => 1]));

    expect($result->errors)->toHaveCount(2);
    expect($result->errorsFor('a')[0]->rule)->toBe('min_value');
    expect($result->errorsFor('b')[0]->rule)->toBe('field_required');
});

it('resolves the error message by locale, falling back to the base message then a default', function (): void {
    $fields = [makeSchemaField(['id' => 'f', 'key' => 'a', 'field_type' => FieldType::Integer])];
    $localized = [makeValidationRow([
        'id' => 'v', 'form_field_id' => 'f', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5',
        'error_message' => 'Too small', 'error_message_translations' => ['fr' => 'Trop petit'],
    ])];
    $bare = [makeValidationRow(['id' => 'v2', 'form_field_id' => 'f', 'rule_type' => ValidationRuleType::MinValue, 'rule_value' => '5'])];
    $validator = makeSemanticValidator();

    expect($validator->evaluate(semInput($fields, [], $localized, ['a' => 1], 'fr'))->errorsFor('a')[0]->message)->toBe('Trop petit');
    expect($validator->evaluate(semInput($fields, [], $localized, ['a' => 1], 'en'))->errorsFor('a')[0]->message)->toBe('Too small');
    expect($validator->evaluate(semInput($fields, [], $bare, ['a' => 1], 'en'))->errorsFor('a')[0]->message)->toBe('This value is not valid.');
});
