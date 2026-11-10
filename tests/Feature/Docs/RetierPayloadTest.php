<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| An answered retier is checked to have reached the row it names (M231, `R-f6eab58d`).
|--------------------------------------------------------------------------
| `M105` answered `D51` and `D53` and left both rows at their old tiers, and `pipeline-lint` passed it
| green. `scripts/retier-payload.php` reads every retier an answered decision commissions and judges it
| against the row; `scripts/state.php --check` exits 1 on one that never arrived.
|
| ⛔ ON THE REAL TREE THE CHECK IS GREEN ON ARRIVAL: of 12 payloads, 9 name closed rows, `D51`'s row is at
| its answered tier, and 2 ids no longer resolve. So the cases below are what can fail, and each was
| proven by a committed mutation (the claim records which).
|
| ⚠️ THE WIRING ARM READS SOURCE, AND THAT IS ITS LIMIT. `state.php` runs top to bottom and shells `git`
| and `gh`, so no test here can run it. What it prints was read on the host, and the claim says so.
*/

require_once __DIR__.'/../../../scripts/retier-payload.php';

/** The tier vocabulary, read out of `state.php` rather than copied, because every copy is pinned. */
function retierTiers(): array
{
    $source = (string) file_get_contents(base_path('scripts/state.php'));

    expect(preg_match('/^const TIERS = \[(.*)\];$/mu', $source, $m))->toBe(1, 'scripts/state.php no longer declares TIERS on one line.');

    preg_match_all("/'([a-z-]+)'/u", $m[1], $tiers);

    return $tiers[1];
}

/**
 * A decisions document with nothing open and the given lines answered.
 *
 * @param  list<string>  $answered
 */
function retierDocument(array $answered, array $open = []): string
{
    return implode("\n", ['# Decisions', '', '## OPEN', '', ...$open, '', '## ANSWERED', '', ...$answered, '']);
}

/** One answered decision whose answer line carries the given payload. */
function retierAnswer(string $decision, string $payload): array
{
    return [
        '### '.$decision.' — A question about something. **A — the recommendation.**',
        '',
        '**Answered 2026-10-15 (user decision, in chat).** **What the answer files, in the same push:** '.$payload,
        '',
    ];
}

/** @return array{id: string, state: string, tier: string, records_retier: bool} */
function retierRow(string $id, string $state, string $tier, bool $recordsRetier = false): array
{
    return ['id' => $id, 'state' => $state, 'tier' => $tier, 'records_retier' => $recordsRetier];
}

it('reads a single retier on an answer line', function (): void {
    $payloads = retier_payloads(retierDocument(retierAnswer('D9', '`R-0000000a` retiered `early-testing`.')));

    expect($payloads)->toBe([['decision' => 'D9', 'id' => 'R-0000000a', 'tier' => 'early-testing', 'shape' => 'answer']]);
});

it('reads every id in a list', function (): void {
    $payloads = retier_payloads(retierDocument(retierAnswer(
        'D9',
        'R-0000000f (`early-testing`); `R-0000000a`, `R-0000000b` and `R-0000000c` retiered `early-testing` as folded.',
    )));

    expect(array_column($payloads, 'id'))->toBe(['R-0000000a', 'R-0000000b', 'R-0000000c'])
        ->and(array_unique(array_column($payloads, 'tier')))->toBe(['early-testing']);
});

it('reads a retier written with a parenthetical and "is retiered"', function (): void {
    $payloads = retier_payloads(retierDocument(retierAnswer(
        'D9',
        'a marker is ready; and `R-0000000a` (ticks Vision never returns) is retiered `during-testing`, because its remedy is that arm.',
    )));

    expect($payloads)->toBe([['decision' => 'D9', 'id' => 'R-0000000a', 'tier' => 'during-testing', 'shape' => 'answer']]);
});

it('reads the tier after an arrow, never the one before it', function (): void {
    $payloads = retier_payloads(retierDocument(retierAnswer('D9', '`R-0000000a` is retiered `after-launch` → `during-testing`.')));

    expect(array_column($payloads, 'tier'))->toBe(['during-testing']);
});

