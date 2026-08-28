<?php

declare(strict_types=1);

/*
 * Tracker lint (M40) — merge-blocking structural checks on PROGRESS.md and PROGRESS_ARCHIVE.md.
 *
 * WHY THIS IS A CI STEP AND NOT A LOCAL CHECK. On 2026-08-16 commit f565ac9 ("LANE A — J4b1 merged
 * as #158") deleted 1,086 lines from PROGRESS.md — the whole of Current Status, the roadmap and the
 * ledger — because a splice replaced from the FIRST occurrence of the hand-off marker, which is the
 * EXAMPLE a few lines up, through to the Lane B one. It MERGED GREEN. Nothing in CI reads a
 * documentation diff, so a local-only gate would not have caught it. That is the whole argument for
 * this file existing, and for it being registered as its own CI step rather than in composer only.
 *
 * ⛔ THE INCIDENT IS A J-SERIES ONE. Six sites in this repository attributed it to "M31" (four) or
 * "M16" (two) — every one of them invented by a later retelling, and three written by the two
 * increments immediately before this one. The only site that never named an increment was the only
 * one that stayed true. Settled from `git show --numstat f565ac9`, never from prose, which is the
 * same discipline this gate enforces on the files themselves.
 *
 * ⚠️ THE ANCHORS APPEAR VERBATIM INSIDE THE PROSE THEY GOVERN. Standing Rule 7(e) quotes the
 * hand-off marker; a status bullet once quoted its own heading. EVERY pattern here is therefore
 * line-anchored with /m — a substring count is simply wrong on this corpus, and was measured wrong
 * three times in one increment before the assertions were anchored.
 *
 * Usage:
 *   php scripts/tracker-lint.php            # all rules, failures only
 *   php scripts/tracker-lint.php --verbose  # print every measurement, not only the failures
 *
 * Exit 0 = clean. Exit 1 = a rule failed. Exit 2 = could not measure — NEVER a silent skip.
 */

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['verbose']);
$verbose = isset($opts['verbose']);

const TRACKER = 'PROGRESS.md';
const ARCHIVE = 'PROGRESS_ARCHIVE.md';

// The ceiling is a RATCHET, and the surgery has now turned it down once: 1,500,000 -> 600,000, with
// the tracker at ~514 KB. It is deliberately NOT set to the current size — a ceiling with no
// headroom reddens on the next ordinary close-out — and deliberately not to the plan's ~40 KB
// target, which is unreachable while Standing Rules (207 KB) and Next Session (226 KB) remain in
// this file. The headroom is printed on every run so a stale ceiling is visible rather than
// inferred, and the next increment to move either of those sections turns it down again.
const TRACKER_BYTE_CEILING = 600000;

// A drop larger than this needs an explicit marker in the commit message. The incident was 1,086.
const DROP_LIMIT = 200;
// ⛔ THE MARKER MUST START A LINE, AND THAT IS NOT COSMETIC. The first version matched it
// ANYWHERE in the commit range, so a commit whose message merely DISCUSSED the marker armed it:
// the surgery's own claim commit said its commits carry the marker or R7 refuses them, and R7
// duly reported the deletion as DECLARED before the surgery had even been committed. A mention
// is indistinguishable from a declaration unless the form is constrained — the same defect as a
// number in prose being read as a spend, found twice in one session. Requiring line-start makes
// declaring it deliberate and discussing it free.
const SURGERY_MARKER = '[tracker-surgery]';

// The archive carries a SECOND, STALE constitution: "## Standing Rules for This Project" plus a
// "## Next Session — Resume Here" that is byte-identical to the tracker's.
//
// ⛔ THIS WAS 2 UNTIL THE SURGERY, AND THE SURGERY LOWERED IT IN ITS OWN COMMIT. The gate shipped
// pinning the known-bad state of two, because asserting one would have been RED ON ARRIVAL and
// could never have merged; the hazard it blocked meanwhile was the one the plan named, "a naive
// append would make three". The archive's duplicate heading is now renamed, so exactly one
// remains and this is 1. ⚠️ A gate whose expectation an increment invalidates must be updated BY
// that increment, or main merges red.
const EXPECTED_CROSS_FILE_NEXT_SESSION = 1;

$failures = [];
$notes = [];

$tracker = read_or_die(TRACKER);
$archive = read_or_die(ARCHIVE);

// ── R1. Size ceiling. ────────────────────────────────────────────────────────────────────────────
$bytes = strlen($tracker);

