<?php

declare(strict_types=1);

use App\Rules\ContentBlocks;
use App\Support\Forms\Glossary;

// M245 (`R-d0bbb7bb`) — a form's glossary: the matching key a marked word finds its term by, and the shape a snapshot
// and a template carry. Pure: no container, no database.
//
// ⛔ THE KEY HAS A TWIN. `glossaryKey()` in `resources/js/components/builder/glossary.ts` reads the same vectors
// (`tests/fixtures/glossary-term-keys.json`), so a change to either half that the fixture does not carry reddens a
// suite. ⚠️ Helpers are prefixed `glossary*`: Pest loads every file into one process.

/** @return array<string, array{string, string}> */
function glossaryKeyVectors(): array
{
    /** @var array{vectors: list<array{string, string}>} $doc */
    $doc = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/glossary-term-keys.json'), true, flags: JSON_THROW_ON_ERROR);

    $cases = [];
    foreach ($doc['vectors'] as $position => [$input, $key]) {
        $cases['#'.($position + 1).' '.$key] = [$input, $key];
    }

    return $cases;
}

it('matches a word to its term by the key it shares with the respondent app', function (string $input, string $key): void {
    expect(Glossary::key($input))->toBe($key);
})->with(glossaryKeyVectors());

it('reads a key fixture that is not empty, so the vectors above can never pass by being absent', function (): void {
    expect(glossaryKeyVectors())->toHaveCount(13);
});

it('cleans a term and a definition to one line of single spaces, keeping a term’s case', function (): void {
    expect(Glossary::cleanTerm('  Barangay'.chr(9).'Health  Worker '))->toBe('Barangay Health Worker')
        ->and(Glossary::cleanDefinition("One line.\nAnother line.\r\n\r\nA third."))->toBe('One line. Another line. A third.');
});

it('refuses a term that could never be marked as a glossary word', function (string $term, ?string $reason): void {
    expect(Glossary::termProblem($term))->toBe($reason);
})->with([
    'a term' => ['TWG', null],
    'the longest term' => [str_repeat('a', ContentBlocks::MAX_TERM_LENGTH), null],
    'empty' => ['', 'is empty'],
    'too long' => [str_repeat('a', ContentBlocks::MAX_TERM_LENGTH + 1), 'is longer than '.ContentBlocks::MAX_TERM_LENGTH.' characters'],
    'a bar' => ['a|b', 'holds a “|” or a control character'],
    'a control character' => ['a'.chr(7).'b', 'holds a “|” or a control character'],
]);

it('accepts a glossary as a snapshot carries it, and an empty one', function (): void {
    expect(Glossary::problem([['term' => 'TWG', 'definition' => 'The technical working group.']]))->toBeNull()
        ->and(Glossary::problem([]))->toBeNull();
});

it('refuses a glossary a template could not have come from', function (mixed $value, string $reason): void {
    expect(Glossary::problem($value))->toContain($reason);
})->with([
    'not a list' => [['TWG' => 'x'], 'list of terms'],
    'an entry that is a list' => [[['TWG', 'x']], 'term 1 is not a term and its definition'],
    'an extra key' => [[['term' => 'TWG', 'definition' => 'x', 'id' => 1]], 'term 1 is not a term and its definition'],
    'a missing definition' => [[['term' => 'TWG']], 'term 1 is not a term and its definition'],
    'a term that is a number' => [[['term' => 42, 'definition' => 'x']], 'term 1 is not text'],
    'extra spacing' => [[['term' => ' TWG', 'definition' => 'x']], 'term 1 has extra spacing'],
    'an empty term' => [[['term' => '', 'definition' => 'x']], 'term 1 is empty'],
    'a bar' => [[['term' => 'a|b', 'definition' => 'x']], 'term 1 holds a “|”'],
    'an empty definition' => [[['term' => 'TWG', 'definition' => ' ']], 'term 1’s definition is empty'],
    'a long definition' => [[['term' => 'TWG', 'definition' => str_repeat('a', Glossary::MAX_DEFINITION_LENGTH + 1)]], 'longer than '.Glossary::MAX_DEFINITION_LENGTH],
    'a repeat by key' => [[['term' => 'TWG', 'definition' => 'x'], ['term' => 'twg', 'definition' => 'y']], 'term 2 repeats “twg”'],
    'too many' => [array_map(static fn (int $n): array => ['term' => "T{$n}", 'definition' => 'x'], range(1, Glossary::MAX_ENTRIES + 1)), 'at most '.Glossary::MAX_ENTRIES],
]);

it('reads a snapshot’s glossary defensively, and an older snapshot’s as empty', function (): void {
    expect(Glossary::fromSnapshot(['sections' => [], 'fields' => []]))->toBe([])
        ->and(Glossary::fromSnapshot(null))->toBe([])
        ->and(Glossary::fromSnapshot(['glossary' => 'x']))->toBe([])
        ->and(Glossary::fromSnapshot(['glossary' => [['term' => 'TWG', 'definition' => 'x'], ['term' => 1], 'junk']]))
        ->toBe([['term' => 'TWG', 'definition' => 'x']]);
});

it('orders entries by key, then byte-wise by term, never by number', function (): void {
    $sorted = Glossary::sorted([
        ['term' => 'b', 'definition' => '1'],
        ['term' => '9', 'definition' => '2'],
        ['term' => 'B', 'definition' => '3'],
        ['term' => '10', 'definition' => '4'],
        ['term' => 'a', 'definition' => '5'],
    ]);

    expect(array_column($sorted, 'term'))->toBe(['10', '9', 'a', 'B', 'b']);
});
