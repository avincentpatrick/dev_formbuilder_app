<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M175 (`R-f3fb2522`, `D139`) — every session starts by reading the testers' feedback.
|--------------------------------------------------------------------------
| The user (2026-10-16): "every start of a session, we will include always to check the feedback record if
| there is any and automatically insert it in the pipeline. but review it if its already fixed or ongoing".
| Three places carry it, and this file pins all three, because the obligation is only as real as the text
| a session reads first:
|  · CLAUDE.md's "Start every session" runs the pull, and "Closing out" marks Resolved only once the fix is live;
|  · PROGRESS.md's Standing Rules record the triage and why;
|  · scripts/preflight.php prints how many reports are New when the token file exists — and NEVER blocks on
|    it, because an unreachable testing server must not stop a session from building.
*/

/** The text of a `## ` section of a markdown file, up to the next `## ` heading. */
function sessionStartSection(string $file, string $heading): string
{
    $text = (string) file_get_contents(base_path($file));
    $start = strpos($text, "\n## {$heading}");
    expect($start)->not->toBeFalse("{$file} has no '## {$heading}' section");

    $end = strpos($text, "\n## ", (int) $start + 4);

    return substr($text, (int) $start, $end === false ? null : $end - (int) $start);
}

it('starts every session with the feedback pull, into the session scratchpad', function (): void {
    $start = sessionStartSection('CLAUDE.md', 'Start every session');

    expect($start)->toContain('php scripts/feedback-pull.php --out=')
        ->and($start)->toMatch('/scratchpad/i')
        ->and($start)->toMatch('/triage/i')
        // M179 (`D151`): what the testers said since the last pull is triaged like a new report.
        ->and($start)->toMatch('/REOPENED/')
        ->and($start)->toMatch('/comment/i')
        ->and($start)->toContain('--comment=');
});

it('closes out by marking the fixed reports Resolved, and only once the deploy is live', function (): void {
    $close = sessionStartSection('CLAUDE.md', 'Closing out');

    expect($close)->toContain('feedback-pull.php --resolve=')
        ->and($close)->toMatch('/only once the deploy is live/i')
        // M179: a relayed report is resolved by the reference its row cites.
        ->and($close)->toContain('--resolve=<id|reference>');
});

it('records the triage rule and its reason among the Standing Rules', function (): void {
    $rules = sessionStartSection('PROGRESS.md', 'Standing Rules');

    expect($rules)->toContain('D139')
        ->and($rules)->toMatch('/already fixed/i')
        ->and($rules)->toMatch('/already queued/i')
        ->and($rules)->toContain('early-testing')
        ->and($rules)->toContain('during-testing')
        ->and($rules)->toContain('--user-approved')
        // M179: the threads and the relayed comments are part of the rule.
        ->and($rules)->toContain('D151')
        ->and($rules)->toMatch('/reopened/i')
        ->and($rules)->toMatch('/by reference/i');
});

it('prints the New count in preflight, before the result, and never blocks on it', function (): void {
    $source = (string) file_get_contents(base_path('scripts/preflight.php'));

    $feedback = strpos($source, 'section("Testers\' feedback")');
    $result = strpos($source, "section('Result')");
    expect($feedback)->not->toBeFalse('preflight has no feedback section')
        ->and($result)->not->toBeFalse()
        ->and($feedback)->toBeLessThan((int) $result);

    $block = substr($source, (int) $feedback, (int) $result - (int) $feedback);
    expect($block)->toContain('feedback-pull.php')
        ->and($block)->toContain('--count')
        // M179: the reopened reports and the members' new comments are counted too.
        ->and($block)->toContain('reopened=')
        // ⛔ The one property that matters: a testing server that is down, or a token that expired, must not
        // stop a session from building — so this section may inform and warn, and never fail.
        ->and($block)->not->toContain('$failures[]');
});
