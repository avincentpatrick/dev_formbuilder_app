<?php

declare(strict_types=1);

/*
 * Citation-liveness gate (M46) — a `path:N` citation whose target line is DEAD.
 *
 * WHY THIS EXISTS. This corpus cites source by line number constantly, and nothing has ever re-read
 * one. M37's census found roughly thirty rotten citations across the backlog; M46 re-measured the
 * eight `major` documentation-truth rows and found the citations of SIX of them pointing at blank
 * lines, at a shell comment inside a fenced block, or at a range whose first three lines are about a
 * different subject. A citation into nothing is worse than no citation: it is an assertion of
 * evidence that a reader will not check twice, and it is how five of those eight rows stayed wrong
 * about themselves for weeks.
 *
 * ⛔ WHAT THIS GATE DOES NOT DO, STATED HERE RATHER THAN DISCOVERED LATER. It checks that a cited
 * line is ALIVE. It NEVER checks that the line says what the citing sentence claims. Measured
 * against the eight rows that motivated it, it fires on THREE — the `APP_PREVIOUS_KEYS` register
 * entry, the ACCESS-MATRIX verification step and the README block. It is blind to the other five,
 * whose citations land on live lines that say the wrong thing.
 *
 * ⛔ AND IT MUST NEVER BE SOLD AS COVERING THE CLASS IT CANNOT SEE. Two counter-examples from the
 * same increment, both worth keeping in view:
 *
 *   (a) `ConnectorChannelDirectory` carried a docblock asserting that grepping the application tree
 *       for cache-facade calls finds nothing. Three runtime cache writes existed, and one of them
 *       reaches the cache through an injected repository, so the prescribed grep could not have seen
 *       it. A gate over first-party code that reports absence is an engine for that error.
 *
 *   (b) ADR-0001 asserted that nothing lowercases email on the register/login path. The lowercasing
 *       lives in `vendor/`, gated by a config flag. A gate scoped to first-party code would have
 *       CONFIRMED the false claim.
 *
 * The honest split: **negatives about ARTIFACTS** (a file, a line, a constraint, an env var, a
 * dependency) are mechanisable. **Negatives about BEHAVIOUR** are not, and a gate that pretends
 * otherwise will confidently ratify the error.
 *
 * RULES
 *   R1  Every resolved citation in the tier-1 corpus points at a line that is not blank, not a
 *       horizontal rule, not a fence delimiter, not a table separator, not inside a fenced block of
 *       a Markdown target, and not past end-of-file.
 *   R2  The ledger tier (`docs/feature-backlog.md`) may not exceed a RATCHETED ceiling of rotten
 *       citations. It is not held at zero — see the ceiling's own note.
 *   R3  The scan must find documents, extract citations, and resolve them. Any of the three coming
 *       back below its floor FAILS, because this gate has three independent ways to pass while blind.
 *
 * ⚠️ FOUR TRAPS MEASURED WHILE BUILDING THIS, EACH OF WHICH PRODUCED A WRONG ANSWER FIRST:
 *
 *   1. LINES ARE SPLIT WITH explode(), NEVER preg_split ON `\R` OR A NEWLINE CLASS. Byte 0x85 is a
 *      newline to PCRE without `/u`, and it occurs INSIDE common UTF-8 emoji — `✅` is E2 9C 85.
 *      This corpus is saturated with ✅ / ⛔ / ⚠️, so the naive split silently shifts every line
 *      number after the first and the gate reports rot that is an artefact of its own reader.
 *   2. THE EXTENSION MUST START WITH A LETTER. Without that constraint the pattern matches WCAG
 *      contrast ratios — `4.5:1` parses as path `4.5`, line 1 — and the design-system reference
 *      alone contributes dozens. Measured before this file was written.
 *   3. THE PATTERN DELIMITER CANNOT BE `~`, because the fence predicate has to match `~~~`.
 *   4. A BARE BASENAME PREFERS THE REPOSITORY ROOT. `README.md` matches both the root file and
 *      `packages/design-system/README.md`; without root-preference the three dead `README.md`
 *      citations this gate was built to catch resolve to nothing and it reports a clean run.
 *
 * ⚠️ ANCHOR LINE ONLY. For a range `N-M` only line N is checked. Whole-range checking was
 * prototyped and rejected: a legitimate citation into a method spans blank lines, and the
 * false-positive rate would make the gate untrustworthy. The stated cost is that a range whose
 * later lines are a table header and separator does not fire. A false NEGATIVE is acceptable here;
 * a false POSITIVE is not.
 *
 * Usage: php scripts/citation-liveness-lint.php [--report]
 *        --report prints every tier including the excluded ones and ALWAYS exits 0. It is a
 *        measurement mode and is deliberately not wired into CI.
 */

