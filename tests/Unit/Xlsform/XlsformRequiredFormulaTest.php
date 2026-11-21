<?php

declare(strict_types=1);

use App\Enums\ComparisonOperator;
use App\Enums\FieldType;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Services\Xlsform\Dto\FieldSpec;
use App\Services\Xlsform\Dto\ValidationSpec;
use App\Services\Xlsform\XlsformRequiredFormula;

/*
|--------------------------------------------------------------------------
| M244 (`R-ea0cc482`, `R-0ceefbb4`): an ODK `required` formula as "Required when…" rules, and back. Either-of
| branches are separate rules, all-of branches one group; every check is held to what the compared question takes.
|--------------------------------------------------------------------------
*/

/** @return array<string, FieldSpec> */
function requiredFormulaFields(): array
{
    return [
        'comment' => new FieldSpec(key: 'comment', fieldType: FieldType::LongText),
        'cut' => new FieldSpec(key: 'cut', fieldType: FieldType::Dropdown),
        'worry' => new FieldSpec(key: 'worry', fieldType: FieldType::MultiSelect),
        'keep' => new FieldSpec(key: 'keep', fieldType: FieldType::SingleSelect),
        'age' => new FieldSpec(key: 'age', fieldType: FieldType::Integer),
        'name' => new FieldSpec(key: 'name', fieldType: FieldType::ShortText),
        'agree' => new FieldSpec(key: 'agree', fieldType: FieldType::YesNo),
        'intro' => new FieldSpec(key: 'intro', fieldType: FieldType::Note),
    ];
}

/**
 * @param  list<ValidationSpec>  $rows
 * @return list<array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?int, 5: ?string}>
 */
function requiredFormulaShape(array $rows): array
{
    return array_map(static fn (ValidationSpec $row): array => [
        $row->ruleType?->value,
        $row->operator?->value,
        $row->ruleValue,
        $row->relatedField?->key,
        $row->group,
        $row->logicOperator?->value,
    ], $rows);
}

it('reads NHETE\'s or-of-equalities as three either-of rules', function (): void {
    $fields = requiredFormulaFields();

    $rows = (new XlsformRequiredFormula)->read("\${cut} = 'change' or selected(\${worry}, 'other') or \${keep} = 'close'", $fields['comment'], $fields);

    expect(requiredFormulaShape($rows))->toBe([
        ['required_if', 'eq', 'change', 'cut', null, null],
        ['required_if', 'contains', 'other', 'worry', null, null],
        ['required_if', 'eq', 'close', 'keep', null, null],
    ])->and(array_map(static fn (ValidationSpec $row): int => $row->sequence, $rows))->toBe([0, 1, 2]);
});

it('reads an all-of branch as one group, and distributes and over or', function (): void {
    $fields = requiredFormulaFields();

    $rows = (new XlsformRequiredFormula)->read("(\${age} >= 18 or \${agree} = 'yes') and \${name} != ''", $fields['comment'], $fields);

    expect(requiredFormulaShape($rows))->toBe([
        ['required_if', 'gte', '18', 'age', 0, 'and'],
        ['required_with', null, null, 'name', 0, 'and'],
        ['required_if', 'eq', 'yes', 'agree', 1, 'and'],
        ['required_with', null, null, 'name', 1, 'and'],
    ]);
});

it('reads each check by the shape of the question it names', function (string $formula, array $expected): void {
    $fields = requiredFormulaFields();

    expect(requiredFormulaShape((new XlsformRequiredFormula)->read($formula, $fields['comment'], $fields)))->toBe([$expected]);
})->with([
    'selected on a single choice is equals' => ["selected(\${keep}, 'yes')", ['required_if', 'eq', 'yes', 'keep', null, null]],
    'not selected on a single choice' => ["not(selected(\${keep}, 'no'))", ['required_if', 'neq', 'no', 'keep', null, null]],
    'a value on the left' => ['18 < ${age}', ['required_if', 'gt', '18', 'age', null, null]],
    'a negative number' => ['${age} > -1', ['required_if', 'gt', '-1', 'age', null, null]],
    'blank' => ["\${name} = ''", ['required_if', 'is_null', null, 'name', null, null]],
    'answered' => ["\${worry} != ''", ['required_with', null, null, 'worry', null, null]],
    'contains on text' => ["contains(\${name}, 'x')", ['required_if', 'contains', 'x', 'name', null, null]],
    'not equal' => ["not(\${cut} = 'keep')", ['required_if', 'neq', 'keep', 'cut', null, null]],
]);

