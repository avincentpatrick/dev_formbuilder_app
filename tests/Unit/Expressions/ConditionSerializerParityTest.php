<?php

declare(strict_types=1);

use App\Exceptions\Expressions\ExpressionSyntaxException;

/**
 * The PHP half of H21d2's serializer guard (Doc #27 §8, amendment E1).
 *
 * `resources/js/components/builder/condition-model.ts` is the THIRD place in this repository where
 * expression syntax is constructed, and the only one that constructs it for an author. The two parsers it
 * writes for are held byte-identical by the golden corpus — but the corpus pins the PARSERS, not a printer
 * that did not exist when it was written. So every canonical string the printer can emit is parsed here, by
 * PHP, over the same `tests/fixtures/condition-serializer.json` the TypeScript round-trip drives.
 *
 * What this catches that the TS suite cannot: text the TypeScript parser accepts and PHP does not. That
 * failure mode is silent in the worst way — nothing validates a `relevant_expression` on WRITE
 * (`UpdateFieldRequest` is shape-only, and `FormBuilderService` says so in its docblock), so the draft saves
 * cleanly and the author meets the problem at publish, in a refusal naming an expression they never typed.
 *
 * The fixture is deliberately OUTSIDE `tests/golden/` — no `grammar_version` key, no manifest — so it moves
 * neither the 296-site count nor the 114-vector total. It is the `tests/fixtures/step-projection.json`
 * precedent (H21a amendment A6), and this file is its third consumer.
 *
 * This is NOT a sixth R3 mirror pair: there is one printer, in one language, and PHP never prints. What is
 * pinned is that ONE implementation's output is legible to BOTH parsers.
 */

/**
 * @return list<array{name: string, note?: string, condition: array<string, mixed>, text: string}>
 */
function conditionSerializerCases(): array
{
    // Read with a plain path rather than `base_path()`: this runs at Pest's COLLECTION time, before the
    // application container the helper would need exists.
    $path = dirname(__DIR__, 2).'/fixtures/condition-serializer.json';
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    /** @var list<array{name: string, note?: string, condition: array<string, mixed>, text: string}> $decoded */
    return $decoded;
}

/**
 * Every `${key}` the fixture's STRUCTURE names, in first-appearance order. A structural walk of the JSON —
 * deliberately not a PHP re-implementation of `toCondition()`, which would be the second classifier Doc #27
 * amendment D2 forbids. It knows only where keys live, not which shapes are representable.
 *
 * @param  array<string, mixed>  $condition
 * @return list<string>
 */
function conditionFixtureKeys(array $condition): array
{
    $keys = [];

    $walk = function (array $node) use (&$walk, &$keys): void {
        $operands = match ($node['kind']) {
            'group' => null,
            'compare' => [$node['left'], $node['right']],
            'blank' => [$node['subject']],
            default => [],
        };

        if ($node['kind'] === 'group') {
            foreach ($node['children'] as $child) {
                $walk($child);
            }

            return;
        }

        if ($node['kind'] === 'count') {
            $keys[] = $node['section'];

            return;
        }

        if ($node['kind'] === 'selected') {
            $keys[] = $node['field'];

            return;
        }

        foreach ($operands ?? [] as $operand) {
            if ($operand['kind'] === 'field') {
                $keys[] = $operand['key'];
            }
        }
    };

    $walk($condition);

    return array_values(array_unique($keys));
}

it('parses every canonical string the TypeScript serializer can emit', function (array $case): void {
    $parser = makeExpressionParser();

    $ast = $parser->parse($case['text']);

    // …and the references land where the structured condition says they do. A printer that emitted a
    // syntactically valid expression naming the WRONG key would parse fine and pass a parse-only assertion.
    expect($parser->referencedKeys($ast))->toEqualCanonicalizing(conditionFixtureKeys($case['condition']));
})->with(array_combine(
    array_column(conditionSerializerCases(), 'name'),
    array_map(static fn (array $case): array => [$case], conditionSerializerCases()),
));

it('reads a non-trivial fixture with unique names, covering every condition kind', function (): void {
    // Anti-vacuity, the `PdfFieldRoleTest` shape: a fixture that failed to load, or a dataset that silently
    // collapsed to zero rows, would make the assertion above pass by never running.
    $cases = conditionSerializerCases();

    expect(count($cases))->toBeGreaterThanOrEqual(24);
    expect(count(array_unique(array_column($cases, 'name'))))->toBe(count($cases));

    $kinds = array_unique(array_map(static fn (array $c): string => $c['condition']['kind'], $cases));
    sort($kinds);
    expect($kinds)->toBe(['blank', 'compare', 'count', 'group', 'selected']);

    // At least one case must carry a group nested inside a group and at least one a negation — the two
    // shapes a flat, unnegated fixture would leave entirely unparsed on this side.
    $nested = static function (array $c) use (&$nested): bool {
        return $c['kind'] === 'group' && array_reduce(
            $c['children'],
            static fn (bool $carry, array $child): bool => $carry || $child['kind'] === 'group' || $nested($child),
            false,
        );
    };
    expect(array_filter($cases, static fn (array $c): bool => $nested($c['condition'])))->not->toBeEmpty();
    expect(array_filter(
        $cases,
        static fn (array $c): bool => $c['condition']['kind'] === 'selected' && $c['condition']['negated'] === true,
    ))->not->toBeEmpty();
});

it('would fail loudly on text this parser cannot read, so the pass above is evidence', function (): void {
    // The anti-vacuity twin of the parse assertion: it proves `parse()` throws rather than returning
    // something falsy that an `expect()` would wave through. `= =` is the exact shape the seeded
    // `Logic Notices Demo` carries as its invalid case.
    expect(fn () => makeExpressionParser()->parse('${age} = = 1'))->toThrow(ExpressionSyntaxException::class);
});
