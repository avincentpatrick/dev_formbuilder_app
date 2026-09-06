<?php

declare(strict_types=1);

/*
 * The hand-off, generated (M42).
 *
 * WHY THIS EXISTS. The hand-off was an 18,425-byte single line in PROGRESS.md, rewritten by hand every
 * increment and copied by hand into every new session. It restated the protocol, the traps, the
 * namespaces and the merged status of five increments — and this project has already banned that
 * pattern twice, each time after the copies contradicted each other: Standing Rule 7(b) for the lane
 * boundary, and docs/gate-baselines.md for gate numbers. The hand-off was the third instance and it was
 * measurably wrong in BOTH lanes at once, which is the predicted failure of a document that must be
 * hand-rewritten every increment rather than carelessness.
 *
 * So it is split three ways and none of the three is a copy:
 *
 *   the protocol   -> CLAUDE.md, which is auto-loaded and which this line POINTS AT and never restates.
 *   the numbers    -> scripts/state.php, derived from the tree.
 *   recent lessons -> the newest ## RELEASED sections of the lane's own claim file, read at render time.
 *                     Every increment already writes its transferable lesson there; retyping it here is
 *                     what made two hand-offs disagree with each other and with the tree.
 *
 * ⛔ THE RENDERED LINE OPENS WITH A MACHINE-READABLE [state ...] BLOCK, AND ITS POSITION IS THE POINT.
 * scripts/state.php --check reads it ONLY at that one offset, immediately after the hand-off arrow, so
 * a later sentence quoting the same bytes cannot arm it. Three separate attempts in this repository
 * have now failed by letting a MENTION read as a DECLARATION — preflight scraping any number literal as
 * a spend, R7 matching its surgery marker anywhere in a commit range, and this increment's own first
 * draft going red on the claim that introduced it. Natural language cannot carry a machine token.
 *
 * ⛔ THE BODY CARRIES NO BACKTICK. The tracker wraps the whole hand-off in a single inline-code span,
 * so one backtick inside it terminates the span and corrupts every line after it. Derived text is
 * stripped, not trusted, because it comes from a claim file that is full of them.
 *
 * ⛔ AND --write SPLICES BY PRE-MEASURED LINE INDEX, NEVER BY SEARCH-AND-REPLACE. PROGRESS.md contains
 * a verbatim example of the hand-off marker inside the prose that governs it; a splice that replaced
 * from the FIRST match deleted 1,086 lines on 2026-08-16 and merged green.
 *
 * Usage:
 *   php scripts/next.php [--lane=a]              # render to STDOUT, exactly as the tracker holds it
 *   php scripts/next.php [--lane=a] --write      # replace that lane's line in PROGRESS.md, in place
 *
 * Exit 0 = rendered. Exit 1 = a --write assertion failed. Exit 2 = could not measure.
 */

const TRACKER = 'PROGRESS.md';

// ONE LANE SINCE M50 -- docs/adr/0022-single-lane-development.md. Lane B's entry is gone, so its
// hand-off line can no longer be regenerated and `--lane=b` is refused. docs/claims/lane-b.md is
// still READ by scripts/state.php for the increment number; it is an archive, not a lane.
const LANES = [
    'a' => [
        'claim' => 'docs/claims/lane-a.md',
        'worktree' => 'c:\\laragon\\www\\dev_formbuilder_app',
        'stack' => 'dev_formbuilder_app-* on 8080/5432/6379/5173',
    ],
];

// Four, because that is how many increments the hand-off this replaces actually carried. Each release
// already states its own transferable lesson in its lead paragraph; older ones stay in the claim file,
// which is one Read away and is the authority anyway.
const RECENT_RELEASES = 4;

// A lead paragraph is a headline here, not the record. The claim file holds the whole thing and this
// line names it, so clipping costs nothing and keeps the hand-off readable in one screen.
const LESSON_CHARS = 220;

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['lane::', 'write']);
$lane = strtolower((string) ($opts['lane'] ?? 'a'));
$write = isset($opts['write']);

