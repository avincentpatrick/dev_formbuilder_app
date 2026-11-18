<?php

declare(strict_types=1);

use App\Support\Forms\PrintedContent;

// M245 (`R-c083b711`) — a note's content as paper prints it, and every string it prints, which the scan reader must know.
// Pure: no container, no database. ⚠️ Helpers are prefixed `printedContent*`: Pest loads every file into one process.

/** @return list<array<string, mixed>> */
function printedContentEveryKind(): array
{
    return [
        ['type' => 'heading', 'level' => 2, 'text' => 'Before you begin'],
        ['type' => 'heading', 'level' => 1, 'text' => '   '],
        ['type' => 'paragraph', 'spans' => [
            ['text' => 'Read the '],
            ['text' => 'leaflet', 'link' => 'https://example.org/leaflet', 'bold' => true],
            ['text' => ' and ask the '],
            ['text' => 'TWG', 'term' => 'TWG'],
            ['text' => ' about ', 'italic' => true],
            ['text' => '${name}', 'code' => true],
        ]],
        ['type' => 'callout', 'tone' => 'warning', 'spans' => [['text' => 'Fasting is required.']]],
        ['type' => 'list', 'style' => 'bulleted', 'items' => [['spans' => [['text' => 'Water']]], ['spans' => []], ['spans' => [['text' => 'Card']]]]],
        ['type' => 'list', 'style' => 'numbered', 'items' => [['spans' => [['text' => 'First']]], ['spans' => [['text' => 'Second']]]]],
        ['type' => 'divider'],
        ['type' => 'image', 'attachment_id' => '0192e2e0-0000-7000-8000-0000000000c1', 'alt' => 'A map'],
        ['type' => 'image', 'attachment_id' => '0192e2e0-0000-7000-8000-0000000000c2', 'alt' => null],
        ['type' => 'details', 'summary' => 'More about this', 'blocks' => [
            ['type' => 'paragraph', 'spans' => [['text' => 'Inside.']]],
            ['type' => 'details', 'summary' => 'Never', 'blocks' => []],
        ]],
        ['type' => 'video'],
        'junk',
    ];
}

it('prints each block as paper can: a link with its address, plain code and glossary words, ASCII list markers, details open', function (): void {
    $blocks = PrintedContent::blocks(printedContentEveryKind());

    expect(array_column($blocks, 'kind'))->toBe(['heading', 'paragraph', 'callout', 'list', 'list', 'divider', 'picture', 'details'])
        ->and($blocks[0])->toBe(['kind' => 'heading', 'level' => 2, 'text' => 'Before you begin'])
        ->and($blocks[1]['runs'])->toBe([
            ['text' => 'Read the ', 'bold' => false, 'italic' => false],
            ['text' => 'leaflet', 'bold' => true, 'italic' => false],
            ['text' => ' (https://example.org/leaflet)', 'bold' => false, 'italic' => false],
            ['text' => ' and ask the ', 'bold' => false, 'italic' => false],
            ['text' => 'TWG', 'bold' => false, 'italic' => false],
            ['text' => ' about ', 'bold' => false, 'italic' => true],
            ['text' => '${name}', 'bold' => false, 'italic' => false],
        ])
        ->and($blocks[3]['ordered'])->toBeFalse()
        ->and($blocks[3]['items'])->toHaveCount(2)
        ->and($blocks[4]['ordered'])->toBeTrue()
        ->and($blocks[6])->toBe(['kind' => 'picture', 'text' => 'Picture: A map'])
        ->and($blocks[7]['summary'])->toBe('More about this')
        ->and(array_column($blocks[7]['blocks'], 'kind'))->toBe(['paragraph']);
});

it('gives the scan reader every string it prints, a list item with and without its marker', function (): void {
    expect(PrintedContent::lines(PrintedContent::blocks(printedContentEveryKind())))->toBe([
        'Before you begin',
        'Read the leaflet (https://example.org/leaflet) and ask the TWG about ${name}',
        'Fasting is required.',
        'Water', '- Water',
        'Card', '- Card',
        'First', '1. First',
        'Second', '2. Second',
        'Picture: A map',
        'More about this',
        'Inside.',
    ]);
});

it('prints nothing for content it cannot read, and a glossary as its title and its entries', function (): void {
    expect(PrintedContent::blocks(null))->toBe([])
        ->and(PrintedContent::blocks(['not' => 'a list']))->toBe([])
        ->and(PrintedContent::blocks([['type' => 'paragraph', 'spans' => [['text' => '  ']]]]))->toBe([])
        ->and(PrintedContent::glossaryLines([]))->toBe([])
        ->and(PrintedContent::glossaryLines([['term' => 'TWG', 'definition' => 'The working group.']]))->toBe(['Glossary', 'TWG - The working group.']);
});
