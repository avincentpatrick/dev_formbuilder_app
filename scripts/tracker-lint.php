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

// The ceiling is a RATCHET and it has now been turned down THREE times: 1,500,000 -> 600,000 by the
// Current Status surgery, 600,000 -> 400,000 by the claim-ledger surgery that took Standing Rules
// from 208 KB to 46 KB, and 400,000 -> 200,000 by M48's Next Session surgery, which moved 200,625
// bytes out of a 360,207-byte file and left it at 161,298. It is deliberately NOT set to the current
// size — a ceiling with no headroom reddens on the next ordinary close-out — so this leaves ~38,700,
// roughly a dozen ordinary close-outs at the rate `## Current Status` has actually been growing.
//
// ⛔ THE ~40 KB TARGET IS STILL UNREACHABLE, AND THE SECTION BLOCKING IT HAS CHANGED — WHICH IS WHY
// THIS COMMENT NAMES THE MEASUREMENT AND NOT A SECTION IT INHERITED. The previous version said the
// target was unreachable "while Next Session (214 KB, and now 62% of this file) remains in it"; that
// section is now 15,269 bytes and 9.5%, and the sentence was falsified by the same commit that
// ratcheted this constant. What blocks the target now is `## Current Status` at 67,982 bytes — 42%
// of the file and its largest section — plus `## Standing Rules` at 49,001. The increment that turns
// this constant down again is the one that moves Current Status, and that is a filed row rather than
// a sentence here: a comment naming the next obligation is the thing that goes stale first.
// The headroom prints on every run, so a ceiling that has drifted is visible rather than inferred.
const TRACKER_BYTE_CEILING = 200000;

// ⛔ TWO THRESHOLDS, BECAUSE THE LINE ONE IS BLIND TO THE ONLY SURGERY THIS GATE HAS EVER SEEN.
// DROP_LIMIT is lines, and the incident is quoted everywhere as 1,086 — that is a numstat deletion
// count, and this gate computes a NET drop, so to R7 the incident is 1,085. The two differ by the
// single line f565ac9 added. But M45's claim-ledger surgery moved 161,528 bytes in 133 net lines
// and sailed under the limit: this file's hand-off and status bullets are single lines thousands of
// bytes long, so a line count is a poor proxy for how much of the constitution just left.
//
// ⛔ THE BYTE LIMIT IS SIZED FROM THE FULL HISTORY, NOT FROM INTUITION. Every commit touching this
// file on origin/main — 394 parent/child pairs, blob sizes read with git cat-file — splits cleanly
// in two, with an order of magnitude between the halves:
//
//   surgeries  938,007 (Current Status) · 670,409 (THE INCIDENT, f565ac9) · 307,867 · 272,006 ·
//              161,528 (M45 — 133 lines, and therefore invisible to DROP_LIMIT)
//   ordinary   14,340 (M42, one long generated hand-off line replaced) · 6,486 · 6,130 · 4,114 · ...
//
// 50,000 sits in that gap: 3.5x above the largest ordinary drop and 3.2x below the smallest surgery.
// Both deltas print on every run so a threshold that has drifted out of the gap is visible rather
// than inferred. ⚠️ The direction to watch is the ORDINARY half growing — that 14,340 was one
// hand-off line, and scripts/next.php generates it.
const DROP_LIMIT = 200;
const DROP_BYTE_LIMIT = 50000;
// ⛔ THE MARKER MUST START A LINE, AND THAT IS NOT COSMETIC. The first version matched it
// ANYWHERE in the commit range, so a commit whose message merely DISCUSSED the marker armed it:
// the surgery's own claim commit said its commits carry the marker or R7 refuses them, and R7
// duly reported the deletion as DECLARED before the surgery had even been committed. A mention
// is indistinguishable from a declaration unless the form is constrained — the same defect as a
// number in prose being read as a spend, found twice in one session. Requiring line-start makes
// declaring it deliberate and discussing it free.
//
// ⛔⛔ AND BARE LINE START WAS UNREACHABLE ON THE TRUNK, WHICH IS WHY EXACTLY ONE BULLET MAY NOW
// PRECEDE IT. GitHub's default squash body renders every commit subject as "* <subject>", so M45's
// surgery merged with the marker in the trunk message TWICE and R7 still matched nothing. Measured
// on that commit rather than reasoned about: run against `git log --format=%B 1f966a4~1..1f966a4`,
// the bare line-start pattern returns NO MATCH and the bullet-tolerant one matches. The relaxation
// is exactly one of "* ", "- " or "+ " and NO LEADING WHITESPACE — an indented continuation line
// still cannot arm it, which is precisely the property the paragraph above defends. It also closes
// the case where a merge is done through the web UI, which produces the same shape.
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

