<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M178 (`R-2d921bbe`, `D137`, `D143`) — a merge point runs between the user's data census, with no backup by hand.
|--------------------------------------------------------------------------
| `D137` made every merge point wait for the user's staging backup block until a deploy could protect the data
| itself. `M176` built that guard (every deploy backs up and counts every table around its migration) and merge
| point 2's second deploy proved it on the testing server (run 39190622862), so the block is retired. What stays
| is the user's census before and after — their own check that the data survived, independent of the deploy's.
| Four places carry the routine, and none was pinned before this file: CLAUDE.md "The trunk", the hand-off
| `scripts/next.php` generates, Standing Rule 5's reason in PROGRESS.md, and the runbook.
*/

/** The text of a `## ` section of a markdown file, up to the next `## ` heading. */
function mergePointSection(string $file, string $heading): string
{
    $text = (string) file_get_contents(base_path($file));
    $start = strpos($text, "\n## {$heading}");
    expect($start)->not->toBeFalse("{$file} has no '## {$heading}' section");

    $end = strpos($text, "\n## ", (int) $start + 4);

    return substr($text, (int) $start, $end === false ? null : $end - (int) $start);
}

it('runs a merge point between the user\'s census before and after, with no backup block, in CLAUDE.md', function (): void {
    $trunk = mergePointSection('CLAUDE.md', 'The trunk');

    expect($trunk)->toMatch('/after the user has taken the\s+staging data census/')
        ->and($trunk)->toMatch('/takes the\s+census again/')
        ->and($trunk)->toMatch('/every deploy backs the database up and counts every table/')
        ->and($trunk)->not->toMatch('/backup block/i');
});

it('says the same in the hand-off scripts/next.php generates', function (): void {
    $source = (string) file_get_contents(base_path('scripts/next.php'));
    $start = strpos($source, "'main IS THE TRUNK:");
    expect($start)->not->toBeFalse('next.php no longer emits the trunk sentence');

    $sentence = substr($source, (int) $start, (int) strpos($source, '$config[\'claim\']', (int) $start) - (int) $start);

    expect($sentence)->toContain('after the user has taken the staging')
        ->and($sentence)->toContain('takes the census again')
        ->and($sentence)->toContain('every deploy backs up and counts every table')
        ->and($sentence)->not->toMatch('/backup block/i');
});

it('records why the block is retired among the Standing Rules, and keeps the census', function (): void {
    $rules = mergePointSection('PROGRESS.md', 'Standing Rules');

    expect($rules)->toContain('THE USER\'S PER-MERGE BACKUP BLOCK IS RETIRED')
        ->and($rules)->toContain('R-2d921bbe')
        ->and($rules)->toContain('The census before and after each merge point stays')
        ->and($rules)->not->toContain('after the user runs the backup block');
});

it('says in the runbook that the deploy\'s own backup replaced the block, and the nightly task stays', function (): void {
    $runbook = (string) file_get_contents(base_path('docs/deployment-infrastructure.md'));

    expect($runbook)->toContain('It replaced the user\'s pre-merge backup block, which is retired')
        ->and($runbook)->toContain('The nightly task (§8.3) stays')
        ->and($runbook)->not->toContain('the user\'s pre-merge backup block still runs first');
});