if ($bytes > TRACKER_BYTE_CEILING) {
    fail('R1 size', sprintf('%s is %s bytes, over the %s ceiling. Move history into %s.',
        TRACKER, number_format($bytes), number_format(TRACKER_BYTE_CEILING), ARCHIVE));
} else {
    pass('R1 size', sprintf('%s is %s bytes, %s under the ceiling',
        TRACKER, number_format($bytes), number_format(TRACKER_BYTE_CEILING - $bytes)));
}

$notes[] = sprintf('%s is %s bytes (ceiling %s)',
    TRACKER, number_format($bytes), number_format(TRACKER_BYTE_CEILING));

// ── R2. Per-file heading uniqueness in the tracker. ──────────────────────────────────────────────
$headings = [
    'Standing Rules' => '/^## Standing Rules$/m',
    'Current Status' => '/^## Current Status$/m',
    'Next Session' => '/^## Next Session/m',
];

foreach ($headings as $name => $pattern) {
    $n = anchored_count($tracker, $pattern);

    if ($n !== 1) {
        fail('R2 headings', "'{$name}' appears {$n} time(s) at line start in ".TRACKER.', expected exactly 1.');
    } else {
        pass('R2 headings', "'{$name}' appears exactly once in ".TRACKER);
    }
}

// ── R3. Cross-file constitution count — see EXPECTED_CROSS_FILE_NEXT_SESSION above. ──────────────
$crossNext = anchored_count($tracker, '/^## Next Session/m') + anchored_count($archive, '/^## Next Session/m');

if ($crossNext !== EXPECTED_CROSS_FILE_NEXT_SESSION) {
    fail('R3 cross-file', sprintf(
        "'Next Session' appears %d time(s) across both tracker files, expected exactly %d. ".
        'A THIRD copy means a splice appended a new constitution instead of editing the existing one. '.
        'A FIRST means the surgery has landed, and this constant must be lowered in that same commit.',
        $crossNext, EXPECTED_CROSS_FILE_NEXT_SESSION));
} else {
    pass('R3 cross-file', "'Next Session' appears {$crossNext} time(s) across both files, as expected");
}

// ── R4. The archive must never grow a Current Status of its own. ─────────────────────────────────
$archiveStatus = anchored_count($archive, '/^## Current Status$/m');

if ($archiveStatus !== 0) {
    fail('R4 archive', ARCHIVE." carries {$archiveStatus} 'Current Status' heading(s); it must carry none.");
} else {
    pass('R4 archive', ARCHIVE." carries no 'Current Status' heading");
}

// ── R5. Encoding. A splice that rewrites line endings or drops the trailing newline fuses two lines
//    and swallows a bullet whole — measured, and it reported a delta of 0 where +1 was asserted. ───
foreach ([TRACKER => $tracker, ARCHIVE => $archive] as $path => $body) {
    if (str_contains($body, "\r")) {
        fail('R5 encoding', "{$path} contains CR bytes; this repository is pure LF.");
    } elseif (! str_ends_with($body, "\n")) {
        fail('R5 encoding', "{$path} does not end with a newline; the next append will fuse two lines.");
    } elseif (str_starts_with($body, "\xEF\xBB\xBF")) {
        fail('R5 encoding', "{$path} starts with a UTF-8 BOM.");
    } else {
        pass('R5 encoding', "{$path} is pure LF, no BOM, trailing newline present");
    }
}

// ── R6. One hand-off marker per lane, at line start. preflight asserts this locally; it is repeated
//    here because preflight is not a merge gate and this is. ───────────────────────────────────────
foreach (['A', 'B'] as $lane) {
    $n = anchored_count($tracker, '/^\*\*LANE '.$lane.' NEXT PROMPT/m');

    if ($n !== 1) {
        fail('R6 handoff', "The LANE {$lane} hand-off marker appears {$n} time(s) at line start, expected exactly 1.");
    } else {
        pass('R6 handoff', "The LANE {$lane} hand-off marker appears exactly once at line start");
    }
}