// ⛔ THE LANES THAT OWE A HAND-OFF. This was ['A', 'B'] until M50 collapsed the project to a single
// lane, and — exactly like the constant above — the collapse had to lower it IN ITS OWN COMMIT.
// Removing Lane B's hand-off line without editing this list takes R6 red on the trunk; that was
// measured before the change rather than reasoned about, by deleting the line against the unedited
// gate and watching R6 fail alone (1 check, 1 rule group, R3 untouched).
//
// ⚠️ AND THE EXISTENCE CHECK ALONE IS NOT ENOUGH, WHICH IS WHY R6 ALSO COMPARES THE WHOLE SET. A
// per-lane "appears exactly once" loop is blind to a marker for a lane that no longer exists: a
// resurrected `**LANE B NEXT PROMPT` would satisfy every iteration of the loop and be reported by
// nothing. Counting every `**LANE <X> NEXT PROMPT` and requiring the total to equal this list is
// what makes the retirement enforceable instead of merely conventional.
const HANDOFF_LANES = ['A'];

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
foreach (HANDOFF_LANES as $lane) {
    $n = anchored_count($tracker, '/^\*\*LANE '.$lane.' NEXT PROMPT/m');

    if ($n !== 1) {
        fail('R6 handoff', "The LANE {$lane} hand-off marker appears {$n} time(s) at line start, expected exactly 1.");
    } else {
        pass('R6 handoff', "The LANE {$lane} hand-off marker appears exactly once at line start");
    }
}

// The set, not just the members — see HANDOFF_LANES. A marker for a retired lane passes every
// iteration above and is caught only here.
$allHandoffs = anchored_count($tracker, '/^\*\*LANE [A-Z] NEXT PROMPT/m');

if ($allHandoffs !== count(HANDOFF_LANES)) {
    fail('R6 handoff', sprintf(
        '%s carries %d hand-off marker(s) at line start, expected exactly %d (%s). '.
        'A marker for a lane that no longer exists is the failure this counts: the per-lane loop '.
        'above cannot see one, because it only ever looks for the lanes it already knows about.',
        TRACKER, $allHandoffs, count(HANDOFF_LANES), implode(', ', HANDOFF_LANES)));
} else {
    pass('R6 handoff', sprintf('%s carries exactly %d hand-off marker(s), one per live lane (%s)',
        TRACKER, $allHandoffs, implode(', ', HANDOFF_LANES)));
}

