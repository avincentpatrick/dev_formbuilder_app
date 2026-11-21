<?php

declare(strict_types=1);

use App\Rules\ContentBlocks;
use App\Services\Xlsform\XlsformMarkdown;

/*
|--------------------------------------------------------------------------
| M244 (`R-5957b273`, `R-7e134c37`): markdown in an XLSForm label, both ways. A note's markdown becomes content
| blocks the rule accepts strictly; a question's label keeps its words and loses only inline formatting; content
| written back out reads back to itself.
|--------------------------------------------------------------------------
*/

it('reads a note into headings, spans, callouts, dividers and list paragraphs', function (): void {
    $reading = (new XlsformMarkdown)->toBlocks("# Title\n\n## Part\n\n### Deeper\n\nSome **bold**, *italic*, `code` and [a link](https://example.org/x).\n\n- one\n- two\n\n3. three\n\n> quoted\n\n---");

    expect($reading['formatted'])->toBeTrue()
        ->and($reading['issues'])->toBe([])
        ->and($reading['blocks'])->toBe([
            ['type' => 'heading', 'level' => 1, 'text' => 'Title'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Part'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Deeper'],
            ['type' => 'paragraph', 'spans' => [
                ['text' => 'Some '],
                ['text' => 'bold', 'bold' => true],
                ['text' => ', '],
                ['text' => 'italic', 'italic' => true],
                ['text' => ', '],
                ['text' => 'code', 'code' => true],
                ['text' => ' and '],
                ['text' => 'a link', 'link' => 'https://example.org/x'],
                ['text' => '.'],
            ]],
            ['type' => 'paragraph', 'spans' => [['text' => '• one']]],
            ['type' => 'paragraph', 'spans' => [['text' => '• two']]],
            ['type' => 'paragraph', 'spans' => [['text' => '3. three']]],
            ['type' => 'callout', 'tone' => 'info', 'spans' => [['text' => 'quoted']]],
            ['type' => 'divider'],
        ])
        ->and(ContentBlocks::problem($reading['blocks'], true))->toBeNull();
});

it('reads a bold pair with a space just inside it as ODK renders it', function (): void {
    $markdown = new XlsformMarkdown;

    expect($markdown->toBlocks('**Watch out: **this one')['blocks'])->toBe([['type' => 'paragraph', 'spans' => [
        ['text' => 'Watch out:', 'bold' => true], ['text' => ' this one'],
    ]]])
        ->and($markdown->plainLabel('__Note: __here'))->toBe('Note: here')
        ->and($markdown->plainLabel('a \*\* literal \*\* pair'))->toBe('a \*\* literal \*\* pair');
});

it('starts a new paragraph at each line break, since a paragraph keeps none', function (): void {
    $reading = (new XlsformMarkdown)->toBlocks("first line\nsecond line");

    expect($reading['formatted'])->toBeTrue()
        ->and($reading['blocks'])->toBe([
            ['type' => 'paragraph', 'spans' => [['text' => 'first line']]],
            ['type' => 'paragraph', 'spans' => [['text' => 'second line']]],
        ]);
});

it('calls one paragraph of plain words unformatted', function (string $text): void {
    expect((new XlsformMarkdown)->toBlocks($text)['formatted'])->toBeFalse();
})->with([
    'words' => ['Please answer every question.'],
    'a lone star' => ['Rate 1-5*'],
    'snake_case' => ['Use first_name and last_name'],
    'a hash with no space' => ['#1 priority'],
]);

it('keeps an unsafe link as words, drops a picture and HTML tags, and reports each', function (): void {
    $reading = (new XlsformMarkdown)->toBlocks('See [this](javascript:alert(1)) and ![diagram](nhete-diagram.png) <span style="color:red">red</span>');

    expect($reading['blocks'])->toBe([['type' => 'paragraph', 'spans' => [['text' => 'See this and  red']]]])
        ->and($reading['issues'])->toBe([
            ['kind' => 'unsafe_link', 'detail' => 'javascript:alert(1)'],
            ['kind' => 'image', 'detail' => 'nhete-diagram.png'],
            ['kind' => 'html', 'detail' => ''],
        ])
        ->and(ContentBlocks::problem($reading['blocks'], true))->toBeNull();
});

it('keeps to the rule\'s limits: spans split at 2,000 characters, at most 50 to a paragraph, a long heading made bold text', function (): void {
    $long = str_repeat('a', 4500);
    $many = implode(' ', array_map(static fn (int $i): string => $i % 2 === 0 ? "**b{$i}**" : "w{$i}", range(1, 120)));
    $heading = '# '.str_repeat('h', 301);

    $markdown = new XlsformMarkdown;
    $split = $markdown->toBlocks($long)['blocks'];
    $chunked = $markdown->toBlocks($many)['blocks'];
    $bold = $markdown->toBlocks($heading)['blocks'];

    expect(array_map(static fn (array $span): int => mb_strlen($span['text']), $split[0]['spans']))->toBe([2000, 2000, 500])
        ->and(count($chunked))->toBeGreaterThan(1)
        ->and(max(array_map(static fn (array $block): int => count($block['spans']), $chunked)))->toBeLessThanOrEqual(ContentBlocks::MAX_SPANS)
        ->and($bold[0])->toBe(['type' => 'paragraph', 'spans' => [['text' => str_repeat('h', 301), 'bold' => true]]])
        ->and(ContentBlocks::problem([...$split, ...$chunked, ...$bold], true))->toBeNull();
});