if (! isset(LANES[$lane])) {
    fwrite(STDERR, "next: unknown lane '{$lane}'. There is one lane since M50 - use --lane=a.\n");
    exit(1);
}

$state = read_state();
$line = render_line($lane, $state);

if (! $write) {
    fwrite(STDOUT, $line."\n");
    exit(0);
}

write_line($lane, $line);
exit(0);

/**
 * One authority, referenced rather than copied: the numbers are not re-derived here.
 *
 * @return array<string, mixed>
 */
function read_state(): array
{
    $status = 0;
    $json = sh('php '.escapeshellarg('scripts/state.php').' --json --no-rows 2>&1', $status);
    $state = json_decode($json, true);

    if ($status !== 0 || ! is_array($state)) {
        fwrite(STDERR, "next: CANNOT MEASURE — scripts/state.php exited {$status}.\n");
        fwrite(STDERR, '      '.trim($json)."\n");
        exit(2);
    }

    return $state;
}

/**
 * @param  array<string, mixed>  $state
 */
function render_line(string $lane, array $state): string
{
    $config = LANES[$lane];
    $upper = strtoupper($lane);
    $decisions = $state['decisions']['open'];

    // ⛔ THE EFFECTIVE COUNT, NOT THE RAW ONE (M70). A commit inside `ci.yml`'s `paths-ignore` produces
    //    NO RUN AT ALL and therefore cannot have invalidated the baselines — and a close-out is four or
    //    five such commits, so the raw number told every incoming session the file was stale on the one
    //    occasion it provably was not. Both are named here, because a single number leaves a reader
    //    unable to tell a quiet trunk from a parser that harvested nothing.
    $effectiveBehind = $state['baselines']['commits_behind_effective'] ?? $state['baselines']['commits_behind_main'];

    $baselines = $state['baselines']['commits_behind_main'] === null
        ? 'docs/gate-baselines.md carries no measurable provenance — regenerate it'
        : sprintf(
            'docs/gate-baselines.md is %d commit(s) behind the trunk that could produce a CI run, of %d raw%s',
            $effectiveBehind,
            $state['baselines']['commits_behind_main'],
            $effectiveBehind > 0 ? ' — regenerate it' : '',
        );

    $parts = [
        sprintf(
            '[state next=M%d adr=%s migration=%s]',
            $state['increment']['next_free'],
            $state['adr']['next_free'],
            $state['migration']['next_free'],
        ),
        sprintf('You are LANE %s, worktree %s, stack %s.', $upper, $config['worktree'], $config['stack']),
        'GENERATED BY php scripts/next.php --lane='.$lane.' — do not hand-edit this line, regenerate it.',
        '⛔ READ CLAUDE.md FIRST. It is the imperative layer and this line deliberately does not restate it.',
        sprintf('Then: php scripts/preflight.php --lane=%s and php scripts/state.php.', $lane),
        'Every number in the block above was derived from the tree by state.php and none of it was typed;'
            .' the ADR gap is RESERVED, not free, and state.php says so on every run.',
        sprintf('main IS THE TRUNK: branch from origin/main, PR into main, self-merge on 6/6 green with each'
            .' job\'s step count read individually. Your claim goes in %s and is PUSHED before you open the'
            .' first file.', $config['claim']),
        // ⛔ M67 — THE UNIT OF WORK IS DERIVED FROM `D13`, NOT HARD-CODED, BECAUSE THE HAND-EDIT THAT
        //    CARRIED IT BEFORE WAS INVISIBLE HERE AND THIS GENERATOR SILENTLY DROPPED IT.
        //    `D13` answered the question "how should the remaining rows be worked" with *in batches of 3–4*,
        //    and `M66` put that into its hand-off BY HAND — the one thing the line above forbids. So the
        //    instruction lived only in a string this script had never heard of, and the first faithful
        //    regeneration (M67's) reverted the queue to single rows with nothing reporting the loss.
        //    `git log -S BATCHED -- scripts/next.php` returns nothing, which is how that was established.
        //    ⚠️ AN ANSWERED DECISION THAT ONLY A HAND-EDIT CARRIES IS ONE REGENERATION FROM GONE.
        in_array('D13', $state['decisions']['answered'] ?? [], true)
            ? 'THE QUEUE IS docs/pipeline.md — one GENERATED ordered line holding every remaining task, '
                .'plan work first and the defect ledger behind it ('.(int) $state['pipeline']['rows'].' rows, '
                .(int) $state['pipeline']['held'].' held). A held row sits in it with its blocker named: do not '
                .'start one and do not offer it as the next step, but it IS counted. Then: take the next BATCHED '
                .'increment under D13 — 3-4 live rows sharing no non-hub file, at most one'
                .' hub-touching row — from docs/feature-backlog.md ('.$state['backlog']['open'].' open, '
                .$state['backlog']['by_severity']['major'].' major), ranked in docs/backlog-triage.md, which is'
                .' GENERATED from the tree — regenerate it rather than reading a stale one, and treat its order'
                .' as operability and not priority. D13\'s saving is proven and the batch size is not to be'
                .' revisited; plan against its own ~42% model rather than any single increment\'s figure.'
                .' Verify each row\'s evidence, its remedy AND its premise separately, and record them per row.'
            : 'THE QUEUE IS docs/pipeline.md ('.(int) $state['pipeline']['rows'].' rows, '
                .(int) $state['pipeline']['held'].' held, generated). Then take the next row from '
                .'docs/feature-backlog.md — '.$state['backlog']['open'].' open ('
                .$state['backlog']['by_severity']['major'].' major), ranked in docs/backlog-triage.md, which is'
                .' GENERATED from the tree — regenerate it rather than reading a stale one, and treat its order'
                .' as operability and not priority. Verify the row\'s evidence and its remedy separately.',
        $decisions === []
            ? 'No open decisions.'
            : 'Open decisions: '.implode(', ', $decisions).' — do not re-ask them and do not stall; record a'
                .' recommendation and take the next row in the same turn.',
        $baselines.'; never restate its figures here.',
        'RECENT LESSONS, read from the newest releases in '.$config['claim'].' rather than retyped: '
            .implode(' ', recent_lessons($config['claim'])),
        sprintf('On finish: close the row, release the claim, regenerate the baselines from your own merge run,'
            .' php scripts/next.php --lane=%s --write, then a 3-5 bullet status and the bare next prompt.', $lane),
    ];

    return '**LANE '.$upper.' NEXT PROMPT →** `'.strip_backticks(implode(' ', $parts)).'`';
}

