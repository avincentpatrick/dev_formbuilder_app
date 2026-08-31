<?php

declare(strict_types=1);

/*
 * Session preflight (M36).
 *
 * WHY THIS EXISTS. Every check below is a trap this project has already paid for, written down in
 * prose, and then hit again anyway — because prose has to be re-read, re-interpreted, and remembered
 * at the right moment by whoever holds the tree. The hand-off that carries these warnings is now
 * roughly four thousand words per lane. A check does not have to be remembered.
 *
 * ⛔ THIS IS DELIBERATELY NOT A ci.yml STEP, AND NOT PART OF `composer run quality`.
 * Most of what it asserts is meaningless in CI: there are no worktrees, no concurrent local Pest, and
 * the claim has already merged by the time CI sees the branch. The comments in `ci.yml` warn that a
 * composer-only registration "would gate nothing" — that warning is about MERGE-BLOCKING gates, and
 * this is not one. It is an instrument you run at session open and again before you push. Do not
 * "fix" it by adding a CI step.
 *
 * ⛔ THE INCREMENT NUMBER IS NO LONGER PARSED HERE (M42). It comes from `php scripts/state.php --json`,
 * which derives it from the `## RELEASED` headings rather than from any literal in prose. The scrape
 * this file used to do read a FORECAST as a SPEND and climbed every time an increment documented the
 * problem — measured on the tree that fixed it, where the old block answered "next free is M43" because
 * this increment's own claim names its own number.
 *
 * Usage:
 *   php scripts/preflight.php [--lane=a] [--with-pint] [--with-gates] [--closeout]
 *
 * Runs on the HOST — which is itself one of the findings it reports. `--with-pint` and `--with-gates`
 * are opt-in because they are slow; everything else is nearly instant.
 */

// ⛔ ONE LANE SINCE M50 — docs/adr/0022-single-lane-development.md. Lane B's entry is gone and
//    `--lane=b` is now REFUSED rather than silently accepted, which is the point: a stale hand-off
//    or an old habit that still passes `--lane=b` gets an error instead of a plausible-looking run
//    against a worktree that no longer exists.
//
// ⚠️ docs/claims/lane-b.md STILL EXISTS AND IS STILL READ — by scripts/state.php, which derives the
//    increment number from the `## RELEASED` headings of BOTH claim files. It holds ten releases
//    recorded nowhere else. It is an ARCHIVE, not a lane; absence from this map is what makes that
//    distinction executable.
const LANES = [
    'a' => ['claim' => 'docs/claims/lane-a.md', 'container' => 'dev_formbuilder_app-app-1'],
];

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['lane::', 'with-pint', 'with-gates', 'closeout']);
$closeout = isset($opts['closeout']);
$lane = strtolower((string) ($opts['lane'] ?? 'a'));

if (! isset(LANES[$lane])) {
    fwrite(STDERR, "preflight: unknown lane '{$lane}'. There is one lane since M50 - use --lane=a.\n");
    exit(1);
}

$claimFile = LANES[$lane]['claim'];
$container = LANES[$lane]['container'];
$failures = [];

section('Context');
$branch = trim(sh('git rev-parse --abbrev-ref HEAD'));
info('lane', strtoupper($lane)." ({$claimFile}, {$container})");
info('branch', $branch);
info('worktrees', "\n".indent(sh('git worktree list')));

// ── The number is decided by BOTH sources, because it has been decided by the worktree three
//    increments running (M30 caught the worktree ahead of the claim file; M31 caught the file
//    catching up mid-session). One command closes a gap both files agree to leave open.
section('Claims and numbering');
sh('git fetch --quiet origin 2>&1');

foreach (LANES as $key => $config) {
    $status = first_line_matching(show_from_main($config['claim']), '/^## Status:/');
    info('lane-'.$key.' status', $status === null ? '(no ## Status heading found)' : trim($status));
}

// ⛔ THE NUMBER COMES FROM scripts/state.php AND IS NOT PARSED HERE. Until M42 this block scraped the
//    highest `M<n>` LITERAL out of both claim files and took the maximum — so a forecast, a quoted
//    hand-off fragment, or a sentence about that very defect all read as a SPEND. Measured: it answered
//    "next free is M42" when the truth was M40, and it climbed every time an increment documented the
//    problem. The mitigation was to forbid lane-a.md from naming a planned increment in prose, which
//    made the tool accidentally correct at a real cost to the writing. This is the fix instead: one
//    authority, referenced rather than copied, exactly as Rule 7(b) requires of the lane boundary.
$stateStatus = 0;
$stateJson = sh('php '.escapeshellarg('scripts/state.php').' --json --no-rows 2>&1', $stateStatus);
$state = json_decode($stateJson, true);

