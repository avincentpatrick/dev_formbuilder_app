<?php

declare(strict_types=1);

/*
 * Tracker-surgery verification harness (M71).
 *
 * WHY THIS EXISTS, AND WHY IT IS A COMMITTED FILE RATHER THAN A SCRATCH SCRIPT.
 *
 * A "tracker surgery" is the operation that moves dated records out of PROGRESS.md into
 * PROGRESS_ARCHIVE.md when `tracker-lint`'s R1 byte ceiling comes into range. It has now been
 * performed FOUR times — M41, M45, M48, M60 — and each one wrote a throwaway splice-and-verify
 * script in a session scratchpad and threw it away. `git log --all --diff-filter=A` under `scripts/`
 * finds no file whose name has ever contained *surgery*, *splice* or *verify*.
 *
 * ⛔ THE COST IS MEASURED AND IT IS ALWAYS THE CHECK, NEVER THE SURGERY.
 *
 *   M41  Byte conservation failed on its first run against a CORRECT tree, by exactly one byte: the
 *        formula omitted the JOIN SEAM between the old archive tail and the first inserted line. The
 *        truth is sum(P) + sum(H) + |P| + |H| + 1. See A2.
 *   M45  The paths-touched assertion failed TWICE, with the check at fault both times — first it
 *        compared committed state while phase 2 was still in the working tree, then its expected list
 *        omitted the gate-constants file. See A3.
 *   M48  Collapsed both files into ONE commit and thereby forfeited the independent git-level proof
 *        entirely; and three of its splices read a missing file, wrote a blank line and REPORTED
 *        SUCCESS — the succeeds-on-empty-input family. See A4 and the floors.
 *   M60  Held on its first run only because M41's corrected formula was used rather than re-derived,
 *        and it had to be recovered out of release PROSE because there was no code to inherit it from.
 *
 * Three surgeries, three defective first-run checks. `scripts/tracker-lint-controls.php` already
 * argues this case in its own words — "a control that is not committed is a control that ran once" —
 * and it was written about M47 discarding controls in a detached worktree, which is the stated reason
 * the fetch-depth defect survived eight increments. The tracker GATE has a committed control harness.
 * The tracker SURGERY did not, and the surgery is the operation with the blast radius.
 *
 * ⛔ WHAT THIS DOES NOT DO: IT DOES NOT PERFORM THE SURGERY. It verifies one that has already been
 * performed, against the state before it. That is deliberate. The splice itself is a handful of
 * `head`/`tail` calls by pre-measured line index, and CLAUDE.md already forbids the dangerous form
 * ("split by pre-measured line index, never by search" — a search once deleted 1,086 lines and merged
 * green because these files contain verbatim examples of their own anchors). What has never survived
 * is the PROOF, so the proof is what is kept.
 *
 * THE FOUR PROOFS, each independent of the others:
 *
 *   A1  COUNTED MULTISET OF LINE HASHES. Every line removed from the tracker appears in the archive
 *       with exactly the multiplicity it lost. ⛔ COUNTED, NEVER A SET: M45's 134 moved lines yielded
 *       130 distinct hashes because five blank lines shared one, and M48's 84 blank lines shared a
 *       single hash — so a set equality would have passed while silently dropping 83 lines.
 *   A2  EXACT BYTE CONSERVATION, with the bytes ADDED by the move stated rather than inferred, and
 *       with no tolerance. A conservation check with a fudge factor is not a conservation check.
 *   A3  PATHS TOUCHED, read from the WORKING TREE and compared as an exact set.
 *   A4  INDEPENDENT SLICE HASH. The removed lines, joined, hash to the same sha256 as the contiguous
 *       region that appeared in the archive. This is the proof that survives a wrong multiset
 *       implementation, because it shares no code with A1.
 *
 * ⛔ AND IT REFUSES RATHER THAN PASSING WHEN IT CANNOT MEASURE. Exit 2, never exit 0. Every floor
 * below comes from a measured failure in this repository, not from caution: an empty harvest, a
 * zero-byte snapshot, a move of nothing, an unstated added-byte count.
 *
 * Usage:
 *   php scripts/tracker-surgery.php --base=<ref> --added-bytes=<n> [--paths=<a,b,c>]
 *                                   [--tracker=<path>] [--archive=<path>]
 *                                   [--before-tracker=<path> --before-archive=<path>]
 *
 * The before-state comes from `git show <ref>:<path>` unless BOTH --before-* paths are given, which
 * is what lets the controls drive every proof with plain files and no git fixture at all.
 *
 * Exit: 0 verified · 1 a proof FAILED · 2 could not measure (which is never a pass)
 */