it('reads a heading that asks for one row\'s tier, the keep form included', function (): void {
    $payloads = retier_payloads(retierDocument([
        '### D51 — `R-0000000a`, the sign-in loop, is tiered `early-testing`; it is unreachable. Which tier? **A — `before-launch`.**',
        '',
        '### D52 — `R-0000000b`, the welcome email, is tiered `early-testing`; it is latent. Which tier? **C — keep `early-testing`.**',
    ]));

    expect($payloads)->toBe([
        ['decision' => 'D51', 'id' => 'R-0000000a', 'tier' => 'before-launch', 'shape' => 'heading'],
        ['decision' => 'D52', 'id' => 'R-0000000b', 'tier' => 'early-testing', 'shape' => 'heading'],
    ]);
});

it('reads nothing that is not an answered retier', function (string $document): void {
    expect(retier_payloads($document))->toBe([]);
})->with([
    'an open question, which has no payload yet' => [retierDocument([], [
        '### D99 — `R-0000000a`, a row, is tiered `early-testing`. Which tier? **A — `before-launch`.**',
        '**What the answer files, in the same push:** `R-0000000a` retiered `before-launch`.',
    ])],
    'a row filed at a tier, which is not a retier' => [retierDocument(retierAnswer('D9', 'R-0000000a and R-0000000b (`early-testing`).'))],
    'a retier told in prose, off the answer line' => [retierDocument([
        '### D9 — A question. **A.**',
        'Later, `R-0000000a` retiered `early-testing` by hand.',
    ])],
    'a payload that names no id' => [retierDocument(retierAnswer('D9', 'the sixteen UI rows retiered `early-testing`.'))],
    'no ANSWERED heading at all' => ["# Decisions\n\n### D9 — `R-0000000a`, x. Which tier? **A — `before-launch`.**\n"],
]);

it('fails an open row the answer never reached, naming the decision, the row and both tiers', function (): void {
    $verdict = judge_retiers(
        [['decision' => 'D51', 'id' => 'R-0000000a', 'tier' => 'before-launch', 'shape' => 'heading']],
        [retierRow('R-0000000a', 'open', 'early-testing')],
        retierTiers(),
    );

    expect($verdict['failures'])->toHaveCount(1)
        ->and($verdict['failures'][0])->toContain('D51')->toContain('R-0000000a')
        ->toContain('`before-launch`')->toContain('`early-testing`')
        ->and($verdict['judged'])->toBe(1)
        ->and($verdict['unresolved'])->toBe([]);
});

it('passes a row the answer reached, a closed row, and a row that records why it moved again', function (array $row): void {
    $verdict = judge_retiers(
        [['decision' => 'D51', 'id' => 'R-0000000a', 'tier' => 'before-launch', 'shape' => 'heading']],
        [$row],
        retierTiers(),
    );

    expect($verdict['failures'])->toBe([])
        ->and($verdict['judged'])->toBe(1);
})->with([
    'open at the answered tier' => [retierRow('R-0000000a', 'open', 'before-launch')],
    'closed at another tier' => [retierRow('R-0000000a', 'closed', 'early-testing')],
    'open at another tier, with a retier recorded at the row' => [retierRow('R-0000000a', 'open', 'during-testing', true)],
]);

it('lists an id no row carries, and never fails it', function (): void {
    $verdict = judge_retiers(
        [['decision' => 'D53', 'id' => 'R-0000000e', 'tier' => 'early-testing', 'shape' => 'heading']],
        [retierRow('R-0000000a', 'open', 'early-testing')],
        retierTiers(),
    );

    expect($verdict['unresolved'])->toBe(['D53 R-0000000e'])
        ->and($verdict['failures'])->toBe([])
        ->and($verdict['judged'])->toBe(0);
});

