<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Support\Forms\MatrixColumns;

/*
|--------------------------------------------------------------------------
| M246 — a grid column's types are written four times; this holds the copies equal.
|--------------------------------------------------------------------------
| `MatrixColumns::TYPES` is the authority. The guest runtime (`schema-mapping.ts`'s MATRIX_COLUMN_TYPES, and the piped-text
| cell reader's GRID_COLUMN_TYPES in `grid-cells.ts`) falls back to
| a single choice for a type it does not know, and the builder (`MatrixEditor.vue`'s COLUMN_TYPES) offers only what it
| lists — so a copy left behind draws a long-text column as a dropdown, or never offers it. Each copy is read as text,
| anchored on its own declaration; an unmatched pattern fails the case rather than comparing against nothing.
*/

/** @return list<string> the quoted `'…'` values inside the declaration that starts with `$anchor` and ends at `];` */
function matrixColumnTypesIn(string $relativePath, string $anchor, string $item): array
{
    $source = (string) file_get_contents(__DIR__.'/../../../'.$relativePath);
    $found = preg_match('/'.preg_quote($anchor, '/').'(.*?)\];/s', $source, $match);
    expect($found)->toBe(1, "{$relativePath} declares no {$anchor}");
    preg_match_all($item, $match[1], $values);

    return $values[1];
}

it('lists the same column types, in the same order, in the runtime and the builder as on the server', function (): void {
    $authority = array_map(static fn (FieldType $type): string => $type->value, MatrixColumns::TYPES);

    expect(matrixColumnTypesIn('resources/public-runtime/lib/schema-mapping.ts', 'const MATRIX_COLUMN_TYPES = [', "/'([a-z_]+)'/"))->toBe($authority)
        ->and(matrixColumnTypesIn('resources/js/components/builder/MatrixEditor.vue', 'const COLUMN_TYPES = [', "/value: '([a-z_]+)'/"))->toBe($authority)
        ->and(matrixColumnTypesIn('resources/public-runtime/engine/grid-cells.ts', 'const GRID_COLUMN_TYPES = [', "/'([a-z_]+)'/"))->toBe($authority)
        ->and($authority)->toContain('long_text')
        ->and($authority)->not->toContain('yes_no');
});