const SURGERY_EXIT_OK = 0;
const SURGERY_EXIT_FAILED = 1;
const SURGERY_EXIT_CANNOT_MEASURE = 2;

/**
 * A line that appears this many times or fewer is still counted individually.
 *
 * There is no threshold here and there must never be one: the constant exists only to name the fact
 * that multiplicity is tracked exactly. M48's 84 identical blank lines are the reason.
 */
const SURGERY_MULTISET_IS_COUNTED = true;

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', [
    'base:', 'added-bytes:', 'paths::', 'tracker::', 'archive::',
    'before-tracker::', 'before-archive::', 'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, surgery_usage());
    exit(SURGERY_EXIT_OK);
}

/** Refuse loudly. Never exit 0 from here — a harness that cannot measure has not passed. */
function surgery_refuse(string $why): never
{
    fwrite(STDERR, "tracker-surgery: CANNOT MEASURE — {$why}\n");
    fwrite(STDERR, "tracker-surgery: this is exit 2 and NOT a pass. A check that cannot see the\n");
    fwrite(STDERR, "                 operation is worse than no check, because it prints a verdict.\n");
    exit(SURGERY_EXIT_CANNOT_MEASURE);
}

function surgery_usage(): string
{
    return "Usage: php scripts/tracker-surgery.php --base=<ref> --added-bytes=<n> [--paths=a,b,c]\n".
        "                                       [--tracker=<path>] [--archive=<path>]\n".
        "                                       [--before-tracker=<path> --before-archive=<path>]\n\n".
        "Verifies a tracker surgery that has ALREADY been performed. Exit 0 verified, 1 failed,\n".
        "2 could not measure. See the block at the top of this file for what each proof is for.\n";
}

/** Read a file, refusing an empty or missing one — the M48 succeeds-on-empty-input family. */
function surgery_read(string $path, string $role): string
{
    if (! is_file($path)) {
        surgery_refuse("{$role} is not a file: {$path}");
    }

    $body = file_get_contents($path);

    if ($body === false || $body === '') {
        surgery_refuse("{$role} is empty ({$path}). An operation that succeeds on empty input is this ".
            'project\'s most-measured failure family.');
    }

    return $body;
}

/**
 * Split into lines.
 *
 * ⛔ `explode` AND NEVER `preg_split` ON `\R`. Without the `/u` modifier PCRE's `\R` matches the byte
 * 0x85, which occurs INSIDE common UTF-8 characters — the check mark is E2 9C 85 — and these files
 * are full of them. It silently shifts every line number after the first (M42).
 */
function surgery_lines(string $body): array
{
    return explode("\n", $body);
}