if ($stateStatus !== 0 || ! is_array($state)) {
    $failures[] = 'state.php could not measure';
    fail('scripts/state.php exited '.$stateStatus.' — the numbering cannot be derived.');
    note(trim(last_line($stateJson)));
} else {
    info('highest released', 'M'.$state['increment']['highest_released'].'  ->  next free is M'.$state['increment']['next_free']);
    note('Derived from the `## RELEASED` headings of both claim files, truncated at each file\'s');
    note('`## Template` heading — NEVER from a literal in prose. Run `php scripts/state.php` for the');
    note('ADR and migration namespaces, the open rows, and how stale the gate baselines are.');

    if ($state['increment']['sources_agree'] === true) {
        pass('merged pull-request titles agree independently (max M'.$state['increment']['remote_max'].')');
    } elseif ($state['increment']['sources_agree'] === false) {
        $failures[] = 'numbering sources disagree';
        fail('merged pull-request titles top out at M'.$state['increment']['remote_max'].' — the two sources disagree.');
        note('A mis-truncated scan fails SILENTLY, by returning a LOWER maximum, and a low maximum is a');
        note('number collision. Settle it before claiming anything.');
    } else {
        warn('no independent cross-check: '.(string) $state['increment']['remote_reason']);
    }
}

note('Still a FLOOR, not an allocation. Read the other lane\'s file in full and run `git worktree');
note('list` — a forward queue is a claim and does not live under the `## Status` heading.');

// ── Rule 7(a). A branch cut from local HEAD is cut from a stale base and inherits none of what the
//    other lane has since fixed. This is a property of two worktrees, not of any one branch.
section('Branch base');

if ($branch === 'main') {
    warn('on main — cut a branch before opening any file');
} else {
    $ancestor = 0;
    sh('git merge-base --is-ancestor origin/main HEAD', $ancestor);

    if ($ancestor === 0) {
        pass('origin/main is an ancestor of HEAD (branch is cut from, or merged with, current main)');
    } else {
        $failures[] = 'branch is NOT based on current origin/main';
        fail('branch is NOT based on current origin/main — Rule 7(a). Rebase or merge origin/main in.');
    }
}

// ── Rule 7(g). A claim is a PUSHED commit. M14 wrote a perfect one that nobody could see.
section('Claim published');

if ($branch === 'main') {
    warn('skipped — not on a branch');
} elseif (str_contains(show_from_main($claimFile), $branch)) {
    pass("{$claimFile} on origin/main names this branch");
} elseif ($closeout) {
    // ⛔ --closeout IS EXPLICIT AND IS NEVER INFERRED FROM THE BRANCH NAME. `m<n>-closeout` is a
    //    convention, and a check that relaxes itself whenever a branch is named a certain way is one
    //    anyone can switch off by renaming a branch. The operator asserts the state; this records it.
    //
    //    On a close-out the claim has ALREADY merged, so Rule 7(g) has no true answer here: the branch
    //    a close-out runs on is one the claim could not have named, because it did not exist when the
    //    claim was written. M52 found this by having `loop gates` go red on its own close-out.
    warn("--closeout: {$claimFile} on origin/main does not name '{$branch}', which is EXPECTED.");
    note('A close-out runs on a branch the claim could not have named. The claim it releases has');
    note('already merged, so Rule 7(g) is satisfied by history rather than by this branch.');
    note('⚠️ If you did NOT mean this to be a close-out, you have just waived the claim check —');
    note('re-run without --closeout and write the claim first.');
} else {
    $failures[] = 'claim not published';
    fail("{$claimFile} on origin/main does NOT name '{$branch}'. An unpushed claim does not exist (M14).");
    note('Write the claim, then: git pull --rebase && git push origin HEAD:main — BEFORE the first file.');
    note('If this is a close-out, say so explicitly: --closeout.');
}