// ── R7. The rule that would have caught the incident. ────────────────────────────────────────────
//    A large removal is legitimate only when the commit message says so.
//
//    ⛔⛔ THE BASE COMES FROM THE EVENT, NOT FROM THE COMMIT GRAPH, AND THAT IS THE WHOLE OF M49.
//    This rule spent its first eight increments assuming THE UNIT OF CHANGE IS ONE COMMIT. HEAD~1 is
//    the right base only when a push or a pull request contains exactly one, and the 2026-08-16
//    incident happened to be a single commit — which is the only reason the rule as first written
//    would ever have caught it. THE GATE WAS SIZED AGAINST A SAMPLE OF ONE.
//
//    Measured on M48's own push rather than predicted: `e82e835..5d4bd79` carried four commits, one
//    of which removed 198,909 bytes of this file. The run compared HEAD~1 — where the file is
//    ALREADY 161,298 bytes — against HEAD and reported a delta of ZERO. The largest removal since
//    the incident this rule exists for crossed the merge gate unmeasured, and green.
//
//    HOW THE BASE IS CHOSEN NOW. Keyed on GITHUB_EVENT_NAME, which GitHub always sets, so a ci.yml
//    edit that drops the variables below EXITS 2 instead of going quiet:
//
//      push          github.event.before, passed in as TRACKER_LINT_BASE_SHA. Required. The range is
//                    then every commit that was pushed, which is what the rule always meant.
//      pull_request  HEAD~1 — see the next paragraph — with the CLONE SHAPE asserted against
//                    github.event.pull_request.commits, passed in as TRACKER_LINT_PR_COMMITS.
//      schedule,     HEAD~1. A nightly or hand-fired run measures the trunk tip against its parent;
//      dispatch      whatever it carried was already measured by the push that created it.
//      unset         HEAD~1, the local run. Printed as such, never silently assumed.
//
//    ⚠️ AND THE ROW THAT FILED THIS PRESCRIBED github.event.pull_request.base.sha FOR THE SECOND ARM,
//    WHICH IS WRONG HERE — WRONG IN THE DIRECTION THAT PRINTS A NUMBER. base.sha is the base tip as
//    of the EVENT; the checkout is refs/pull/N/merge as of the RUN. When main advances between them,
//    which on a two-lane repository is routine and is already catalogued here as "a gate number
//    moving on a diff that cannot move it is the OTHER LANE", `base.sha..HEAD` sweeps in the other
//    lane's commits and reports their PROGRESS.md delta as this pull request's. The merge commit's
//    FIRST PARENT is the tip actually being merged into, and is exact. So the payload is spent on the
//    job base.sha cannot do: catching a clone too shallow to hold the commits being measured.
//
//    ⛔⛔ WHY A CLONE-SHAPE ASSERTION AT ALL. M48's other half: ci.yml used fetch-depth: 2, chosen by
//    M40 FOR THIS RULE, which leaves the merge commit and the PR's LAST commit and grafts every
//    earlier one away — so a marker on any but the final commit was invisible. R7 reported it absent
//    while measuring the delta perfectly, and CANNOT TELL THE TWO APART FROM INSIDE: "no commit in
//    this range carries the marker" is the same observation whether the commit is missing or the
//    marker is. Comparing the range's commit count against the payload's is what distinguishes them,
//    and it turns a silent re-blinding into a loud broken gate. The depth is 0 today; the value is one
//    line of YAML and the next person tuning clone time can lower it.
//
//    ⛔⛔ IT IS HEAD~1 AND NEVER HEAD-CARET, AND THAT IS NOT A STYLE CHOICE. PHP's exec() runs through
//    cmd.exe on Windows, where the caret IS THE ESCAPE CHARACTER, so a rev-parse of HEAD-caret
//    returns HEAD itself. Measured on this host: HEAD and HEAD-caret both resolved to 216ea25 while
//    HEAD~1 gave f537bea. The check therefore compared the file against ITSELF, reported +0 forever,
//    and rev-parse --verify still SUCCEEDED so the cannot-measure guard never fired. Green on Linux
//    CI and silently blind on every local run — a vacuous success inside the gate written to end
//    vacuous successes. For the same reason the commit-ness check below is `git cat-file -t` and not
//    a rev-parse with a brace suffix: NO CARET MAY APPEAR IN ANY COMMAND IN THIS FILE.
//
//    ⛔ EVERY PATH EITHER RESOLVES OR EXITS 2. None falls back silently, and a base that cannot be
//    established is never treated as a base of HEAD~1 — a delta measured against the wrong base is
//    worse than one not measured at all, because it prints a number.
$base = resolve_r7_base();

$parentOut = [];
$parentStatus = 0;
exec('git rev-parse --verify --quiet '.$base['ref'].' 2>&1', $parentOut, $parentStatus);

if ($parentStatus !== 0 || $parentOut === []) {
    cannot_measure_r7(
        $base['ref'].' is not reachable (shallow clone?).',
        'The base was chosen as '.$base['source'].'. ci.yml checks out with fetch-depth: 0 for this '.
        'rule. Refusing to skip the check.');
}

$beforeOut = [];
$showStatus = 0;
exec('git show '.$base['ref'].':'.escapeshellarg(TRACKER).' 2>&1', $beforeOut, $showStatus);

if ($showStatus !== 0) {
    cannot_measure_r7(TRACKER.' does not exist at '.$base['ref'].'.', 'Base chosen as '.$base['source'].'.');
}

$before = count($beforeOut);
$after = substr_count($tracker, "\n");
$drop = $before - $after;

// ⛔ THE BYTE SIZE IS READ FROM THE BLOB AND NOT RECONSTRUCTED FROM $beforeOut. exec() splits on
//    newlines and drops the trailing one, so a length rebuilt from that array is wrong by an amount
//    nobody can state in advance — and a threshold justified by a number the gate cannot compute is
//    the small version of the defect this rule is about. `git cat-file -s` answers what was asked.
$sizeOut = [];
$sizeStatus = 0;
exec('git cat-file -s '.$base['ref'].':'.escapeshellarg(TRACKER).' 2>&1', $sizeOut, $sizeStatus);