/** A counted multiset of line hashes: sha256 => occurrences. */
function surgery_multiset(array $lines): array
{
    $counts = [];

    foreach ($lines as $line) {
        $key = hash('sha256', $line);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

/** $a minus $b, per key, floored at zero. */
function surgery_multiset_diff(array $a, array $b): array
{
    $out = [];

    foreach ($a as $key => $n) {
        $left = $n - ($b[$key] ?? 0);
        if ($left > 0) {
            $out[$key] = $left;
        }
    }

    return $out;
}

function surgery_multiset_total(array $counts): int
{
    return array_sum($counts);
}

/** Run a command, returning [stdout, exitCode]. A pipe hides the exit status, so it is captured. */
function surgery_exec(string $command): array
{
    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    return [implode("\n", $output), $status];
}

// ── Inputs ───────────────────────────────────────────────────────────────────────────────────────

$trackerPath = (string) ($opts['tracker'] ?? 'PROGRESS.md');
$archivePath = (string) ($opts['archive'] ?? 'PROGRESS_ARCHIVE.md');

if (! isset($opts['added-bytes'])) {
    surgery_refuse('--added-bytes was not given. The bytes a surgery ADDS — new headings, the batch '.
        'preamble — must be STATED, never inferred from the residual: inferring them makes A2 an '.
        'identity that cannot fail.');
}

$addedBytes = (string) $opts['added-bytes'];

if (! preg_match('/^\d+$/', $addedBytes)) {
    surgery_refuse("--added-bytes must be a non-negative integer, got: {$addedBytes}");
}

$addedBytes = (int) $addedBytes;

$trackerAfter = surgery_read($trackerPath, 'tracker (after)');
$archiveAfter = surgery_read($archivePath, 'archive (after)');

$usingFileSnapshots = isset($opts['before-tracker']) && isset($opts['before-archive']);

if ($usingFileSnapshots) {
    $trackerBefore = surgery_read((string) $opts['before-tracker'], 'tracker (before)');
    $archiveBefore = surgery_read((string) $opts['before-archive'], 'archive (before)');
    $baseLabel = 'file snapshots';
} else {
    if (! isset($opts['base'])) {
        surgery_refuse('--base was not given and no --before-tracker/--before-archive pair was '.
            'supplied, so there is nothing to compare against.');
    }

    $base = (string) $opts['base'];

    [$resolved, $status] = surgery_exec('git rev-parse --verify '.escapeshellarg($base.'^{commit}'));

    if ($status !== 0) {
        surgery_refuse("--base does not resolve to a commit: {$base}");
    }

    $baseLabel = trim($resolved);

    foreach ([[$trackerPath, 'tracker'], [$archivePath, 'archive']] as [$path, $role]) {
        [$body, $status] = surgery_exec('git show '.escapeshellarg($baseLabel.':'.$path));
        if ($status !== 0) {
            surgery_refuse("{$role} does not exist at {$base}: {$path}");
        }
        if (trim($body) === '') {
            surgery_refuse("{$role} is empty at {$base}: {$path}");
        }
    }

    // Read again through the filesystem so byte counts are exact: exec() drops the trailing newline
    // and normalises nothing else, which is fine for existence but wrong for arithmetic.
    $tmp = sys_get_temp_dir().'/tracker-surgery-'.getmypid();
    @mkdir($tmp, 0o777, true);

    $trackerBeforeFile = $tmp.'/before-tracker';
    $archiveBeforeFile = $tmp.'/before-archive';

    [, $s1] = surgery_exec('git show '.escapeshellarg($baseLabel.':'.$trackerPath).' > '.escapeshellarg($trackerBeforeFile));
    [, $s2] = surgery_exec('git show '.escapeshellarg($baseLabel.':'.$archivePath).' > '.escapeshellarg($archiveBeforeFile));

    if ($s1 !== 0 || $s2 !== 0) {
        surgery_refuse('could not materialise the before-state from git');
    }

    $trackerBefore = surgery_read($trackerBeforeFile, 'tracker (before, from git)');
    $archiveBefore = surgery_read($archiveBeforeFile, 'archive (before, from git)');
}

$failures = [];
$notes = [];

// ── A1. Counted multiset of line hashes. ─────────────────────────────────────────────────────────

$trackerBeforeLines = surgery_lines($trackerBefore);
$trackerAfterLines = surgery_lines($trackerAfter);
$archiveBeforeLines = surgery_lines($archiveBefore);
$archiveAfterLines = surgery_lines($archiveAfter);

$removedFromTracker = surgery_multiset_diff(
    surgery_multiset($trackerBeforeLines),
    surgery_multiset($trackerAfterLines)
);

$gainedByArchive = surgery_multiset_diff(
    surgery_multiset($archiveAfterLines),
    surgery_multiset($archiveBeforeLines)
);

$removedTotal = surgery_multiset_total($removedFromTracker);
$gainedTotal = surgery_multiset_total($gainedByArchive);

if ($removedTotal === 0) {
    surgery_refuse('the tracker lost no lines, so there is no surgery here to verify. A harness that '.
        'reports success on a no-op is the vacuous success this file exists to prevent.');
}

$notes[] = sprintf('A1 tracker lost %d line(s) across %d distinct hash(es); archive gained %d across %d',
    $removedTotal, count($removedFromTracker), $gainedTotal, count($gainedByArchive));

// ⛔ Counted, per hash. A set comparison passes here while dropping every duplicate.
$missing = [];

foreach ($removedFromTracker as $key => $n) {
    $got = $gainedByArchive[$key] ?? 0;
    if ($got < $n) {
        $missing[] = substr($key, 0, 12).' lost '.$n.' from the tracker, archive gained '.$got;
    }
}

if ($missing !== []) {
    $failures[] = "A1 multiset — ".count($missing).' line hash(es) left the tracker without arriving in '.
        "the archive at the same multiplicity:\n    ".implode("\n    ", array_slice($missing, 0, 10));
} else {
    $notes[] = 'A1 multiset — every removed line arrived with its exact multiplicity';
}

// ── A2. Exact byte conservation, including the join seam. ────────────────────────────────────────

$before = strlen($trackerBefore) + strlen($archiveBefore);
$after = strlen($trackerAfter) + strlen($archiveAfter);
$residual = $after - $before;

$notes[] = sprintf('A2 bytes — before %s, after %s, residual %s, declared added %s',
    number_format($before), number_format($after), number_format($residual), number_format($addedBytes));

if ($residual !== $addedBytes) {
    $failures[] = sprintf(
        "A2 byte conservation — before + declared_added != after. before=%d declared_added=%d after=%d, ".
        "off by %d.\n    M41's first run failed here by exactly ONE byte against a correct tree: the ".
        "formula omitted the JOIN SEAM between the old archive tail and the first inserted line. If ".
        "this is off by a small number, suspect the seam before you suspect the surgery.",
        $before, $addedBytes, $after, $residual - $addedBytes
    );
} else {
    $notes[] = 'A2 byte conservation — exact, with no tolerance';
}

// ── A3. Paths touched, read from the WORKING TREE. ───────────────────────────────────────────────

if (isset($opts['paths'])) {
    $declared = array_values(array_filter(array_map('trim', explode(',', (string) $opts['paths']))));

    if ($declared === []) {
        surgery_refuse('--paths was given but parsed to nothing.');
    }

    // ⛔ WORKING TREE, NOT COMMITTED STATE. M45's paths assertion failed because it compared the index
    // while phase 2 of the surgery still sat unstaged.
    [$diff, $status] = surgery_exec('git diff --name-only HEAD');

    if ($status !== 0) {
        surgery_refuse('git diff --name-only HEAD failed; cannot compare paths touched');
    }

    $actual = array_values(array_filter(array_map('trim', surgery_lines($diff))));

    sort($declared);
    sort($actual);

    if ($declared !== $actual) {
        $failures[] = "A3 paths touched — the working tree does not match what was declared.\n".
            '    declared: '.implode(', ', $declared)."\n".
            '    actual:   '.implode(', ', $actual);
    } else {
        $notes[] = 'A3 paths touched — exactly '.count($actual).' path(s), as declared';
    }
} else {
    $notes[] = 'A3 paths touched — not declared, so not asserted (pass --paths to assert it)';
}

// ── A4. Independent slice hash. ──────────────────────────────────────────────────────────────────
//
// Shares no code with A1 by construction: A1 compares COUNTS of hashes, this compares the sha256 of
// one contiguous joined region against another. A wrong multiset implementation cannot make both agree.

// ⛔ RECOVERED BY COMMON PREFIX AND SUFFIX, NOT BY COUNTING. The first draft of this walked the before
// state and called a line "removed" once its running count exceeded its total in the after state. That
// is wrong on exactly the input this harness exists for: with duplicated lines it marks the LAST
// occurrences rather than the ones actually removed, so a slice of five lines came back with its blank
// lines in the wrong places and A4 failed against a CORRECT fixture. It was caught by the positive
// control, on the first run — which is the M41/M45 pattern precisely, the check wrong and the surgery
// right, and it is recorded here rather than quietly fixed.
$beforeCount = count($trackerBeforeLines);
$afterCount = count($trackerAfterLines);

$prefix = 0;
while ($prefix < $beforeCount && $prefix < $afterCount
    && $trackerBeforeLines[$prefix] === $trackerAfterLines[$prefix]) {
    $prefix++;
}

$suffix = 0;
while ($suffix < ($beforeCount - $prefix) && $suffix < ($afterCount - $prefix)
    && $trackerBeforeLines[$beforeCount - 1 - $suffix] === $trackerAfterLines[$afterCount - 1 - $suffix]) {
    $suffix++;
}

$removedInOrder = array_slice($trackerBeforeLines, $prefix, $beforeCount - $prefix - $suffix);

if ($removedInOrder === []) {
    surgery_refuse('A4 harvested no removed lines while A1 counted '.$removedTotal.
        ' — the two disagree, which is a defect in this harness and not in the surgery.');
}

$sliceHash = hash('sha256', implode("\n", $removedInOrder));
$notes[] = sprintf('A4 slice — %d line(s) between prefix %d and suffix %d, sha256 %s',
    count($removedInOrder), $prefix, $suffix, substr($sliceHash, 0, 16));

// ⚠️ THE TWO PROOFS MUST AGREE ON THE SIZE OF THE MOVE, and when they do not, the removal was not one
// contiguous region — the prefix/suffix middle then contains lines the tracker KEPT. Saying so beats
// letting the region comparison below fail with a message about re-wrapping.
if (count($removedInOrder) !== $removedTotal) {
    $failures[] = sprintf(
        'A4 slice — the removal is not ONE contiguous region: A1 counted %d removed line(s), the '.
        "prefix/suffix span holds %d. A surgery that takes two separate slices cannot be proved by a ".
        'single region hash, and should be performed and verified one slice at a time.',
        $removedTotal, count($removedInOrder)
    );
}

$archiveBody = $archiveAfter;
$needle = implode("\n", $removedInOrder);

if (! str_contains($archiveBody, $needle)) {
    $failures[] = "A4 slice hash — the removed lines do not appear in the archive as ONE contiguous, ".
        "byte-identical region.\n    They may have arrived reordered, re-wrapped, or in pieces. A1 can ".
        'pass while this fails, which is the entire reason both exist.';
} else {
    $notes[] = 'A4 slice hash — the removed region is present in the archive, contiguous and byte-identical';
}

// ── Report. ──────────────────────────────────────────────────────────────────────────────────────

fwrite(STDOUT, "tracker-surgery: base is {$baseLabel}\n");

foreach ($notes as $note) {
    fwrite(STDOUT, "tracker-surgery: {$note}\n");
}

if ($failures !== []) {
    fwrite(STDERR, "\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "tracker-surgery: FAILED — {$failure}\n");
    }
    fwrite(STDERR, "\ntracker-surgery: ".count($failures)." proof(s) failed. The surgery is NOT verified.\n");
    exit(SURGERY_EXIT_FAILED);
}

fwrite(STDOUT, sprintf(
    "tracker-surgery: VERIFIED — %d line(s) moved, %s bytes conserved exactly, slice contiguous.\n",
    $removedTotal,
    number_format($after)
));

exit(SURGERY_EXIT_OK);
