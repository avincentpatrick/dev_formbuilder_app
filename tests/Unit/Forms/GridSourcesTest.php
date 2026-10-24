<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Forms\ChoiceListMaterializer;
use App\Services\Forms\ChoiceSource;
use App\Services\Forms\GridSources;
use App\Support\Forms\MatrixColumns;

/*
|--------------------------------------------------------------------------
| M185 (`R-bb79aec0`, `D161`) — where a grid's lists come from.
|--------------------------------------------------------------------------
| A grid's rows, its shared cells and each choice column's choices are slots named as a flat question's are, so
| `ChoiceSource::of()` reads each through `GridSources::view()`; a sourced slot's copy leaves the next draft.
*/

it('offers a Grid its rows, its cells and each choice column, and a Likert matrix its rows only', function (): void {
    $config = ['columns' => [
        ['value' => 'svc', 'label' => 'Services', 'type' => 'multi_select'],
        ['value' => 'staff', 'label' => 'Staff', 'type' => 'integer'],
        ['value' => 'grade', 'label' => 'Grade'],
    ]];

    $grid = GridSources::slots(FieldType::Matrix, $config);
    expect(array_map(static fn (array $s): string => $s['part'].($s['at'] === null ? '' : ':'.$s['at']), $grid))
        ->toBe(['rows', 'cells', 'column:0', 'column:2'])
        ->and($grid[0]['max'])->toBe(GridSources::ROWS_MAX)
        ->and($grid[2]['max'])->toBe(ChoiceListMaterializer::FLAT_LIST_MAX)
        ->and(array_column(GridSources::slots(FieldType::LikertMatrix, $config), 'part'))->toBe(['rows'])
        ->and(GridSources::slots(FieldType::SingleSelect, $config))->toBe([]);
});

it('reads each slot as a choice question reads its source, keys present only where the grid holds them', function (): void {
    $config = [
        'rows' => [],
        'rows_list' => 'wards',
        'cells' => [['value' => 'y', 'label' => 'Yes']],
        'columns' => [['value' => 'svc', 'label' => 'Services', 'options_source' => ['form_id' => null, 'field_key' => null]]],
    ];
    [$rows, $cells, $column] = GridSources::slots(FieldType::Matrix, $config);

    expect(GridSources::view($config, $rows))->toBe(['options' => [], 'options_list' => 'wards'])
        ->and(GridSources::sourceOf($config, $rows))->toBe(ChoiceSource::List)
        ->and(GridSources::sourceOf($config, $cells))->toBe(ChoiceSource::Typed)
        ->and(GridSources::view($config, $column))->toBe(['options_source' => ['form_id' => null, 'field_key' => null]])
        ->and(GridSources::sourceOf($config, $column))->toBe(ChoiceSource::Form)
        ->and(GridSources::declaresAny(FieldType::Matrix, $config))->toBeTrue()
        ->and(GridSources::declaresAny(FieldType::Matrix, ['rows' => [['value' => 'a', 'label' => 'A']], 'columns' => []]))->toBeFalse();
});

it('writes a slot\'s items in place, a column\'s inside that column', function (): void {
    $config = ['rows' => [], 'rows_list' => 'wards', 'columns' => [['value' => 'svc', 'label' => 'Services', 'options_list' => 'svc']]];
    [$rows, , $column] = GridSources::slots(FieldType::Matrix, $config);
    $items = [['value' => 'w1', 'label' => 'Ward 1']];

    $written = GridSources::withItems(GridSources::withItems($config, $rows, $items), $column, $items);

    expect($written['rows'])->toBe($items)
        ->and($written['columns'][0]['options'])->toBe($items)
        ->and($written['columns'][0]['options_list'])->toBe('svc');
});

it('takes a sourced slot\'s copy out of the next draft and leaves its source and every typed slot alone', function (): void {
    $copied = [['value' => 'w1', 'label' => 'Ward 1']];
    $typed = [['value' => 'a', 'label' => 'A']];
    $config = [
        'rows' => $copied,
        'rows_source' => ['form_id' => 'f', 'field_key' => 'ward'],
        'cells' => $typed,
        'columns' => [
            ['value' => 'svc', 'label' => 'Services', 'options' => $copied, 'options_list' => 'svc'],
            ['value' => 'grade', 'label' => 'Grade', 'options' => $typed],
        ],
    ];

    $next = ChoiceListMaterializer::forNextDraft($config);

    expect($next['rows'])->toBe([])
        ->and($next['rows_source'])->toBe(['form_id' => 'f', 'field_key' => 'ward'])
        ->and($next['cells'])->toBe($typed)
        ->and($next['columns'][0])->toBe(['value' => 'svc', 'label' => 'Services', 'options_list' => 'svc'])
        ->and($next['columns'][1]['options'])->toBe($typed);
});

it('leaves a typed grid exactly as it is in the next draft', function (): void {
    $config = [
        'rows' => [['value' => 'a', 'label' => 'A']],
        'columns' => [['value' => 'c', 'label' => 'C', 'options' => [['value' => 'x', 'label' => 'X']]]],
        'cells' => [],
    ];

    expect(ChoiceListMaterializer::forNextDraft($config))->toBe($config);
});

it('counts a column with a source as having choices of its own, before and after they are copied', function (): void {
    $sourced = ['columns' => [['value' => 'svc', 'label' => 'Services', 'options_list' => 'svc']], 'cells' => [['value' => 'y', 'label' => 'Yes']]];

    expect(MatrixColumns::needsSharedCells($sourced))->toBeFalse()
        ->and(MatrixColumns::choices($sourced['columns'][0], $sourced))->toBe([])
        ->and(MatrixColumns::needsSharedCells(['columns' => [['value' => 'g', 'label' => 'G']]]))->toBeTrue()
        ->and(MatrixColumns::asScale(['columns' => [['value' => '1', 'label' => 'Poor', 'options_source' => ['form_id' => 'f', 'field_key' => 'k']]]]))
        ->toBe([['value' => '1', 'label' => 'Poor']]);
});
