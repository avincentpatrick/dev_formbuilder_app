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

// The ceiling is a RATCHET, not the target. PROGRESS.md is ~1.44 MB and cannot be read in one call;
// the surgery that fixes that is its own increment. A ceiling set to the TARGET would be red on
// arrival and could never merge, so this ships just above current and the surgery lowers it. The
// headroom is printed on every run so a stale ceiling is visible rather than inferred.
const TRACKER_BYTE_CEILING = 1500000;

// A drop larger than this needs an explicit marker in the commit message. The incident was 1,086.
const DROP_LIMIT = 200;
const SURGERY_MARKER = '[tracker-surgery]';

// The archive carries a SECOND, STALE constitution: "## Standing Rules for This Project" plus a
// "## Next Session — Resume Here" that is byte-identical to the tracker's.
//
// ⛔ THE PLAN FOR THIS GATE ASKED FOR EXACTLY ONE OF EACH ACROSS BOTH FILES. Measured, that is RED
// ON ARRIVAL: the cross-file Next Session count is 2 today, so such a gate could never merge. The
// real hazard is the one the plan itself named — "a naive append would make three" — so two is
// pinned as the known state and three is the defect. The surgery lowers this to 1 in the same
// commit that removes the archive's duplicate, which is a deliberate, visible edit here.
const EXPECTED_CROSS_FILE_NEXT_SESSION = 2;

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
$declared = str_contains(implode("\n", $msgOut), SURGERY_MARKER);

if ($drop > DROP_LIMIT && ! $declared) {
    fail('R7 delta', sprintf(
        '%s lost %d lines (%d down to %d), over the limit of %d, and no commit in HEAD~1..HEAD carries "%s". '.
        'This is the exact shape of the 1,086-line deletion of 2026-08-16 (f565ac9), which merged green. '.
        'If the removal is deliberate, declare it in the commit message.',
        TRACKER, $drop, $before, $after, DROP_LIMIT, SURGERY_MARKER));
} elseif ($drop > DROP_LIMIT) {
    pass('R7 delta', sprintf('%s lost %d lines, declared with "%s"', TRACKER, $drop, SURGERY_MARKER));
    $notes[] = sprintf('DECLARED SURGERY: %d lines removed from %s', $drop, TRACKER);
} else {
    pass('R7 delta', sprintf('%s line delta is %+d (%d to %d)', TRACKER, -$drop, $before, $after));
}

$notes[] = sprintf('%s had %d lines at HEAD~1 and has %d now (%+d)', TRACKER, $before, $after, -$drop);

// ── Report. ──────────────────────────────────────────────────────────────────────────────────────
foreach ($notes as $note) {
    fwrite(STDOUT, "tracker-lint: {$note}\n");
}

if ($failures !== []) {
    fwrite(STDERR, 'tracker-lint: FAILED — '.count($failures).' check(s) in '.
        count(array_unique($failures)).' rule group(s): '.implode(', ', array_unique($failures))."\n");
    exit(1);
}

fwrite(STDOUT, "tracker-lint: passed (7 rule groups, both tracker files scanned).\n");
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
