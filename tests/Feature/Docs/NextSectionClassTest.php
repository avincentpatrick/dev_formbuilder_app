<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Only a row that can be started is named as work (M215, `R-98b2d568`).
|--------------------------------------------------------------------------
| `scripts/state.php` used to read every Next bullet of `docs/pipeline.md` into one flat list, so
| `scripts/next.php`, `scripts/loop.php status` and `state.php` itself said "take work from" twelve rows
| nobody could start, and the sentence went into `PROGRESS.md` on the trunk.
|
| ⛔ THE GENERATOR AND THE READER SHARE NO CODE, SO THIS FILE HOLDS THE SEAM. `scripts/pipeline.php` prints
| the class and `scripts/next-section.php` reads it. Today no decision is open, so the real document has no
| `waiting on you` bullet to catch a reword of that class. The generator arm reads the formats out of
| `render_next()` itself, and it is the only thing guarding that class until a decision is next filed.
|
| ⚠️ THE WIRING ARM READS SOURCE, AND THAT IS ITS LIMIT. `state.php` and `next.php` run top to bottom and
| shell `git` and `gh`, so no test here can run them (`R-eecdb678`). It proves they call this file, not what
| they print: that was read by eye on the host, and the claim says so.
*/

require_once __DIR__.'/../../../scripts/next-section.php';

/**
 * A document shaped as the generator shapes it, with the given lines as its Next section.
 *
 * @param  list<string>  $section
 * @return list<string>
 */
function nextSectionDocument(array $section): array
{
    return [
        '# The pipeline',
        '- `before-next` — a bullet above the section · ready',
        '## Next',
        '',
        'From **during-testing**, the most urgent tier with open work.',
        '',
        ...$section,
        '',
        '## The line',
        '- `after-next` — a bullet below the section · ready',
    ];
}

/**
 * The source of `render_next()`, cut at the next function so a format elsewhere cannot stand in for it.
 */
function renderNextSource(): string
{
    $source = (string) file_get_contents(base_path('scripts/pipeline.php'));
    $start = strpos($source, 'function render_next(');

    expect($start)->not->toBeFalse('scripts/pipeline.php no longer defines render_next(), so this test cannot read it.');

    $end = strpos($source, "\nfunction ", (int) $start + 1);

    return substr($source, (int) $start, $end === false ? null : $end - (int) $start);
}

it('keeps the three classes apart', function (): void {
    $next = parse_next_section(nextSectionDocument([
        '- `R-one` — a row anyone can start · ready',
        '- `ocr-linelist` — a row that needs the user · blocked (user: needs 2–3 scanned sheets)',
        '- `R-two` — a row with a precondition · blocked (precondition: row is latent)',
        '- `D9` — a question · waiting on you',
    ]));

    expect($next['tier'])->toBe('during-testing')
        ->and($next['ready'])->toBe(['R-one'])
        ->and($next['blocked'])->toBe(['ocr-linelist', 'R-two'])
        ->and($next['waiting'])->toBe(['D9'])
        ->and($next['unclassified'])->toBe([]);
});

it('reads the class from the end of the bullet, whatever the headline or the blocker says', function (string $bullet, string $class): void {
    $next = parse_next_section(nextSectionDocument([$bullet]));

    expect($next[$class])->toBe(['R-x']);
})->with([
    'a headline holding the ready text, on a blocked row' => ['- `R-x` — says · ready here · blocked (a reason)', 'blocked'],
    'a blocker holding the ready text' => ['- `R-x` — a row · blocked (once this reads · ready)', 'blocked'],
    'a headline holding the blocked text, on a ready row' => ['- `R-x` — says · blocked (no) here · ready', 'ready'],
    'a headline holding the waiting text, on a ready row' => ['- `R-x` — says · waiting on you here · ready', 'ready'],
    'a blocker with brackets inside, cut by the generator' => ['- `R-x` — a row · blocked (user: each printed box (free) over this…)', 'blocked'],
    'an empty headline' => ['- `R-x` —  · ready', 'ready'],
]);

it('never names a bullet with no readable class as work, and never drops it', function (string $bullet): void {
    $next = parse_next_section(nextSectionDocument([$bullet]));

    expect($next['unclassified'])->toBe(['R-x'])
        ->and($next['ready'])->toBe([])
        ->and($next['blocked'])->toBe([])
        ->and($next['waiting'])->toBe([]);
})->with([
    'a reworded class' => ['- `R-x` — a row · startable'],
    'no class at all' => ['- `R-x` — a row'],
    'text after the class' => ['- `R-x` — a row · ready now'],
    'a blocked class that never closes' => ['- `R-x` — a row · blocked (a reason'],
]);

