<?php

declare(strict_types=1);

use App\Enums\FieldAppearance;
use App\Enums\FieldType;

/*
|--------------------------------------------------------------------------
| M130 (`R-6c76bed2`) — the layouts an author may choose, per field type.
|--------------------------------------------------------------------------
| `FieldAppearance::for()` is a total `match` with no `default`, so PHPStan already refuses an
| unclassified type; these cases pin WHICH answer each type gets, which the totality cannot.
*/

it('offers side by side and in columns on the two list types, in that order', function (FieldType $type): void {
    expect(FieldAppearance::for($type))->toBe([FieldAppearance::ColumnsPack, FieldAppearance::Columns]);
})->with([FieldType::SingleSelect, FieldType::MultiSelect]);

it('offers no layout on any other type', function (): void {
    $offered = [];

    foreach (FieldType::cases() as $type) {
        if (! in_array($type, [FieldType::SingleSelect, FieldType::MultiSelect], true) && FieldAppearance::for($type) !== []) {
            $offered[] = $type->value;
        }
    }

    // Collected as ONE list, so a failure names every offending type rather than the first.
    expect($offered)->toBe([]);
});

it('never offers minimal, which would re-import a single choice as a Dropdown', function (): void {
    $values = array_map(static fn (FieldAppearance $layout): string => $layout->value, FieldAppearance::cases());

    // `in_array`, not `not->toContain()`: Pest's toContain() is variadic, so a message argument would be
    // read as a second needle and the negation would pass over anything (M121).
    expect(in_array('minimal', $values, true))->toBeFalse('`minimal` is offered as a layout');
});

it('uses the ODK names, so each value round-trips through XLSForm verbatim', function (): void {
    expect(FieldAppearance::ColumnsPack->value)->toBe('columns-pack')
        ->and(FieldAppearance::Columns->value)->toBe('columns');
});

it('labels each layout the way the builder offers it', function (): void {
    expect(FieldAppearance::ColumnsPack->label())->toBe('Side by side')
        ->and(FieldAppearance::Columns->label())->toBe('In columns');
});