it('refuses what it cannot read as Required when…', function (string $formula): void {
    $fields = requiredFormulaFields();

    expect((new XlsformRequiredFormula)->read($formula, $fields['comment'], $fields))->toBeNull();
})->with([
    'equals on a multi-select' => ["\${worry} = 'other'"],
    'ordered on text' => ["\${name} > 'm'"],
    'not selected on a multi-select' => ["not(selected(\${worry}, 'other'))"],
    'an unknown question' => ["\${missing} = 'x'"],
    'itself' => ["\${comment} = 'x'"],
    'a function' => ['count(${worry}) > 1'],
    'two questions compared' => ['${age} > ${name}'],
    'a note' => ["\${intro} = 'x'"],
    'odk spelling' => ['count-selected(${worry}) > 1'],
    'garbage' => ["\${cut} = 'a' or"],
]);

it('refuses a formula on a question that takes no answer', function (): void {
    $fields = requiredFormulaFields();

    expect((new XlsformRequiredFormula)->read("\${cut} = 'a'", $fields['intro'], $fields))->toBeNull();
});

it('refuses more than ten either-of branches', function (): void {
    $fields = requiredFormulaFields();
    $formula = implode(' or ', array_map(static fn (int $i): string => "\${age} = {$i}", range(1, 11)));

    expect((new XlsformRequiredFormula)->read($formula, $fields['comment'], $fields))->toBeNull();
});

it('writes the rules back as a formula the reader reads to the same rules', function (): void {
    $fields = requiredFormulaFields();
    $types = array_map(static fn (FieldSpec $field): FieldType => $field->fieldType, $fields);
    $formula = "(\${age} >= 18 or \${agree} = 'yes') and \${name} != '' or selected(\${worry}, 'other') or contains(\${name}, \"it's\") or \${keep} = ''";

    $formulas = new XlsformRequiredFormula;
    $rows = $formulas->read($formula, $fields['comment'], $fields);

    $snapshot = array_map(static fn (ValidationSpec $row): array => [
        'rule_type' => $row->ruleType?->value,
        'operator' => $row->operator?->value,
        'rule_value' => $row->ruleValue,
        'related_field_key' => $row->relatedField?->key,
        'logic_group_ordinal' => $row->group,
        'logic_operator' => $row->logicOperator?->value,
        'error_message' => null,
    ], $rows);

    $written = $formulas->render($snapshot, $types);

    expect($written)->toBe("(\${age} >= 18 and \${name} != '') or (\${agree} = 'yes' and \${name} != '') or selected(\${worry}, 'other') or contains(\${name}, \"it's\") or \${keep} = ''")
        ->and(requiredFormulaShape($formulas->read($written, $fields['comment'], $fields)))->toBe(requiredFormulaShape($rows));
});

it('writes a builder group whose rows are joined by or, folding left as the engines do', function (): void {
    $types = array_map(static fn (FieldSpec $field): FieldType => $field->fieldType, requiredFormulaFields());
    $row = static fn (string $key, string $value, ?string $join): array => [
        'rule_type' => ValidationRuleType::RequiredIf->value,
        'operator' => ComparisonOperator::Eq->value,
        'rule_value' => $value,
        'related_field_key' => $key,
        'logic_group_ordinal' => 0,
        'logic_operator' => $join,
    ];

    $written = (new XlsformRequiredFormula)->render([
        $row('cut', 'change', null),
        $row('keep', 'close', LogicOperator::Or->value),
        $row('agree', 'yes', LogicOperator::And->value),
    ], $types);

    expect($written)->toBe("(\${cut} = 'change' or \${keep} = 'close') and \${agree} = 'yes'");
});

it('writes nothing it cannot read back', function (): void {
    $types = array_map(static fn (FieldSpec $field): FieldType => $field->fieldType, requiredFormulaFields());

    expect((new XlsformRequiredFormula)->render([[
        'rule_type' => 'required_if', 'operator' => 'eq', 'rule_value' => "it's \"x\"", 'related_field_key' => 'name', 'logic_group_ordinal' => null,
    ]], $types))->toBeNull()
        ->and((new XlsformRequiredFormula)->render([[
            'rule_type' => 'required_if', 'operator' => 'eq', 'rule_value' => 'x', 'related_field_key' => 'gone', 'logic_group_ordinal' => null,
        ]], $types))->toBeNull()
        ->and((new XlsformRequiredFormula)->render([['rule_type' => 'pattern', 'rule_value' => 'x']], $types))->toBeNull();
});