// ── R7. The rule that would have caught the incident. ────────────────────────────────────────────
//    A large removal is legitimate only when the commit message says so.
//
//    ⚠️ ON A pull_request EVENT actions/checkout HANDS US A MERGE COMMIT, so HEAD~1 is main's tip
//    and HEAD~1..HEAD is the PR's own commits — exactly the range whose messages must carry the
//    marker. On a push to main, HEAD~1 is the previous commit and the range is what was pushed.
//    Both are correct, and both need the parent REACHABLE, which is why ci.yml uses fetch-depth: 2.
//
//    ⛔⛔ IT IS HEAD~1 AND NEVER HEAD-CARET, AND THAT IS NOT A STYLE CHOICE. PHP's exec() runs through
//    cmd.exe on Windows, where the caret IS THE ESCAPE CHARACTER, so a rev-parse of HEAD-caret
//    returns HEAD itself. Measured on this host: HEAD and HEAD-caret both resolved to 216ea25 while
//    HEAD~1 gave f537bea. The check therefore compared the file against ITSELF, reported +0 forever,
//    and rev-parse --verify still SUCCEEDED so the cannot-measure guard below never fired. Green on
//    Linux CI and silently blind on every local run — a vacuous success inside the gate written to
//    end vacuous successes. Found only because a control COMMITTED its mutation rather than leaving
//    it in the working tree, which is what CI actually does.
//
//    ⛔ IF THE PARENT IS NOT REACHABLE THIS EXITS 2 RATHER THAN SKIPPING. A delta silently not
//    measured is a vacuous success, and this project already catalogues three separate kinds.
$parentOut = [];
$parentStatus = 0;
exec('git rev-parse --verify --quiet HEAD~1 2>&1', $parentOut, $parentStatus);

if ($parentStatus !== 0 || $parentOut === []) {
    fwrite(STDERR, "tracker-lint: CANNOT MEASURE R7 — HEAD~1 is not reachable (shallow clone?).\n");
    fwrite(STDERR, "              ci.yml must check out with fetch-depth: 2. Refusing to skip the check.\n");
    exit(2);
}

$beforeOut = [];
$showStatus = 0;
exec('git show HEAD~1:'.escapeshellarg(TRACKER).' 2>&1', $beforeOut, $showStatus);

if ($showStatus !== 0) {
    fwrite(STDERR, 'tracker-lint: CANNOT MEASURE R7 — '.TRACKER." does not exist at HEAD~1.\n");
    exit(2);
}

$before = count($beforeOut);
$after = substr_count($tracker, "\n");
$drop = $before - $after;

$msgOut = [];
exec('git log --format=%B HEAD~1..HEAD 2>&1', $msgOut);
$declared = preg_match('/^'.preg_quote(SURGERY_MARKER, '/').'/m', implode(chr(10), $msgOut)) === 1;

if ($drop > DROP_LIMIT && ! $declared) {
    fail('R7 delta', sprintf(
        '%s lost %d lines (%d down to %d), over the limit of %d, and no commit in HEAD~1..HEAD carries "%s". '.
        'This is the exact shape of the 1,086-line deletion of 2026-08-16 (f565ac9), which merged green. '.
        'If the removal is deliberate, put the marker at the START of a line in the commit message (a passing mention mid-sentence deliberately does NOT count). '.
        'IF THIS IS A POST-MERGE RUN ON main AND THE PR DID CARRY THE MARKER, THE SQUASH DISCARDED IT: '.
        'gh pr merge --squash --body "" empties the body, and the default body is exactly what would have '.
        'carried the marker through. M41 did that and reddened main for one commit. Pass a body containing '.
        'the marker, or put it in the PR title.',
        TRACKER, $drop, $before, $after, DROP_LIMIT, SURGERY_MARKER));
} elseif ($drop > DROP_LIMIT) {
    pass('R7 delta', sprintf('%s lost %d lines, declared with "%s"', TRACKER, $drop, SURGERY_MARKER));
    $notes[] = sprintf('DECLARED SURGERY: %d lines removed from %s', $drop, TRACKER);
} else {
    pass('R7 delta', sprintf('%s line delta is %+d (%d to %d)', TRACKER, -$drop, $before, $after));
}

$notes[] = sprintf('%s had %d lines at HEAD~1 and has %d now (%+d)', TRACKER, $before, $after, -$drop);

// ── R8. CLAUDE.md carries no namespace literal, because every one of them is derived (M42). ───────
//
//    WHY THIS IS A MERGE GATE AND NOT ADVICE. Standing Rule 7(g) declared a next-free ADR number from
//    M15 — the increment that spent it — until M38, twenty-three increments later, sitting directly
//    above its own sentence boasting it had done exactly this once before. Nothing caught it because
//    nothing in CI has ever read a documentation diff. CLAUDE.md is auto-loaded into every session, so
//    it is the artefact most likely to rot the same way and the one whose rot would be read first.
//
//    ⛔ WHY IT SCANS CLAUDE.md AND NOT PROGRESS.md, WHICH IS THE HARDER AND MORE IMPORTANT HALF. Inside
//    the tracker a live declaration and a dated quotation of a past one are THE SAME BYTES: the
//    constitution's forward claim and a RELEASED bullet recording what was true two months ago differ
//    only in which is still true. A rule over the tracker is therefore either RED ON ARRIVAL — which
//    M40 established can never merge — or vacuous. CLAUDE.md has the constraint by construction: its
//    whole contract is that it points rather than states, so ANY namespace literal in it is wrong, and
//    the rule needs no judgement about which. The tracker half stays unguarded and is filed as a row
//    rather than faked here.
//
//    The floor matters as much as the patterns: a deleted or emptied CLAUDE.md must FAIL, not pass for
//    free. Four lint gates gained a floor in M36 after controller-gate printed `passed` while blind.
const IMPERATIVES = 'CLAUDE.md';
const MIN_IMPERATIVE_LINES = 50;