/**
 * The lead paragraph of each of the newest releases. Truncated at the Template heading first, for the
 * same reason state.php does it: both claim files carry a verbatim RELEASED example under that heading.
 *
 * @return list<string>
 */
function recent_lessons(string $claimPath): array
{
    if (! is_file($claimPath)) {
        return ['(no claim file at '.$claimPath.')'];
    }

    $body = (string) file_get_contents($claimPath);
    $parts = preg_split('/^## Template$/m', $body, 2);
    $live = is_array($parts) ? (string) $parts[0] : $body;

    // explode, never the regex newline class: without the unicode modifier it matches a byte that
    // occurs inside the UTF-8 encoding of the check mark, and this corpus is full of them.
    $lines = explode("\n", $live);
    $lessons = [];

    foreach ($lines as $i => $line) {
        if (count($lessons) >= RECENT_RELEASES || ! str_starts_with($line, '## RELEASED')) {
            continue;
        }

        $heading = trim(substr($line, strlen('## RELEASED')));
        $heading = trim(ltrim($heading, "—- \t"));
        $summary = '';

        for ($j = $i + 1; $j < count($lines) && $j < $i + 40; $j++) {
            $candidate = trim($lines[$j]);

            if ($candidate === '' || str_starts_with($candidate, '#')) {
                if ($summary !== '') {
                    break;
                }

                continue;
            }

            $summary .= ($summary === '' ? '' : ' ').$candidate;
        }

        $lessons[] = rtrim(collapse($heading), '.').' — '.clip(collapse($summary), LESSON_CHARS);
    }

    return $lessons === [] ? ['(no releases found in '.$claimPath.')'] : $lessons;
}