// ── Rule 7(c) / M34. A concurrent migrate:fresh drops the schema mid-run and the failures read as
//    product bugs. The tell is the ASSERTION TOTAL moving, not the failure count.
section('Concurrent suites');
$probe = "ps -eo args | grep -E '[v]endor/bin/pest|[a]rtisan test' | wc -l";
$running = trim(sh(sprintf('docker exec %s sh -c %s 2>&1', escapeshellarg($container), escapeshellarg($probe))));

if ($running === '0') {
    // wc -l, NOT grep -c: grep -c exits 1 on zero matches and reads as a command failure.
    pass("no Pest or artisan-test process in {$container}");
} elseif (is_numeric($running)) {
    $failures[] = 'concurrent suite running';
    fail("{$running} Pest/artisan-test process(es) running in {$container} — do not start another (M34).");
} else {
    warn("could not probe {$container}: ".$running);
}

// ── M52. The pre-push guard is OPT-IN PER CLONE and cannot be otherwise: core.hooksPath is local
//    git configuration, and a repository may not enable its own hooks by design. So the only honest
//    mitigation is to make its ABSENCE loud at session open, rather than discovering it at the moment
//    of the push it would have refused.
section('Pre-push guard');
$hooksPath = trim(sh('git config --get core.hooksPath'));

if ($hooksPath === '') {
    warn('core.hooksPath is unset — the pre-push guard is NOT installed.');
    note('Install it with: git config core.hooksPath .githooks');
    note('It refuses a push whose claim is not on the trunk, and a HEAD:main carrying more than one');
    note('commit — the two rules M14 and M48 each broke with a real push. Not blocking: it guards');
    note('mistakes, not intent, and --no-verify bypasses it on purpose.');
} elseif ($hooksPath !== '.githooks') {
    warn("core.hooksPath is '{$hooksPath}', not '.githooks' — the guard in this repository is not active.");
} elseif (! is_file('.githooks/pre-push')) {
    warn('core.hooksPath is .githooks but .githooks/pre-push is missing.');
} else {
    pass('pre-push guard installed (core.hooksPath = .githooks)');
}

// ── M31. A splice that fuses two lines because the inserted file had no trailing newline swallowed
//    the other lane's bullet whole, and reported a delta of 0 where +1 was asserted.
section('PROGRESS.md structural');
$progress = (string) file_get_contents($root.'/PROGRESS.md');
$marker = 'LANE '.strtoupper($lane).' NEXT PROMPT';
$atLineStart = preg_match_all('/^\*\*'.preg_quote($marker, '/').'/m', $progress);

info('total lines', (string) substr_count($progress, "\n"));

if ($atLineStart === 1) {
    pass('hand-off marker for lane '.strtoupper($lane).' appears exactly once at line start');
} else {
    $failures[] = 'hand-off marker count';
    fail("hand-off marker appears {$atLineStart} time(s) at line start — expected exactly 1.");
    note('The file contains a VERBATIM EXAMPLE of the marker, so a naive match finds the wrong one.');
    note('A marker check tells you what you found; only a line-count delta tells you what you destroyed.');
}

if (str_ends_with($progress, "\n")) {
    pass('PROGRESS.md ends with a newline');
} else {
    $failures[] = 'PROGRESS.md trailing newline';
    fail('PROGRESS.md does NOT end with a newline — a splice will fuse two lines (M31).');
}

// ── The baselines file, and the gate counts it records.
section('Gate baselines');
$baselinePath = $root.'/docs/gate-baselines.md';

if (! is_file($baselinePath)) {
    warn('docs/gate-baselines.md is missing — run: php scripts/gate-baselines.php --run=<ci-run-id>');
} else {
    $baselines = (string) file_get_contents($baselinePath);
    $stamp = first_line_matching($baselines, '/^\*\*Measured from/');
    info('provenance', $stamp === null ? '(no provenance line)' : trim(strip_tags($stamp)));

    $onDisk = count_test_files();
    info('.test.ts files on disk', (string) $onDisk);

    if (preg_match('/Vitest[^|]*\|\s*(\d+)\s*files/i', $baselines, $m) === 1) {
        if ((int) $m[1] === $onDisk) {
            pass("Vitest file count matches the baseline ({$onDisk})");
        } else {
            warn("Vitest files on disk ({$onDisk}) != baseline ({$m[1]}). A run reporting fewer than");
            note('the disk count silently skipped files — check the FILE COUNT, not just pass/fail.');
        }
    }
}