if (! is_file(IMPERATIVES)) {
    fail('R8 imperatives', IMPERATIVES.' does not exist. It is auto-loaded into every session and is '.
        'the file this project reads first; a missing one is not a passing one.');
} else {
    $imperatives = read_or_die(IMPERATIVES);
    $imperativeLines = substr_count($imperatives, "\n");

    if ($imperativeLines < MIN_IMPERATIVE_LINES) {
        fail('R8 imperatives', sprintf(
            '%s has %d lines, below the floor of %d. A gate nobody can tell is blind is a gate nobody is running.',
            IMPERATIVES, $imperativeLines, MIN_IMPERATIVE_LINES));
    } else {
        pass('R8 imperatives', sprintf('%s has %d lines, over the floor of %d', IMPERATIVES, $imperativeLines, MIN_IMPERATIVE_LINES));
    }

    $literals = [
        'a migration prefix' => '/\d{4}_\d{2}_\d{2}_\d{6}/',
        'an increment number' => '/\bM\d{1,3}\b/',
        'an ADR sub-decision id' => '/§D\d+/u',
        'a "next free" declaration' => '/next free/i',
    ];

    foreach ($literals as $what => $pattern) {
        $hits = preg_match_all($pattern, $imperatives);

        if ($hits > 0) {
            fail('R8 imperatives', sprintf(
                '%s contains %d occurrence(s) of %s. That file states no numbers — it points at '.
                '`php scripts/state.php`, which derives them from the tree. A number written there is a '.
                'copy, and a copy is what went stale for twenty-three increments inside Standing Rule 7(g).',
                IMPERATIVES, $hits, $what));
        } else {
            pass('R8 imperatives', sprintf('%s contains no %s', IMPERATIVES, $what));
        }
    }

    if (substr_count($imperatives, "\r") !== 0) {
        fail('R8 imperatives', IMPERATIVES.' contains carriage returns; both tracker files are pure LF and so is this.');
    } elseif (! str_ends_with($imperatives, "\n")) {
        fail('R8 imperatives', IMPERATIVES.' does not end with a newline — a splice will fuse two lines.');
    } else {
        pass('R8 imperatives', IMPERATIVES.' is pure LF and ends with a newline');
    }

    $notes[] = sprintf('%s is %d bytes, %d lines, carrying no namespace literal', IMPERATIVES, strlen($imperatives), $imperativeLines);
}

// ── Report. ──────────────────────────────────────────────────────────────────────────────────────
foreach ($notes as $note) {
    fwrite(STDOUT, "tracker-lint: {$note}\n");
}

if ($failures !== []) {
    fwrite(STDERR, 'tracker-lint: FAILED — '.count($failures).' check(s) in '.
        count(array_unique($failures)).' rule group(s): '.implode(', ', array_unique($failures))."\n");
    exit(1);
}

fwrite(STDOUT, "tracker-lint: passed (8 rule groups, both tracker files and CLAUDE.md scanned).\n");
exit(0);

function fail(string $rule, string $message): void
{
    global $failures;

    $failures[] = $rule;
    fwrite(STDERR, "tracker-lint: [FAIL] {$rule} — {$message}\n");
}

function pass(string $rule, string $message): void
{
    global $verbose;

    if ($verbose) {
        fwrite(STDOUT, "tracker-lint: [ok]   {$rule} — {$message}\n");
    }
}

function read_or_die(string $path): string
{
    if (! is_file($path)) {
        fwrite(STDERR, "tracker-lint: CANNOT MEASURE — {$path} does not exist.\n");
        exit(2);
    }

    $body = file_get_contents($path);

    if ($body === false || $body === '') {
        fwrite(STDERR, "tracker-lint: CANNOT MEASURE — {$path} is unreadable or empty.\n");
        exit(2);
    }

    return $body;
}

function anchored_count(string $haystack, string $pattern): int
{
    return (int) preg_match_all($pattern, $haystack);
}