/** Tier 1 — the specification corpus. Zero tolerance. */
const TIER1_ROOTS = ['docs'];
const TIER1_EXTRA_FILES = ['README.md'];

/**
 * Excluded, and the reason is the same one `state.php` prints about declarations in prose: a
 * citation inside a DATED record is correct AS HISTORY, and rewriting it falsifies the log.
 *
 * `docs/claims/**` are the lane ledgers. `docs/backlog-triage.md` is a point-in-time census whose
 * whole value is that it records what was true on the day it was measured — "correcting" its
 * citations would destroy the measurement rather than repair it. Both also sit in `ci.yml`'s
 * `paths-ignore`, which is the same judgement made one layer up.
 *
 * ➕ `docs/backlog-triage-m37.md` (`M65`) is the 2026-08-28 census, frozen byte-for-byte when
 * `docs/backlog-triage.md` became generated. ⛔ IT IS ADDED HERE ON A FORWARD ARGUMENT AND NOT TO
 * HIDE A FAILURE, and the distinction is worth the line: measured at the moment it was frozen, it
 * PASSED tier 1 unexcluded — 57 documents, 456 citations, 424 resolved, ledger unmoved at 18. The
 * reason to exclude it is that it can never be repaired. It is a dated record nobody may edit, so a
 * citation of its that rots later is a red gate with no legal fix, which is the same argument the
 * two above carry, reached before the rot rather than after it. ⚠️ It is NOT in `ci.yml`'s
 * `paths-ignore` — that file is the user's — so unlike the others a push touching it does run CI.
 * It is frozen, so it will not be touched again.
 */
const TIER1_EXCLUDE_PREFIXES = ['docs/claims/'];
const TIER1_EXCLUDE_FILES = ['docs/feature-backlog.md', 'docs/backlog-triage.md', 'docs/backlog-triage-m37.md'];

/** Tier 2 — the ledger. Ratcheted, never zero. */
const TIER2_FILES = ['docs/feature-backlog.md'];

/**
 * ⛔ RATCHET-DOWN OBLIGATION, AND IT BELONGS TO WHOEVER MOVES THE NUMBER.
 *
 * `docs/feature-backlog.md` is append-mostly and cites code that then moves, so holding it at zero
 * would make it red on arrival — which M40 established can never merge. The ceiling is therefore the
 * count measured at the merge that introduced this gate, with a STRICT `>`, so the file cannot get
 * worse. **Any increment that reduces the count must lower this constant in the same commit**, which
 * is the discipline `TRACKER_BYTE_CEILING` already carries and the reason it was ratcheted once.
 * Raising it requires saying why in the commit body.
 *
 * ⚠️ IT CANNOT RATCHET TO ZERO, AND THE REASON IS STRUCTURAL RATHER THAN LAZY. Part of this count
 * lives inside CLOSED rows whose preserved original filings are dead BY DESIGN: three of the rows
 * M46 closed were closed *because* their citations pointed at blank lines, and that dead citation is
 * the evidence for the closure. Re-pointing it would destroy the record. A refinement would exempt
 * struck-through rows, which needs a parser that can tell a closed row from an open one — more than
 * this gate should grow on its first outing, and filed as its own row instead.
 */
// ⚠️ 19 -> 18 by M48 (2026-08-29), which repaired the one dead citation its own subject had created:
// the ledger pointed at `PROGRESS.md:1436` in a file that has been spliced twice since and is now 306
// lines. Re-pointed by SECTION NAME rather than re-measured to a new line, which is what this gate's
// own failure message prescribes. ⛔ THE FIRST DRAFT OF THAT REPAIR NAMED THE WRONG SECTION — it
// invented a G11 entry the archive does not have, and only opening the archive caught it. A citation
// repaired by guess is the defect this gate exists to catch, committed by the increment holding it.
const LEDGER_ROT_CEILING = 18;

/** R3 floors. Three, because there are three independent ways for this gate to pass while blind. */
const MIN_EXPECTED_DOCUMENTS = 40;
const MIN_EXPECTED_CITATIONS = 140;
const MIN_EXPECTED_RESOLVED = 130;

/**
 * A per-line escape hatch, counted and printed so it can never be quietly over-used.
 *
 * It exists for ONE case: a sentence whose job is to document a dead citation. Closing a row by
 * writing "the line this used to cite is blank" reddens the gate on the sentence recording the
 * repair. The PREFERRED answer is a writing convention — name the defect without reproducing the
 * citation — and this marker is the fallback for when quoting is genuinely load-bearing.
 */