if ($sizeStatus !== 0 || $sizeOut === [] || preg_match('/^\d+$/', trim((string) $sizeOut[0])) !== 1) {
    cannot_measure_r7(
        'the size of '.TRACKER.' at '.$base['ref'].' is unreadable.',
        'git cat-file -s said: '.trim(implode(' ', $sizeOut)));
}

$beforeBytes = (int) trim((string) $sizeOut[0]);
$afterBytes = strlen($tracker);
$byteDrop = $beforeBytes - $afterBytes;

$overLines = $drop > DROP_LIMIT;
$overBytes = $byteDrop > DROP_BYTE_LIMIT;

$msgOut = [];
exec('git log --format=%B '.$base['ref'].'..HEAD 2>&1', $msgOut);
$declared = preg_match('/^(?:[*+-] )?'.preg_quote(SURGERY_MARKER, '/').'/m', implode(chr(10), $msgOut)) === 1;

$scale = sprintf('%d line(s) (%d down to %d, limit %d) and %s byte(s) (%s down to %s, limit %s)',
    $drop, $before, $after, DROP_LIMIT,
    number_format($byteDrop), number_format($beforeBytes), number_format($afterBytes),
    number_format(DROP_BYTE_LIMIT));

if (($overLines || $overBytes) && ! $declared) {
    fail('R7 delta', sprintf(
        '%s lost %s — over %s — and no commit in %s..HEAD declares it with "%s". '.
        'This is the shape of the deletion of 2026-08-16 (f565ac9), which merged green: 1,085 lines by this '.
        "gate's arithmetic (1,086 by numstat) and 670,409 bytes. ".
        'If the removal is deliberate, put the marker at the START of a line in the commit message. One "* ", '.
        '"- " or "+ " may precede it, because that is what a squash produces; a mention mid-sentence or on an '.
        'indented line deliberately does NOT count. '.
        'IF THIS IS A POST-MERGE RUN ON main, THE SQUASH IS THE LIKELY CAUSE, IN ONE OF TWO WAYS: '.
        'gh pr merge --squash --body "" empties the body entirely (M41 did that and reddened main for one '.
        "commit), and GitHub's DEFAULT body renders each subject as a bullet, which is how M45 merged with the ".
        'marker present twice and this rule matching nothing. Pass an explicit --body whose FIRST CONTENT LINE '.
        'is the marker. '.
        '⛔ DO NOT PUT IT IN THE PR TITLE: scripts/state.php anchors merged pull-request titles on ^M(\d{1,3}): '.
        'for the independent increment cross-check, so a marker prefix there silently drops this PR out of that '.
        'second source and trades one gate for another.',
        TRACKER, $scale, $overLines && $overBytes ? 'both limits' : ($overLines ? 'the line limit' : 'the byte limit'),
        $base['ref'], SURGERY_MARKER));
} elseif ($overLines || $overBytes) {
    pass('R7 delta', sprintf('%s lost %s, declared with "%s"', TRACKER, $scale, SURGERY_MARKER));
    $notes[] = sprintf('DECLARED SURGERY: %d line(s) and %s byte(s) removed from %s',
        $drop, number_format($byteDrop), TRACKER);
} else {
    pass('R7 delta', sprintf('%s delta is %+d line(s) and %+d byte(s), under both limits (%d lines, %s bytes)',
        TRACKER, -$drop, -$byteDrop, DROP_LIMIT, number_format(DROP_BYTE_LIMIT)));
}

// ⛔ THE BASE AND ITS PROVENANCE PRINT ON EVERY RUN, AND THAT LINE IS THE ARTEFACT. A future edit
//    that re-blinds this rule — a bounded fetch-depth, a dropped env var in ci.yml — is visible in
//    the run log as a base that is not the one the event named, instead of as a marker that is
//    mysteriously absent. M48 lost eight increments to exactly that ambiguity.
$notes[] = sprintf('R7 base is %s (%s)', $base['ref'], $base['source']);
$notes[] = sprintf('%s had %d lines / %s bytes at %s and has %d / %s now (%+d lines, %+d bytes)',
    TRACKER, $before, number_format($beforeBytes), $base['ref'], $after, number_format($afterBytes), -$drop, -$byteDrop);

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

/**
 * R7's base, and where it came from. See the block comment above R7 for why each arm is what it is.
 *
 * @return array{ref: string, source: string}
 */
