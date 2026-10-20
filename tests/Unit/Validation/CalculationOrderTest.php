<?php

declare(strict_types=1);

use App\Services\Validation\CalculationOrder;

/*
|--------------------------------------------------------------------------
| M180 (`R-39dbce5b`, `D149`) — the order calculations are worked out in, shared with the TypeScript twin.
|--------------------------------------------------------------------------
| tests/fixtures/calculation-order.json is read by this file AND by
| resources/public-runtime/engine/__tests__/calculation-order.test.ts, so the two engines agree on which
| calculations are worked out and in what order — a chain in either direction, a diamond, and the ones that
| never are (a self-reference, a cycle, anything downstream of one). References come from the real parser.
*/

it('orders the calculations exactly as the shared fixture says', function (array $case): void {
    $parser = makeExpressionParser();
    $references = static fn (string $formula): array => $parser->referencedKeys($parser->parse($formula));

    expect(CalculationOrder::order($case['calculations'], $references))->toBe($case['order']);
})->with(function (): iterable {
    /** @var list<array{name: string, calculations: list<array{key: string, formula: ?string}>, order: list<string>}> $cases */
    $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/calculation-order.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($cases as $case) {
        yield $case['name'] => [$case];
    }
});