/**
 * Emphasis markers render LITERALLY inside an inline-code span, so they are noise in the one place this
 * text is ever read. Stripped here rather than in strip_backticks(), because the backtick rule is about
 * correctness and this one is only about legibility.
 */
function collapse(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', str_replace(['**', '__'], '', $text)));
}

/**
 * A lead paragraph is a headline, not the record. The full one is a single Read away in the claim file,
 * which this line names — and keeping the hand-off short is the entire point of generating it.
 */
function clip(string $text, int $limit): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    $cut = mb_substr($text, 0, $limit);
    $space = mb_strrpos($cut, ' ');

    return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' .,;:—-').' [...]';
}

/**
 * ⛔ THE WHOLE LINE LIVES INSIDE ONE INLINE-CODE SPAN IN THE TRACKER. A single backtick anywhere in the
 * body closes that span early and every following character renders as prose, which silently changes
 * what a session reads. Derived text comes from a claim file that is dense with them, so it is
 * stripped rather than trusted.
 */
function strip_backticks(string $text): string
{
    return str_replace('`', '', $text);
}

/**
 * Replace the lane's hand-off line by PRE-MEASURED INDEX, and assert the file afterwards.
 */
function write_line(string $lane, string $rendered): void
{
    $upper = strtoupper($lane);
    $marker = '/^\*\*LANE '.$upper.' NEXT PROMPT/';

    if (! is_file(TRACKER)) {
        fwrite(STDERR, 'next: CANNOT MEASURE — '.TRACKER." does not exist.\n");
        exit(2);
    }

    $body = (string) file_get_contents(TRACKER);

    if (! str_ends_with($body, "\n")) {
        fwrite(STDERR, 'next: refusing to splice — '.TRACKER." does not end with a newline.\n");
        exit(1);
    }

    $lines = explode("\n", $body);
    $indexes = [];

    foreach ($lines as $i => $line) {
        if (preg_match($marker, $line) === 1) {
            $indexes[] = $i;
        }
    }

    // ⛔ EXACTLY ONE, AND NOT "THE FIRST ONE". PROGRESS.md quotes the hand-off marker inside the rule
    //    that governs it; a splice that took the first match deleted 1,086 lines and merged green.
    if (count($indexes) !== 1) {
        fwrite(STDERR, 'next: refusing to splice — the LANE '.$upper.' marker appears '.count($indexes).
                       " time(s) at line start, expected exactly 1.\n");
        exit(1);
    }

    $before = count($lines);
    $lines[$indexes[0]] = $rendered;
    $out = implode("\n", $lines);

    if (count(explode("\n", $out)) !== $before) {
        fwrite(STDERR, "next: refusing to write — the line count moved during the splice.\n");
        exit(1);
    }

    file_put_contents(TRACKER, $out);

    // Read it back and assert, rather than assuming the write did what it said.
    $check = explode("\n", (string) file_get_contents(TRACKER));
    $found = 0;

    foreach ($check as $line) {
        $found += preg_match($marker, $line) === 1 ? 1 : 0;
    }

    if ($found !== 1 || count($check) !== $before || $check[$indexes[0]] !== $rendered) {
        fwrite(STDERR, 'next: WROTE A FILE THAT DOES NOT READ BACK AS EXPECTED — inspect '.TRACKER." now.\n");
        exit(1);
    }

    fwrite(STDOUT, sprintf(
        "next: %s line %d replaced (%d bytes, line count unchanged at %d).\n",
        TRACKER, $indexes[0] + 1, strlen($rendered), $before - 1
    ));
}

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $status);

    return implode("\n", $output);
}