it('reads the Next section and nothing either side of it', function (): void {
    $next = parse_next_section(nextSectionDocument(['- `R-one` — a row · ready']));

    expect($next['ready'])->toBe(['R-one']);
});

it('reads a line ending in a carriage return as the same line', function (): void {
    $next = parse_next_section(["## Next\r", "From **during-testing**, the most urgent.\r", "- `R-one` — a row · ready\r"]);

    expect($next['tier'])->toBe('during-testing')
        ->and($next['ready'])->toBe(['R-one']);
});

it('reads how much the section left out, and nothing when it left out nothing', function (): void {
    $cut = parse_next_section(nextSectionDocument([
        '- `R-one` — a row · ready',
        '',
        '⚠️ **This section is CUT, and the rest of the tier is not shown here.** 156 more ready and 64 more',
        'blocked `during-testing` row(s) are in **The line** below — read it rather than this section before',
    ]));
    $whole = parse_next_section(nextSectionDocument(['- `R-one` — a row · ready']));

    expect($cut['ready_more'])->toBe(156)
        ->and($cut['blocked_more'])->toBe(64)
        ->and($whole['ready_more'])->toBe(0)
        ->and($whole['blocked_more'])->toBe(0);
});

it('reads an empty document as nothing, rather than refusing', function (): void {
    $next = parse_next_section([]);

    expect($next['tier'])->toBeNull()
        ->and($next['ready'])->toBe([])
        ->and(next_section_rows($next))->toBe(['next work' => '(the pipeline lists none)'])
        ->and(next_section_sentence($next))->toBe('The Next section lists no work outside held rows and open questions.');
});

it('names only the ready rows as work in the hand-off', function (): void {
    $sentence = next_section_sentence(parse_next_section(nextSectionDocument([
        '- `R-one` — a row · ready',
        '- `R-two` — a row · ready',
        '- `ocr-linelist` — a row · blocked (user: needs sheets)',
        '- `D9` — a question · waiting on you',
    ])));

    [$work, $notWork] = explode(' Not work, and shown so it stays visible: ', $sentence);

    expect($work)->toContain('Take work from the Next section — during-testing: R-one, R-two — and group')
        ->not->toContain('ocr-linelist')
        ->not->toContain('D9')
        ->and($notWork)->toBe('blocked ocr-linelist; waiting on the user D9.');
});

it('says nothing about work that is not there', function (): void {
    $sentence = next_section_sentence(parse_next_section(nextSectionDocument(['- `R-one` — a row · ready'])));

    expect($sentence)->not->toContain('Not work');
});

it('says so when a tier holds nothing that can be started, rather than printing an empty list', function (): void {
    $next = parse_next_section(nextSectionDocument(['- `ocr-linelist` — a row · blocked (user: needs sheets)']));

    expect(next_section_sentence($next))
        ->toStartWith('The Next section holds no ready during-testing row, so nothing in it can be started: read The line.')
        ->not->toContain('Take work from')
        ->and(next_section_rows($next))->toBe([
            'next work' => 'during-testing — nothing here is ready to start',
            'blocked, not work' => 'ocr-linelist',
        ]);
});

it('states the cut wherever it prints the ids, so twelve never reads as the whole tier', function (): void {
    $next = parse_next_section(nextSectionDocument([
        '- `R-one` — a row · ready',
        '- `ocr-linelist` — a row · blocked (user: needs sheets)',
        '',
        '⚠️ **This section is CUT, and the rest of the tier is not shown here.** 156 more ready and 64 more',
    ]));

    expect(next_section_rows($next))->toBe([
        'next work' => 'during-testing — R-one (1 of 157 ready; the rest are in The line)',
        'blocked, not work' => 'ocr-linelist (1 of 65 blocked; the rest are in The line)',
    ])->and(next_section_sentence($next))
        ->toContain('during-testing: R-one (1 of 157 ready; the rest are in The line) — and group')
        ->toContain('blocked ocr-linelist (1 of 65 blocked; the rest are in The line).');
});

it('says when a bullet could not be read, and names it nowhere as work', function (): void {
    $next = parse_next_section(nextSectionDocument(['- `R-one` — a row · ready', '- `R-odd` — a row · startable']));

    expect(next_section_unreadable($next))->toContain('R-odd')
        ->and(next_section_sentence($next))->toContain('Take work from the Next section — during-testing: R-one — and group')
        ->toContain('no readable class, so they are not named as work: R-odd.')
        ->and(next_section_rows($next)['next work'])->not->toContain('R-odd')
        ->and(next_section_unreadable(parse_next_section(nextSectionDocument(['- `R-one` — a row · ready']))))->toBeNull();
});

