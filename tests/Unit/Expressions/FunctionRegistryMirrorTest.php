<?php

declare(strict_types=1);

use App\Services\Expressions\FunctionRegistry;

/*
|--------------------------------------------------------------------------
| M180 (`R-da02fe03`) — the two function registries are one list.
|--------------------------------------------------------------------------
| Until M180 nothing pinned the function list across the two engines: the golden vectors held them together only
| where a vector happened to call a function. This reads the TypeScript registry's source and asserts the two lists
| match name for name and argument count for argument count, and that R4's functions are on both.
*/

/** @return array<string, array{0: int, 1: ?int}> name => [min, max] from the TypeScript registry's source */
function typescriptFunctionRegistry(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/resources/public-runtime/engine/function-registry.ts');

    expect(preg_match('/const PUBLIC: Record<string, \[number, number \| null\]> = \{(.*?)\};/s', $source, $block))->toBe(1);
    preg_match_all('/([a-z_]+): \[(\d+), (\d+|null)\]/', $block[1], $entries, PREG_SET_ORDER);

    $out = [];
    foreach ($entries as [, $name, $min, $max]) {
        $out[$name] = [(int) $min, $max === 'null' ? null : (int) $max];
    }

    return $out;
}

it('keeps the TypeScript function registry identical to the PHP one, argument counts included', function (): void {
    $php = (new FunctionRegistry)->publicFunctions();
    $typescript = typescriptFunctionRegistry();

    ksort($php);
    ksort($typescript);

    expect($typescript)->toBe($php);
});

it('offers R4\'s date and number functions on both engines', function (): void {
    $names = array_keys((new FunctionRegistry)->publicFunctions());

    expect($names)->toContain('date_diff', 'date_add', 'age', 'round', 'sum', 'coalesce', 'concat');
    expect(array_keys(typescriptFunctionRegistry()))->toContain('date_diff', 'date_add', 'age', 'round', 'sum', 'coalesce', 'concat');
});