const ALLOW_MARKER = 'citation-liveness: allow';

/**
 * ⚠️ THE EXTENSION CLASS IS `[A-Za-z][A-Za-z0-9]{0,7}` AND THE LEADING LETTER IS LOAD-BEARING —
 * see trap 2 in the header. The delimiter is `#`, not `~` — see trap 3.
 */
const CITATION_PATTERN = '#(?<![\w./-])((?:[A-Za-z0-9_.\-]+/)*[A-Za-z0-9_.\-]+\.[A-Za-z][A-Za-z0-9]{0,7}):(\d+)(?:-(\d+))?(?![\w-])#';

$root = str_replace('\\', '/', dirname(__DIR__));
$reportMode = in_array('--report', array_slice($argv, 1), true);

$index = git_index($root);

if ($index === null) {
    fwrite(STDERR, "Citation liveness linter CANNOT MEASURE: `git ls-files` produced no output.\n");
    fwrite(STDERR, "  Citations are resolved against the git index, so an unreadable index is not a clean run.\n");
    exit(2);
}

$tier1 = collect_tier1($root);
$tier2 = existing_files($root, TIER2_FILES);
$excluded = collect_excluded($root);

$scan1 = scan($root, $tier1, $index);
$scan2 = scan($root, $tier2, $index);

$documents = count($tier1) + count($tier2);
$citations = $scan1['checked'] + $scan2['checked'];
$resolved = $scan1['resolved'] + $scan2['resolved'];
$unresolved = $scan1['unresolved'] + $scan2['unresolved'];
$allowed = $scan1['allowed'] + $scan2['allowed'];

if ($reportMode) {
    $scanX = scan($root, $excluded, $index);

    report('TIER 1 — specification corpus (zero tolerance)', $scan1, count($tier1));
    report('TIER 2 — ledger, ratcheted at '.LEDGER_ROT_CEILING, $scan2, count($tier2));
    report('EXCLUDED — dated records, correct as history, never gated', $scanX, count($excluded));

    printf(
        "\nSCOPE DECISION INPUT: tier-1 rotten = %d, tier-2 rotten = %d, excluded rotten = %d (not a defect).\n",
        count($scan1['rot']),
        count($scan2['rot']),
        count($scanX['rot'])
    );
    echo "--report always exits 0. It measures; it does not gate.\n";
    exit(0);
}

$failures = [];

// R3 first: a blind gate must not be allowed to report a clean run, and its message must not be
// confused with R1's, whose remedy is entirely different.
if ($documents < MIN_EXPECTED_DOCUMENTS) {
    $failures[] = sprintf(
        'DISCOVERY REGRESSION: scanned only %d document(s), expected at least %d. A scan root has moved or been renamed.',
        $documents,
        MIN_EXPECTED_DOCUMENTS
    );
}

if ($citations < MIN_EXPECTED_CITATIONS) {
    $failures[] = sprintf(
        'DISCOVERY REGRESSION: extracted only %d citation(s), expected at least %d. The extraction pattern is broken, or the corpus shed its line references wholesale.',
        $citations,
        MIN_EXPECTED_CITATIONS
    );
}

if ($resolved < MIN_EXPECTED_RESOLVED) {
    $failures[] = sprintf(
        'DISCOVERY REGRESSION: resolved only %d citation(s) against the git index, expected at least %d. The resolver is broken.',
        $resolved,
        MIN_EXPECTED_RESOLVED
    );
}

$discoveryFailed = $failures !== [];

