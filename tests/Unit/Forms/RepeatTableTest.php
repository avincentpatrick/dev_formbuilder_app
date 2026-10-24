<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Support\Forms\MatrixColumns;
use App\Support\Forms\RepeatTable;

/*
|--------------------------------------------------------------------------
| M185 (`R-0a7275ce`, `D129`) — can a repeatable section be drawn as a table?
|--------------------------------------------------------------------------
| The cases are read from `tests/fixtures/repeat-table.json`, which `resources/js/components/submissions/repeat-table.test.ts`
| reads too, so the server (the PDF, the paper form) and the browser (the guest form, the encode page, the preview, the
| builder's Layout setting) cannot disagree about a section.
*/

/** @return list<array{name: string, members: list<array<string, mixed>>, columns: list<string>, blocker: ?string}> */
function repeatTableCases(): array
{
    $file = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/repeat-table.json'), true, flags: JSON_THROW_ON_ERROR);

    return $file['cases'];
}

it('reads the shared cases — a floor, so an emptied fixture cannot pass', function (): void {
    expect(repeatTableCases())->toHaveCount(7);
});

it('answers every shared case: which members are columns, and which keeps the cards', function (): void {
    foreach (repeatTableCases() as $case) {
        expect(array_column(RepeatTable::columns($case['members']), 'key'))->toBe($case['columns'], $case['name'])
            ->and(RepeatTable::blocker($case['members'])['key'] ?? null)->toBe($case['blocker'], $case['name']);
    }
});

it('draws a table only for a repeatable section set to one, with a column, and nothing that keeps the cards', function (): void {
    $fits = [['key' => 'name', 'field_type' => 'short_text']];

    expect(RepeatTable::draws(['is_repeatable' => true, 'repeat_layout' => 'table'], $fits))->toBeTrue()
        ->and(RepeatTable::draws(['is_repeatable' => true], $fits))->toBeFalse()
        ->and(RepeatTable::draws(['is_repeatable' => false, 'repeat_layout' => 'table'], $fits))->toBeFalse()
        ->and(RepeatTable::draws(['is_repeatable' => true, 'repeat_layout' => 'table'], []))->toBeFalse()
        ->and(RepeatTable::draws(['is_repeatable' => true, 'repeat_layout' => 'table'], [...$fits, ['key' => 'n', 'field_type' => 'long_text']]))->toBeFalse();
});

it('holds a grid column\'s types and the three text kinds and a dropdown beside them, never Yes / No', function (): void {
    $types = array_map(static fn (FieldType $t): string => $t->value, RepeatTable::TYPES);

    expect(array_diff(array_map(static fn (FieldType $t): string => $t->value, MatrixColumns::TYPES), $types))->toBe([])
        ->and($types)->toContain('email', 'phone', 'url', 'dropdown')
        ->and($types)->not->toContain('yes_no');
});