it('carries no backtick in the hand-off, which the tracker wraps in one code span', function (): void {
    // next.php strips them as well; this keeps the rule true of the text as written.
    $sentence = next_section_sentence(parse_next_section(nextSectionDocument([
        '- `R-one` — a row · ready',
        '- `ocr-linelist` — a row · blocked (user: needs sheets)',
        '- `R-odd` — a row · startable',
    ])));

    expect(str_contains($sentence, '`'))->toBeFalse();
});

it('classifies every bullet of the real line', function (): void {
    $body = (string) file_get_contents(base_path('docs/pipeline.md'));
    $lines = explode("\n", $body);
    $next = parse_next_section($lines);

    // The floor: an empty read must not pass. Counted here on its own terms, not through the parser.
    $start = array_search('## Next', array_map(static fn (string $l): string => rtrim($l, "\r"), $lines), true);

    expect($start)->not->toBeFalse('docs/pipeline.md has no Next heading. Regenerate it with php scripts/pipeline.php.');

    $bullets = 0;

    foreach (array_slice($lines, (int) $start + 1) as $line) {
        if (str_starts_with($line, '## ')) {
            break;
        }

        $bullets += str_starts_with($line, '- `') ? 1 : 0;
    }

    if ($next['tier'] === null) {
        // Nothing open is a real state of the line one day, and it has its own sentence.
        expect($body)->toContain('Nothing is open outside held rows');

        return;
    }

    expect($bullets)->toBeGreaterThan(0, 'docs/pipeline.md names a tier and lists no row under it.')
        ->and($next['unclassified'])->toBe([], 'These Next bullets carry no class scripts/next-section.php can read, so '
            .'scripts/pipeline.php and it have drifted apart.')
        ->and(count($next['ready']) + count($next['blocked']) + count($next['waiting']))->toBe($bullets);
});

it('reads every class the generator can print', function (): void {
    // ⛔ EXTRACTED, NEVER RESTATED: a copy of these formats would go on passing after render_next() moved.
    preg_match_all('/sprintf\(\s*"(- `%s` — %s · [^"]*?)\\\\n"/u', renderNextSource(), $found);

    expect($found[1])->toHaveCount(3, 'render_next() should print three classes of bullet: ready, blocked and '
        .'waiting. Extraction found '.count($found[1]).', so this test can no longer read the generator.');

    $bullets = array_map(
        static fn (string $format, int $i): string => sprintf($format, 'id-'.$i, 'a headline', 'a reason'),
        $found[1],
        array_keys($found[1])
    );

    $next = parse_next_section(nextSectionDocument($bullets));

    expect($next['ready'])->toBe(['id-0'])
        ->and($next['blocked'])->toBe(['id-1'])
        ->and($next['waiting'])->toBe(['id-2'])
        ->and($next['unclassified'])->toBe([]);
});

it('reads the cut note the generator prints', function (): void {
    preg_match('/"\\\\n(⚠️ \*\*This section is CUT[^"]*?)\\\\n"/u', renderNextSource(), $found);

    // ⚠️ NOT `toHaveKey(1, $message)`: its second argument is the value expected at that key.
    expect(isset($found[1]))->toBeTrue('render_next() no longer writes the cut note this test reads.');

    $next = parse_next_section(nextSectionDocument([sprintf($found[1], 156, 64)]));

    expect($next['ready_more'])->toBe(156)
        ->and($next['blocked_more'])->toBe(64);
});

it('has all three reports read the section through this one file', function (): void {
    $state = (string) file_get_contents(base_path('scripts/state.php'));
    $handoff = (string) file_get_contents(base_path('scripts/next.php'));
    $loop = (string) file_get_contents(base_path('scripts/loop.php'));

    foreach (['state.php' => $state, 'next.php' => $handoff, 'loop.php' => $loop] as $name => $source) {
        expect(str_contains($source, "require_once __DIR__.'/next-section.php';"))
            ->toBeTrue('scripts/'.$name.' no longer loads scripts/next-section.php.');
    }

    expect(str_contains($state, '$out[\'next\'] = parse_next_section(lines($body));'))
        ->toBeTrue('scripts/state.php no longer reads the Next section through parse_next_section().')
        ->and(str_contains($state, 'next_section_rows($gate[\'next\'])'))
        ->toBeTrue('scripts/state.php no longer prints the Next section through next_section_rows().')
        ->and(str_contains($loop, 'next_section_rows((array) $gate[\'next\'])'))
        ->toBeTrue('scripts/loop.php no longer prints the Next section through next_section_rows().')
        ->and(str_contains($handoff, 'next_section_sentence((array) $gate[\'next\'])'))
        ->toBeTrue('scripts/next.php no longer writes the hand-off through next_section_sentence().');
});