if ($discoveryFailed) {
    fwrite(STDERR, "Citation liveness linter FAILED:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }

    // ⚠️ NO CITATION-REMEDY FOOTER HERE. `component-import-lint`'s own control caught it printing
    // "add an import" for a scan root that had moved; a gate whose failure message points at the
    // wrong fix costs more than the defect it caught.
    exit(1);
}

if ($scan1['rot'] !== []) {
    fwrite(STDERR, "Citation liveness linter FAILED — tier 1 (specification corpus) must carry no dead citations:\n");

    foreach ($scan1['rot'] as $rot) {
        fwrite(STDERR, '  - '.describe($rot)."\n");
    }

    fwrite(STDERR, "\nA `path:N` citation whose target line is blank, a rule, a fence, a table separator or past\n");
    fwrite(STDERR, "end-of-file is a pointer into nothing, and it reads as evidence. Re-measure where the cited\n");
    fwrite(STDERR, "thing actually moved to — or, preferably, DROP THE LINE NUMBER and cite the file plus a\n");
    fwrite(STDERR, "section, key or symbol name. Counts and filenames are stable; line numbers are not.\n");
    exit(1);
}

if (count($scan2['rot']) > LEDGER_ROT_CEILING) {
    fwrite(STDERR, sprintf(
        "Citation liveness linter FAILED — the LEDGER TIER carries %d dead citation(s), over its ceiling of %d:\n",
        count($scan2['rot']),
        LEDGER_ROT_CEILING
    ));

    foreach ($scan2['rot'] as $rot) {
        fwrite(STDERR, '  - '.describe($rot)."\n");
    }

    fwrite(STDERR, "\nThe ledger is ratcheted rather than held at zero, so this is not asking for a sweep. Either\n");
    fwrite(STDERR, "fix the citation you just added, or lower nothing and add none — the ceiling may only be\n");
    fwrite(STDERR, "LOWERED, in the same commit as whatever reduced the count.\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Citation liveness linter passed (%d document(s) scanned, %d citation(s) checked, %d resolved, %d unresolved; ledger tier %d rotten, ceiling %d; %d allow-marked).\n",
    $documents,
    $citations,
    $resolved,
    $unresolved,
    count($scan2['rot']),
    LEDGER_ROT_CEILING,
    $allowed
));
exit(0);

/**
 * Every tracked path, plus a basename map for bare-name citations.
 *
 * @return array{paths: array<string, true>, byBasename: array<string, list<string>>}|null
 */
function git_index(string $root): ?array
{
    $out = [];
    $status = 0;
    exec('git -C '.escapeshellarg($root).' ls-files 2>&1', $out, $status);

    if ($status !== 0 || $out === []) {
        return null;
    }

    $paths = [];
    $byBasename = [];

    foreach ($out as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $paths[$line] = true;
        $byBasename[basename($line)][] = $line;
    }

    return ['paths' => $paths, 'byBasename' => $byBasename];
}

/** @return list<string> */
function collect_tier1(string $root): array
{
    $files = [];

    foreach (TIER1_ROOTS as $relativeRoot) {
        $absolute = $root.'/'.$relativeRoot;

        if (! is_dir($absolute)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $relative = relative_path($file->getPathname(), $root);

            if (is_excluded($relative)) {
                continue;
            }

            $files[] = $relative;
        }
    }

    $files = array_merge($files, existing_files($root, TIER1_EXTRA_FILES));
    sort($files);

    return $files;
}

/** @return list<string> */
function collect_excluded(string $root): array
{
    $files = [];
    $absolute = $root.'/docs';

    if (is_dir($absolute)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $relative = relative_path($file->getPathname(), $root);

            if (is_excluded($relative) && ! in_array($relative, TIER2_FILES, true)) {
                $files[] = $relative;
            }
        }
    }

    sort($files);

    return $files;
}