function resolve_r7_base(): array
{
    $event = trim((string) getenv('GITHUB_EVENT_NAME'));

    if ($event === 'push') {
        $sha = trim((string) getenv('TRACKER_LINT_BASE_SHA'));

        if ($sha === '') {
            cannot_measure_r7(
                'GITHUB_EVENT_NAME is "push" but TRACKER_LINT_BASE_SHA is empty or unset.',
                'ci.yml must pass github.event.before into this step. REFUSING TO FALL BACK TO HEAD~1: '.
                'on a push of more than one commit HEAD~1 measures only the last of them, which is how '.
                'a 198,909-byte removal of '.TRACKER.' reached the trunk with a delta of zero.');
        }

        if (preg_match('/^0{40}$/', $sha) === 1) {
            cannot_measure_r7(
                'the push event carries the all-zero before-sha, which means the branch was created '.
                'by this push and has no previous tip.',
                'There is no base to measure against, so there is nothing to compare and no honest '.
                'delta to print. This should not occur on '.TRACKER."'s branch, which already exists.");
        }

        // `git cat-file -t` and NOT a rev-parse with a brace suffix: no caret may appear in any
        // command in this file. See the R7 block comment.
        $typeOut = [];
        $typeStatus = 0;
        exec('git cat-file -t '.escapeshellarg($sha).' 2>&1', $typeOut, $typeStatus);

        if ($typeStatus !== 0 || trim((string) ($typeOut[0] ?? '')) !== 'commit') {
            cannot_measure_r7(
                'TRACKER_LINT_BASE_SHA ('.$sha.') is not a commit in this clone.',
                'A shallow checkout grafts it away, and a force-push can orphan it. git cat-file said: '.
                trim(implode(' ', $typeOut)));
        }

        return ['ref' => $sha, 'source' => 'github.event.before, via TRACKER_LINT_BASE_SHA'];
    }

    if ($event === 'pull_request') {
        $declared = trim((string) getenv('TRACKER_LINT_PR_COMMITS'));

        if (preg_match('/^\d+$/', $declared) !== 1) {
            cannot_measure_r7(
                'GITHUB_EVENT_NAME is "pull_request" but TRACKER_LINT_PR_COMMITS is empty, unset or '.
                'not a number.',
                'ci.yml must pass github.event.pull_request.commits into this step. Without it nothing '.
                'can tell a clone too shallow to hold the marker from a commit that never carried one, '.
                'and those two look identical from inside this rule.');
        }

        $countOut = [];
        $countStatus = 0;
        exec('git rev-list --count HEAD~1..HEAD 2>&1', $countOut, $countStatus);

        if ($countStatus !== 0 || preg_match('/^\d+$/', trim((string) ($countOut[0] ?? ''))) !== 1) {
            cannot_measure_r7(
                'the commit count of HEAD~1..HEAD is unreadable on a pull_request event.',
                'git rev-list said: '.trim(implode(' ', $countOut)));
        }

        $inRange = (int) trim((string) $countOut[0]);
        // The PR's own commits plus the synthetic merge commit actions/checkout puts at HEAD.
        $expected = (int) $declared + 1;

        // >= and not ==, deliberately. The failure being guarded is the range holding FEWER commits
        // than the pull request has, which is what a bounded fetch-depth produces. A strict equality
        // would redden on branch topologies that are legal and uninteresting here — and a false red
        // in the one rule that must never cry wolf costs more than the extra commits it would catch.
        if ($inRange < $expected) {
            cannot_measure_r7(
                sprintf('HEAD~1..HEAD holds %d commit(s), but this pull request has %d, so %d of them '.
                    'are not in the clone.', $inRange, (int) $declared, $expected - $inRange),
                'THE CLONE IS TOO SHALLOW FOR THIS RULE. ci.yml must check out with fetch-depth: 0. '.
                'This is the M48 defect, and it is reported here rather than as an absent marker '.
                'because those two are indistinguishable from inside and one of them is a broken gate.');
        }

        return [
            'ref' => 'HEAD~1',
            'source' => sprintf("the merge commit's first parent; %d commit(s) in range against %d in "
                .'the pull request', $inRange, (int) $declared),
        ];
    }

    if ($event !== '') {
        return ['ref' => 'HEAD~1', 'source' => 'the trunk tip against its parent, on a '.$event.' event'];
    }

    return ['ref' => 'HEAD~1', 'source' => 'local run — GITHUB_EVENT_NAME is unset'];
}

function cannot_measure_r7(string $what, string $why): never
{
    fwrite(STDERR, "tracker-lint: CANNOT MEASURE R7 — {$what}\n");
    fwrite(STDERR, "              {$why}\n");
    exit(2);
}