it('takes inline formatting out of a label but keeps its words and markers', function (string $label, string $plain): void {
    expect((new XlsformMarkdown)->plainLabel($label))->toBe($plain);
})->with([
    'bold' => ['Your **name**, please', 'Your name, please'],
    'a link keeps its address' => ['See [the guide](https://example.org)', 'See the guide (https://example.org)'],
    'a numbered label is untouched' => ['1. Name', '1. Name'],
    'a heading keeps its hash' => ['# of **children**', '# of children'],
    'plain words are untouched' => ['How many children?', 'How many children?'],
    'html tags go' => ['<span style="color:red">Stop</span> here', 'Stop here'],
]);

it('cuts at a word boundary and marks the cut', function (): void {
    $text = str_repeat('word ', 200);

    $cut = XlsformMarkdown::cut($text, 500);

    expect(mb_strlen($cut))->toBeLessThanOrEqual(500)
        ->and($cut)->toEndWith('word…')
        ->and(XlsformMarkdown::cut('short', 500))->toBe('short');
});

it('writes content as markdown ODK reads, and the import reads it back to the same blocks', function (): void {
    $blocks = [
        ['type' => 'heading', 'level' => 1, 'text' => 'Welcome #1'],
        ['type' => 'heading', 'level' => 2, 'text' => 'Before you start'],
        ['type' => 'paragraph', 'spans' => [
            ['text' => 'Read the '],
            ['text' => 'guide', 'bold' => true, 'link' => 'https://example.org/guide'],
            ['text' => ' and note 5*3 = 15, a_b_c, [brackets] and `ticks`.'],
        ]],
        ['type' => 'paragraph', 'spans' => [['text' => '1. not a list '], ['text' => 'really', 'italic' => true]]],
        ['type' => 'callout', 'tone' => 'info', 'spans' => [['text' => 'Mind the '], ['text' => 'code', 'code' => true]]],
        ['type' => 'divider'],
        ['type' => 'paragraph', 'spans' => [['text' => '# not a heading > nor a quote']]],
    ];

    $markdown = new XlsformMarkdown;
    $written = $markdown->toMarkdown($blocks);

    expect($written)->toContain('[**guide**](https://example.org/guide)')
        ->and($markdown->toBlocks($written)['blocks'])->toBe($blocks);
});

it('moves a space just inside a mark outside it, keeping the mark', function (): void {
    $markdown = new XlsformMarkdown;
    $written = $markdown->toMarkdown([['type' => 'paragraph', 'spans' => [['text' => 'plain'], ['text' => ' bold ', 'bold' => true], ['text' => 'end']]]]);

    expect($written)->toBe('plain **bold** end')
        ->and($markdown->toBlocks($written)['blocks'])->toBe([['type' => 'paragraph', 'spans' => [
            ['text' => 'plain '], ['text' => 'bold', 'bold' => true], ['text' => ' end'],
        ]]]);
});

it('round-trips seeded random content through markdown', function (): void {
    mt_srand(244);
    $words = ['alpha', 'beta', '5*3', 'a_b', '[x]', 'tick`s', '#tag', '> gt', '1.', 'émoji 😀', 'back\\slash', '&amp;', '<b>'];
    $markdown = new XlsformMarkdown;

    for ($round = 0; $round < 60; $round++) {
        $spans = [];
        $count = mt_rand(1, 6);
        for ($i = 0; $i < $count; $i++) {
            $span = ['text' => $words[array_rand($words)].($i < $count - 1 ? ' ' : '')];
            foreach (['bold', 'italic', 'code'] as $mark) {
                if (mt_rand(0, 3) === 0) {
                    $span[$mark] = true;
                }
            }
            if (mt_rand(0, 5) === 0) {
                $span['link'] = 'https://example.org/'.$round;
            }
            $spans[] = $span;
        }
        $blocks = [['type' => mt_rand(0, 1) === 0 ? 'paragraph' : 'callout', 'spans' => $spans]];
        if ($blocks[0]['type'] === 'callout') {
            $blocks[0] = ['type' => 'callout', 'tone' => 'info', 'spans' => $spans];
        }

        $written = $markdown->toMarkdown($blocks);
        $read = $markdown->toBlocks($written)['blocks'];
        $marked = static fn (array $spans): bool => array_filter($spans, static fn (array $span): bool => count($span) > 1) !== [];

        // The words survive, the marks survive (measured: none of 300 seeded rounds fell back to plain words once edge
        // spaces moved outside every mark), and writing what was read gives the same markdown again.
        expect(XlsformMarkdown::plainText($read))->toBe(trim(XlsformMarkdown::plainText($blocks)))
            ->and($marked($read[0]['spans']))->toBe($marked($spans))
            ->and($markdown->toMarkdown($read))->toBe($written);
    }
});
