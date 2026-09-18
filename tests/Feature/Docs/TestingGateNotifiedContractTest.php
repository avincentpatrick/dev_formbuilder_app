<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The `Notified:` line is a contract between two files, and this pins it (M99).
|--------------------------------------------------------------------------
| The testing gate's zero is an EVENT: the moment `before-testing` empties, the user is told the app
| is ready for a testing server. `M95` sent that notification on 2026-09-14. Every `state.php` run and
| every generated hand-off went on ordering the next session to send it again, and `PROGRESS.md`
| carried that order ON THE TRUNK for four increments, because nothing in the tree recorded that the
| event had been handled.
|
| ⛔ THE RECORD IS A `done` MARKER, BUT THE FACT TRAVELS AS A LINE OF PROSE, AND THAT IS THE SEAM.
| `scripts/state.php` derives the whole gate by regex over `docs/pipeline.md` — it never walks the
| corpus and never reads the generator's JSON — so `scripts/pipeline.php` PUBLISHES the answer on a
| `Notified:` line and `state.php` PARSES it back. Two files, one sentence, no shared code. Reword the
| render and the parser silently stops matching; `notified` falls back to null, the imperative goes
| quiet in the safe direction, and **nobody finds out until the next tier empties and the notification
| is never sent at all.** That is the failure this file exists to catch.
|
| ⚠️ THE REGEXES ARE EXTRACTED FROM `scripts/state.php`, NEVER RESTATED HERE. A copy of a pattern is
| the two-copies-of-a-fact defect this repository gates elsewhere, and a restated regex would go on
| passing against a parser that had changed underneath it — which is precisely the drift being gated.
| `state.php` is a top-to-bottom script that shells `git` and `gh` on include, so it cannot be
| required; reading its source for the one pattern is the honest seam, and the extraction failing is
| itself a failure rather than a skip.
|
| ⚠️ WHAT THIS DOES NOT CLAIM. It never asserts the notification was ACTUALLY SENT — no test can see
| the user's phone. What is gated is that the tree RECORDS an answer and that both halves of the
| mechanism still agree about how to read it, which is the same distinction `BacklogProvenanceTest`
| draws about liveness: a verdict is recorded, never that it is right.
*/

/**
 * The `Notified:` patterns as `scripts/state.php` actually spells them, read out of its source.
 *
 * @return array{state: string, by: string}
 */
function notifiedPatternsFromState(): array
{
    $source = file_get_contents(base_path('scripts/state.php'));

    expect($source)->toBeString();

    // Both patterns are single-quoted PHP literals on one line each. Anchored on `^Notified: ` so a
    // future pattern that stopped anchoring at line start would fail to extract rather than pass.
    preg_match_all("/'(\/\^Notified: [^']+)'/", (string) $source, $found);

    // ⚠️ THREE LITERALS MATCH, AND ONE OF THEM IS NOT A PARSER. `derive_testing_gate()` FINDS the line
    //    with a bare `^Notified: ` pattern before parsing it, so that one is dropped — by VALUE rather
    //    than by position, because a positional filter would silently pick the wrong pattern the day a
    //    fourth literal is added.
    $parsers = array_values(array_filter(
        $found[1],
        static fn (string $pattern): bool => rtrim(ltrim($pattern, '/'), '/u') !== '^Notified: '
    ));

    expect($parsers)
        ->toHaveCount(2, 'scripts/state.php should carry exactly two ^Notified: PARSING patterns — the '
            .'state and the attribution — beside the bare finder. Extraction found '.count($parsers)
            .', so this test can no longer read the parser it is meant to pin.');

    return ['state' => $parsers[0], 'by' => $parsers[1]];
}

function pipelineNotifiedLine(): string
{
    $lines = explode("\n", (string) file_get_contents(base_path('docs/pipeline.md')));
    $matches = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Notified: ')));

    expect($matches)->toHaveCount(1, 'docs/pipeline.md should carry exactly one line-start `Notified: ` '
        .'line, published by render_testing_gate(). Found '.count($matches).' — regenerate it with '
        .'php scripts/pipeline.php.');

    return $matches[0];
}

it('publishes a Notified line that state.php can still parse', function (): void {
    $patterns = notifiedPatternsFromState();

    // The parser's own pattern, against the generator's own output. If either side moves alone, this
    // is the assertion that goes red — and it is the ONLY place the two are ever compared.
    expect(preg_match($patterns['state'], pipelineNotifiedLine()))
        ->toBe(1, 'docs/pipeline.md\'s Notified line no longer matches the pattern scripts/state.php '
            .'parses it with. The generator and the parser have drifted, and the consequence is a '
            .'testing-server notification that is silently never ordered.');
});

it('carries the attribution clause whenever it reads yes', function (): void {
    $line = pipelineNotifiedLine();

    if (! str_starts_with($line, 'Notified: yes')) {
        expect($line)->toStartWith('Notified: no');

        return;
    }

    // A `yes` with no readable attribution is the shape that lets a wrong or unrecorded claim of
    // "already sent" sit in the tree unattributed — the notification is owed ONCE, so who owed it and
    // when is the whole of the record.
    expect(preg_match(notifiedPatternsFromState()['by'], $line))
        ->toBe(1, 'The Notified line reads yes but carries no parseable attribution clause, so '
            .'state.php cannot say WHO sent it or when.');
});

it('keys both imperative copies on the notified fact, and neither on the zero alone', function (): void {
    // ⛔ THE TWO IMPERATIVES ARE THE ONES THAT SAY *SEND IT NOW*, and next.php's is written INTO
    //    PROGRESS.md by --write, so a regression there lands on the trunk. The rule-phrased copies in
    //    pipeline.php and loop.php are deliberately unconditional and are NOT pinned here: keying
    //    those would delete the rule rather than silence an order. That division is D52's recorded
    //    answer, and reversing it should be a deliberate edit rather than an accident.
    // ⛔ NOT `toContain($needle, $message)` — ITS NEEDLES ARE VARIADIC. The second argument is read as
    //    a SECOND needle, not as a failure message, so that form asserts the message itself appears in
    //    the source and fails for the wrong reason. ClaimTemplateFieldsTest records M30 watching a case
    //    stay GREEN through the same trap, in the direction that hides a defect rather than inventing
    //    one. `str_contains` with a real message is the form that says what broke.
    foreach (['scripts/state.php', 'scripts/next.php'] as $path) {
        $source = (string) file_get_contents(base_path($path));

        expect(str_contains($source, "\$gate['notified'] ?? null) === false"))
            ->toBeTrue($path.' no longer guards its testing-server imperative on the notified fact. '
                .'Unkeyed, it orders every future session to send the user a duplicate notification — '
                .'which is exactly what it did for four increments after M95 sent the real one.');
    }
});