function is_excluded(string $relative): bool
{
    foreach (TIER1_EXCLUDE_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    return in_array($relative, TIER1_EXCLUDE_FILES, true);
}

/**
 * @param  list<string>  $candidates
 * @return list<string>
 */
function existing_files(string $root, array $candidates): array
{
    $files = [];

    foreach ($candidates as $candidate) {
        if (is_file($root.'/'.$candidate)) {
            $files[] = $candidate;
        }
    }

    return $files;
}

/**
 * @param  list<string>  $files
 * @param  array{paths: array<string, true>, byBasename: array<string, list<string>>}  $index
 * @return array{checked: int, resolved: int, unresolved: int, allowed: int, rot: list<array<string, mixed>>, missing: list<string>}
 */
function scan(string $root, array $files, array $index): array
{
    $checked = 0;
    $resolved = 0;
    $unresolved = 0;
    $allowed = 0;
    $rot = [];
    $missing = [];
    $targetCache = [];

    foreach ($files as $relative) {
        $lines = read_lines($root.'/'.$relative);

        if ($lines === null) {
            continue;
        }

        foreach ($lines as $number => $line) {
            if (str_contains($line, ALLOW_MARKER)) {
                $allowed++;

                continue;
            }

            if (preg_match_all(CITATION_PATTERN, $line, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $token = $match[1];
                $anchor = (int) $match[2];

                if ($anchor < 1) {
                    continue;
                }

                $checked++;
                $target = resolve_token($token, $index);

                if ($target === null) {
                    $unresolved++;
                    $missing[] = $relative.' -> '.$token;

                    continue;
                }

                $resolved++;

                if (! array_key_exists($target, $targetCache)) {
                    $targetCache[$target] = read_lines($root.'/'.$target);
                }

                $targetLines = $targetCache[$target];

                if ($targetLines === null) {
                    continue;
                }

                $verdict = judge($targetLines, $anchor, $target);

                if ($verdict !== null) {
                    $rot[] = [
                        'source' => $relative,
                        'line' => $number + 1,
                        'token' => $token,
                        'target' => $target,
                        'anchor' => $anchor,
                        'kind' => $verdict,
                    ];
                }
            }
        }
    }

    return [
        'checked' => $checked,
        'resolved' => $resolved,
        'unresolved' => $unresolved,
        'allowed' => $allowed,
        'rot' => $rot,
        'missing' => $missing,
    ];
}

/**
 * ⛔ explode(), NEVER preg_split on a newline class — see trap 1 in the header.
 *
 * @return list<string>|null
 */
function read_lines(string $absolute): ?array
{
    $body = @file_get_contents($absolute);

    if ($body === false) {
        return null;
    }

    return explode("\n", str_replace("\r\n", "\n", $body));
}

/**
 * @param  array{paths: array<string, true>, byBasename: array<string, list<string>>}  $index
 */
function resolve_token(string $token, array $index): ?string
{
    if (isset($index['paths'][$token])) {
        return $token;
    }

    if (str_contains($token, '/')) {
        return null;
    }

    $candidates = $index['byBasename'][$token] ?? [];

    if ($candidates === []) {
        return null;
    }

    if (count($candidates) === 1) {
        return $candidates[0];
    }

    // Trap 4: a bare basename prefers the repository root.
    foreach ($candidates as $candidate) {
        if (! str_contains($candidate, '/')) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The anchor line's verdict, or null when it is alive.
 *
 * @param  list<string>  $lines
 */
function judge(array $lines, int $anchor, string $target): ?string
{
    $total = count($lines);

    // A file ending in a newline yields a trailing empty element; it is not a real line.
    if ($total > 0 && $lines[$total - 1] === '') {
        $total--;
    }

    if ($anchor > $total) {
        return 'OUT-OF-RANGE (file has '.$total.' lines)';
    }

    $line = $lines[$anchor - 1];
    $trimmed = trim($line);

    if ($trimmed === '') {
        return 'BLANK';
    }

    if (preg_match('#^(-{3,}|\*{3,}|_{3,})$#', $trimmed) === 1) {
        return 'HORIZONTAL RULE';
    }

    if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
        return 'FENCE DELIMITER';
    }

    if (preg_match('#^\|?[\s:|-]*-[\s:|-]*\|[\s:|-]*$#', $trimmed) === 1) {
        return 'TABLE SEPARATOR';
    }

    if (str_ends_with(strtolower($target), '.md') && in_fence($lines, $anchor)) {
        return 'INSIDE A FENCED BLOCK';
    }

    return null;
}

/**
 * Is the anchor inside a fenced block of a Markdown target?
 *
 * @param  list<string>  $lines
 */
function in_fence(array $lines, int $anchor): bool
{
    $open = false;

    for ($n = 0; $n < $anchor - 1; $n++) {
        $trimmed = ltrim($lines[$n]);

        if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
            $open = ! $open;
        }
    }

    return $open;
}

/** @param array<string, mixed> $rot */
function describe(array $rot): string
{
    return sprintf(
        '%s:%d cites `%s` — %s is %s',
        $rot['source'],
        $rot['line'],
        $rot['token'],
        $rot['target'].':'.$rot['anchor'],
        $rot['kind']
    );
}

/** @param array{checked: int, resolved: int, unresolved: int, allowed: int, rot: list<array<string, mixed>>, missing: list<string>} $scan */
function report(string $title, array $scan, int $documents): void
{
    printf(
        "\n=== %s ===\n  %d document(s), %d citation(s), %d resolved, %d unresolved, %d allow-marked, %d ROTTEN\n",
        $title,
        $documents,
        $scan['checked'],
        $scan['resolved'],
        $scan['unresolved'],
        $scan['allowed'],
        count($scan['rot'])
    );

    foreach ($scan['rot'] as $rot) {
        echo '    ROT  '.describe($rot)."\n";
    }

    foreach (array_slice($scan['missing'], 0, 12) as $miss) {
        echo '    ?    unresolved: '.$miss."\n";
    }

    if (count($scan['missing']) > 12) {
        printf("    ?    ... and %d more unresolved\n", count($scan['missing']) - 12);
    }
}

function relative_path(string $path, string $root): string
{
    return ltrim(str_replace($root, '', str_replace('\\', '/', $path)), '/');
}