// ── The six lint gates. Opt-in because they are slow, and reported HOST-first on purpose.
if (isset($opts['with-gates'])) {
    section('Lint gates (host)');

    foreach (['controller-gate', 'migration-lint', 'job-payload-lint', 'constraint-boundary-lint', 'component-import-lint', 'citation-liveness-lint'] as $gate) {
        $status = 0;
        $out = sh('php '.escapeshellarg('scripts/'.$gate.'.php').' 2>&1', $status);
        $status === 0 ? pass(trim(last_line($out))) : fail(trim($out));

        if ($status !== 0) {
            $failures[] = $gate;
        }
    }

    note('Run these on the HOST. Inside the app container RecursiveDirectoryIterator descends the');
    note('Windows bind mount only partially — controller-gate reports 49 of 97 there and still passes');
    note('without a floor. That is why four of them gained one in M36.');
}

// ── Pint, proved rather than trusted.
if (isset($opts['with-pint'])) {
    section('Pint (proved scanned)');
    $probeFile = $root.'/app/PreflightPintProbe.php';
    file_put_contents($probeFile, "<?php\nnamespace App;\nclass   PreflightPintProbe {\npublic function x(  ) { return   1 ; }\n}\n");

    $probeStatus = 0;
    $probeOut = sh(sprintf('docker exec %s vendor/bin/pint --test 2>&1', escapeshellarg($container)), $probeStatus);
    unlink($probeFile);

    if ($probeStatus !== 0 && str_contains($probeOut, 'PreflightPintProbe')) {
        pass('Pint FAILED on a deliberate probe and named it — the scan is real');
    } else {
        $failures[] = 'pint positive control';
        fail('Pint did NOT fail on a deliberately misformatted probe. A bare `passed` is not evidence.');
    }

    $realStatus = 0;
    $realOut = sh(sprintf('docker exec %s vendor/bin/pint --test 2>&1', escapeshellarg($container)), $realStatus);
    $realStatus === 0 ? pass(trim(last_line($realOut))) : fail(trim($realOut));

    if ($realStatus !== 0) {
        $failures[] = 'pint';
    }

    note('CI runs a BARE `pint --test` (whole project, ~1415 files). The command every hand-off has');
    note('prescribed — `pint --test app tests database` — scans only ~1375 and misses scripts/,');
    note('config/, routes/ and bootstrap/ entirely.');
}

section('Result');

if ($failures === []) {
    fwrite(STDOUT, "preflight: OK — nothing blocking.\n");
    exit(0);
}

fwrite(STDERR, 'preflight: '.count($failures).' BLOCKING problem(s): '.implode(', ', $failures)."\n");
exit(1);

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────

function section(string $title): void
{
    fwrite(STDOUT, "\n== {$title} ".str_repeat('=', max(0, 74 - strlen($title)))."\n");
}

function pass(string $message): void
{
    fwrite(STDOUT, "  [ok]    {$message}\n");
}

function fail(string $message): void
{
    fwrite(STDOUT, "  [FAIL]  {$message}\n");
}

function warn(string $message): void
{
    fwrite(STDOUT, "  [warn]  {$message}\n");
}

function info(string $key, string $value): void
{
    fwrite(STDOUT, sprintf("  %-38s %s\n", $key, $value));
}

function note(string $message): void
{
    fwrite(STDOUT, "          {$message}\n");
}

function indent(string $text): string
{
    return implode("\n", array_map(static fn (string $l): string => '          '.$l, explode("\n", trim($text))));
}

function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $status);

    return implode("\n", $output);
}

function show_from_main(string $path): string
{
    return sh('git show '.escapeshellarg('origin/main:'.$path).' 2>&1');
}

function first_line_matching(string $haystack, string $pattern): ?string
{
    foreach (explode("\n", $haystack) as $line) {
        if (preg_match($pattern, $line) === 1) {
            return $line;
        }
    }

    return null;
}

function last_line(string $text): string
{
    $lines = array_values(array_filter(explode("\n", trim($text)), static fn (string $l): bool => trim($l) !== ''));

    return $lines === [] ? '' : (string) end($lines);
}

function count_test_files(): int
{
    $count = 0;

    foreach (['resources', 'packages'] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/node_modules/')) {
                continue;
            }

            if (str_ends_with($path, '.test.ts')) {
                $count++;
            }
        }
    }

    return $count;
}
