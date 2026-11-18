<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormField;
use App\Services\Forms\KeyReferenceRewriter;
use App\Support\Forms\GridRules;

/*
|--------------------------------------------------------------------------
| M246 (`grid-question-table`) — where a grid keeps rules of its own.
|--------------------------------------------------------------------------
| Every walk over a question's expressions reads a grid's rules through GridRules, so this pins what it lists and
| what it rewrites. The builder's mirror is `resources/js/components/builder/grid-rules.ts`.
*/

/** @return array<string, mixed> */
function gridRulesConfig(): array
{
    return [
        'rows' => [
            ['value' => 'r1', 'label' => 'First'],
            ['value' => 'r2', 'label' => 'Second', 'relevant' => '${kept} = \'yes\'', 'not_needed' => ['why']],
            ['value' => 'r3', 'label' => 'Third', 'relevant' => '   '],
        ],
        'columns' => [
            ['value' => 'agree', 'label' => 'Agree', 'type' => 'single_select'],
            ['value' => 'why', 'label' => 'Why', 'type' => 'long_text', 'relevant' => '${region} != \'\'', 'needed_when' => '${.agree} = \'no\'', 'required_when' => '${.agree} = \'no\' and ${strict} = 1'],
        ],
        'cells' => [],
    ];
}

it('lists every non-blank row and column rule, rows first, with where ${.col} may be used', function (): void {
    expect(GridRules::sites(FieldType::Matrix, gridRulesConfig()))->toBe([
        ['expression' => '${kept} = \'yes\'', 'rule' => 'row', 'row' => 'r2', 'column' => null, 'row_scope' => false],
        ['expression' => '${region} != \'\'', 'rule' => 'column', 'row' => null, 'column' => 'why', 'row_scope' => false],
        ['expression' => '${.agree} = \'no\'', 'rule' => 'needed', 'row' => null, 'column' => 'why', 'row_scope' => true],
        ['expression' => '${.agree} = \'no\' and ${strict} = 1', 'rule' => 'required', 'row' => null, 'column' => 'why', 'row_scope' => true],
    ]);
});

it('reads only row rules on a Likert grid, and nothing on any other type or a malformed config', function (): void {
    expect(array_column(GridRules::sites(FieldType::LikertMatrix, gridRulesConfig()), 'rule'))->toBe(['row'])
        ->and(GridRules::sites(FieldType::ShortText, gridRulesConfig()))->toBe([])
        ->and(GridRules::sites(FieldType::Matrix, null))->toBe([])
        ->and(GridRules::sites(FieldType::Matrix, ['rows' => 'oops', 'columns' => [7, 'x']]))->toBe([]);
});

it('rewrites every rule and nothing else, and says whether a grid has rules at all', function (): void {
    $config = GridRules::mapExpressions(FieldType::Matrix, gridRulesConfig(), static fn (string $e): string => '('.$e.')');

    expect($config['rows'][0])->toBe(['value' => 'r1', 'label' => 'First'])
        ->and($config['rows'][1]['relevant'])->toBe('(${kept} = \'yes\')')
        ->and($config['rows'][2]['relevant'])->toBe('   ')
        ->and($config['columns'][1]['required_when'])->toBe('(${.agree} = \'no\' and ${strict} = 1)')
        ->and($config['columns'][0])->toBe(gridRulesConfig()['columns'][0])
        ->and(GridRules::hasRules(FieldType::Matrix, gridRulesConfig()))->toBeTrue()
        ->and(GridRules::hasRules(FieldType::Matrix, ['rows' => [['value' => 'a', 'not_needed' => ['x']]], 'columns' => []]))->toBeTrue()
        ->and(GridRules::hasRules(FieldType::Matrix, ['rows' => [['value' => 'a', 'not_needed' => []]], 'columns' => []]))->toBeFalse();
});

it('re-points a grid rule when its question is copied or a key is renamed', function (): void {
    $field = new FormField(['key' => 'svc', 'label' => 'Service', 'field_type' => FieldType::Matrix, 'config' => gridRulesConfig()]);

    (new KeyReferenceRewriter)->field($field, ['kept' => 'kept_2', 'strict' => 'strict_2']);

    /** @var array{rows: list<array<string, mixed>>, columns: list<array<string, mixed>>} $config */
    $config = $field->config;
    expect($config['rows'][1]['relevant'])->toBe('${kept_2} = \'yes\'')
        ->and($config['columns'][1]['relevant'])->toBe('${region} != \'\'')
        ->and($config['columns'][1]['needed_when'])->toBe('${.agree} = \'no\'')
        ->and($config['columns'][1]['required_when'])->toBe('${.agree} = \'no\' and ${strict_2} = 1');
});