it('fails a tier outside the vocabulary', function (): void {
    $verdict = judge_retiers(
        [['decision' => 'D9', 'id' => 'R-0000000a', 'tier' => 'before-lunch', 'shape' => 'answer']],
        [retierRow('R-0000000a', 'open', 'before-lunch')],
        retierTiers(),
    );

    expect($verdict['failures'])->toHaveCount(1)
        ->and($verdict['failures'][0])->toContain('`before-lunch`')->toContain('not a tier');
});

it('judges a row by the latest answer that names it', function (string $tier, int $failures): void {
    $verdict = judge_retiers(
        [
            ['decision' => 'D20', 'id' => 'R-0000000a', 'tier' => 'before-launch', 'shape' => 'answer'],
            ['decision' => 'D9', 'id' => 'R-0000000a', 'tier' => 'early-testing', 'shape' => 'answer'],
        ],
        [retierRow('R-0000000a', 'open', $tier)],
        retierTiers(),
    );

    expect($verdict['failures'])->toHaveCount($failures)
        ->and($verdict['pairs'])->toBe(2)
        ->and($verdict['judged'])->toBe(1);
})->with([
    'at the later answer\'s tier' => ['before-launch', 0],
    'at the earlier answer\'s tier' => ['early-testing', 1],
]);

it('reads a retier recorded at the row, and not one the row only quotes', function (string $body, bool $records): void {
    expect(row_records_retier($body))->toBe($records);
})->with([
    'retiered up' => ['- **`minor` · A row.** **Retiered up from `during-testing` 2026-10-15 by `M170`: the order.**', true],
    'retiered down' => ['- **`minor` · A row.** **Retiered down from `early-testing` 2026-10-03 by `M127`: not a comment.**', true],
    'the capitals, with an arrow' => ['- **`minor` · A row.** ⛔ **RETIERED `early-testing` → `before-launch` BY `M105` (2026-09-20).**', true],
    'quoted in a code span' => ['- **`minor` · A row.** It names the `**Retiered up from**` form.', false],
    'the word, not bolded' => ['- **`minor` · A row.** Nothing that retiered it is recorded here.', false],
    'nothing' => ['- **`minor` · A row.** **Live.**', false],
]);

it('reads the real decisions document: every shape present, and every tier in the vocabulary', function (): void {
    $payloads = retier_payloads((string) file_get_contents(base_path('docs/claims/decisions.md')));
    $pairs = array_map(static fn (array $p): string => $p['decision'].' '.$p['id'].' '.$p['tier'], $payloads);

    // ⚠️ A FLOOR, NOT A COUNT: a new answer adds payloads. 12 is what was measured when this was written.
    expect(count($payloads))->toBeGreaterThanOrEqual(12, 'docs/claims/decisions.md yields fewer retiers than were measured, so a shape was reworded.')
        ->and($pairs)->toContain('D51 R-5ecfa6cd before-launch', 'D132 R-4c4e35ae early-testing', 'D132 R-31ff7ade early-testing', 'D132 R-d85d51d2 early-testing', 'D104 R-a21fa6e8 during-testing')
        ->and(array_diff(array_column($payloads, 'tier'), retierTiers()))->toBe([]);
});

it('has scripts/state.php judge the retiers through this one file and gate on them', function (): void {
    $state = (string) file_get_contents(base_path('scripts/state.php'));

    expect(str_contains($state, "require_once __DIR__.'/retier-payload.php';"))
        ->toBeTrue('scripts/state.php no longer loads scripts/retier-payload.php.')
        ->and(str_contains($state, 'judge_retiers(retier_payloads(read_tracked_file(DECISIONS)), $backlog[\'severity_rows\'], TIERS)'))
        ->toBeTrue('scripts/state.php no longer judges the retiers it reads from decisions.md against the backlog.')
        ->and(str_contains($state, '\'records_retier\' => row_records_retier($row[\'body\']),'))
        ->toBeTrue('scripts/state.php no longer tells a row that records its retier from one that does not.')
        ->and(str_contains($state, 'foreach ($state[\'retiers\'][\'failures\'] as $message) {'))
        ->toBeTrue('scripts/state.php --check no longer fails on an answered retier that never reached its row.');
});
