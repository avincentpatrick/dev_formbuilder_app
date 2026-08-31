<?php

declare(strict_types=1);

/*
 * Positive-control harness for tracker-lint's R7 (M49).
 *
 * WHY THIS EXISTS, AND WHY IT IS COMMITTED RATHER THAN RUN ONCE BY HAND. R7 is the only gate this
 * repository has against the deletion of 2026-08-16 (f565ac9), which removed 1,086 lines of
 * PROGRESS.md and merged green. It has been wrong twice, and both times the wrongness was invisible:
 *
 *   M48  ci.yml's fetch-depth: 2 left only the PR's LAST commit in the clone, so a [tracker-surgery]
 *        marker on any earlier commit was ungrepable. R7 reported the marker ABSENT while measuring
 *        the delta perfectly. Eight increments passed without anyone noticing, because the rule had
 *        never once fired.
 *
 *   M49  R7 measured HEAD~1 against HEAD on a `push` too. M48's own four-commit push carried a
 *        198,909-byte removal of the constitution and the run reported a delta of ZERO. The largest
 *        removal since the incident this gate exists for crossed the merge gate unmeasured, green.
 *
 * ⛔ M47 BUILT CONTROLS FOR THIS GATE IN A DETACHED WORKTREE AND THREW THEM AWAY, AND THAT IS THE
 * REASON THE fetch-depth DEFECT SURVIVED. A control that is not committed is a control that ran once.
 * This file is the same idea made repeatable, and it is registered as its own CI step for the reason
 * every linter here is: no CI job runs `composer run quality`, so a composer-only registration gates
 * nothing.
 *
 * ⛔ scripts/mutate.php CANNOT DRIVE THIS, AND THE --command= REFINEMENT FILED FOR IT WOULD NOT
 * EITHER. R7's input is the COMMIT GRAPH — blob sizes at a base ref and a declaration read out of
 * `git log --format=%B`. No amount of file mutation reaches it. What it needs is a HISTORY fixture,
 * which is what this builds.
 *
 * HOW IT WORKS. It writes throwaway git repositories, copies the SHIPPED BYTES of
 * scripts/tracker-lint.php into each one, and runs it there. tracker-lint.php does
 * chdir(dirname(__DIR__)), so a copy at <fixture>/scripts/ makes the fixture its repository root and
 * no --root= surface has to be added to the gate under test. The tracker files are SYNTHETIC and not
 * copies of the real ones: a harness keyed to the live PROGRESS.md would change its own arithmetic
 * every time an increment edited the tracker, and would go red on the very surgery it exists to
 * protect.
 *
 * ⛔ THE TWO CASES THAT MATTER MOST ARE THE ONES THAT DEMONSTRATE THE OLD BEHAVIOUR. C2 and C4 run
 * the SAME fixture with the pre-M49 base and assert that the gate sees nothing. M19's lesson — a
 * probe measuring zero proves nothing unless it touched the defect — means a harness that only shows
 * the new code passing has shown nothing at all.
 *
 * ⚠️ NO CARET APPEARS IN ANY COMMAND HERE. PHP's exec() runs through cmd.exe on Windows, where the
 * caret is the escape character; that is how R7 once compared PROGRESS.md against itself and reported
 * +0 forever. HEAD~1 and `git cat-file -t`, never HEAD-caret and never rev-parse with a brace suffix.
 *
 * ⚠️ COMMIT MESSAGES ARE WRITTEN TO FILES AND PASSED WITH -F, NEVER WITH -m. That is mutate.php's
 * discipline for the same reason: the marker is full of brackets and every shell between here and git
 * is entitled to eat them.
 *
 * Usage:
 *   php scripts/tracker-lint-controls.php            # all cases, summary only
 *   php scripts/tracker-lint-controls.php --verbose  # plus the gate's own output for each case
 *   php scripts/tracker-lint-controls.php --keep     # leave the fixtures on disk for inspection
 *
 * Exit 0 = every case landed on its expected verdict. Exit 1 = at least one did not.
 * Exit 2 = the harness could not build a fixture, which is never reported as a pass.
 */

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['verbose', 'keep', 'dir::']);
$verbose = isset($opts['verbose']);
$keep = isset($opts['keep']);

const GATE = 'scripts/tracker-lint.php';

// The fixture's own thresholds are the gate's, so these are sized against the constants in GATE
// rather than restated: the big deletion must clear DROP_BYTE_LIMIT (50,000) and DROP_LIMIT (200),
// the small one must clear neither, and the whole file must sit under TRACKER_BYTE_CEILING.
const FILLER_LINES = 900;
const FILLER_KEEP_AFTER_BIG_CUT = 100;   // removes 800 lines / ~80,000 bytes — over both limits
const FILLER_KEEP_AFTER_SMALL_CUT = 890; //  removes  10 lines /  ~1,000 bytes — under both

// A 40-hex string that is deliberately not an object in any repository this builds.
const ABSENT_SHA = '0123456789abcdef0123456789abcdef01234567';
const ZERO_SHA = '0000000000000000000000000000000000000000';

$scratch = $opts['dir'] ?? (sys_get_temp_dir().DIRECTORY_SEPARATOR.'tracker-lint-controls-'.getmypid());

if (! is_file(GATE)) {
    fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — ".GATE." is missing.\n");
    exit(2);
}

$gateBytes = file_get_contents(GATE);

if ($gateBytes === false || strlen($gateBytes) < 1000) {
    fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — ".GATE." is unreadable or implausibly short.\n");
    exit(2);
}

fwrite(STDOUT, sprintf("tracker-lint-controls: gate under test is %s (%s bytes, sha256 %s)\n",
    GATE, number_format(strlen($gateBytes)), substr(hash('sha256', $gateBytes), 0, 16)));
fwrite(STDOUT, "tracker-lint-controls: fixtures in {$scratch}\n\n");

rrmdir($scratch);

if (! @mkdir($scratch, 0777, true) && ! is_dir($scratch)) {
    fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — could not create {$scratch}.\n");
    exit(2);
}

// ── The fixtures. ────────────────────────────────────────────────────────────────────────────────
//
// Four repositories, because a case that shares history with another can only be read by tracing
// which commit each one is standing on. Each is built once and read by several cases.

$pushDeclared = build_push_fixture($scratch.'/push-declared', true, FILLER_KEEP_AFTER_BIG_CUT);
$pushUndeclared = build_push_fixture($scratch.'/push-undeclared', false, FILLER_KEEP_AFTER_BIG_CUT);
$pushOrdinary = build_push_fixture($scratch.'/push-ordinary', false, FILLER_KEEP_AFTER_SMALL_CUT);
$pr = build_pr_fixture($scratch.'/pull-request');

// ── The cases. ───────────────────────────────────────────────────────────────────────────────────
//
// `env` is the COMPLETE set of overrides: a key mapped to null is UNSET for that run, which is how
// C5, C10 and the two local-fallback cases are expressed. Every case asserts an exit code AND a
// string in the output, never an exit code alone — M48's failure was a gate that measured the delta
// perfectly and reported the marker absent, and an exit-code-only control would have called that
// green in one direction and red in the other without saying which.

$cases = [
    [
        'id' => 'C1',
        'what' => 'push of 3 commits, big deletion in the FIRST, marker on it, base from the event',
        'repo' => $pushDeclared,
        'env' => ['GITHUB_EVENT_NAME' => 'push', 'TRACKER_LINT_BASE_SHA' => $pushDeclared['base']],
        'expect_exit' => 0,
        'expect_out' => 'DECLARED SURGERY',
    ],
    [
        'id' => 'C2',
        'what' => 'THE DEFECT: the same fixture with the pre-M49 base (HEAD~1) sees a delta of zero',
        'repo' => $pushDeclared,
        'env' => ['GITHUB_EVENT_NAME' => null, 'TRACKER_LINT_BASE_SHA' => null],
        'expect_exit' => 0,
        'expect_out' => 'delta is +0 line(s) and +0 byte(s)',
    ],
    [
        'id' => 'C3',
        'what' => 'push of 3 commits, big deletion in the FIRST, NO marker anywhere, base from the event',
        'repo' => $pushUndeclared,
        'env' => ['GITHUB_EVENT_NAME' => 'push', 'TRACKER_LINT_BASE_SHA' => $pushUndeclared['base']],
        'expect_exit' => 1,
        'expect_out' => 'R7 delta',
    ],
    [
        'id' => 'C4',
        'what' => 'THE DEFECT: the same undeclared removal is GREEN against HEAD~1',
        'repo' => $pushUndeclared,
        'env' => ['GITHUB_EVENT_NAME' => null, 'TRACKER_LINT_BASE_SHA' => null],
        'expect_exit' => 0,
        'expect_out' => 'delta is +0 line(s) and +0 byte(s)',
    ],
    [
        'id' => 'C5',
        'what' => 'push event with TRACKER_LINT_BASE_SHA unset — CI wiring dropped',
        'repo' => $pushDeclared,
        'env' => ['GITHUB_EVENT_NAME' => 'push', 'TRACKER_LINT_BASE_SHA' => null],
        'expect_exit' => 2,
        'expect_out' => 'CANNOT MEASURE R7 — GITHUB_EVENT_NAME is "push" but TRACKER_LINT_BASE_SHA is empty or unset',
    ],
    [
        'id' => 'C6',
        'what' => 'push event whose before-sha is the 40 zeros of a branch creation',
        'repo' => $pushDeclared,
        'env' => ['GITHUB_EVENT_NAME' => 'push', 'TRACKER_LINT_BASE_SHA' => ZERO_SHA],
        'expect_exit' => 2,
        'expect_out' => 'CANNOT MEASURE R7 — the push event carries the all-zero before-sha',
    ],
    [
        'id' => 'C7',
        'what' => 'push event whose before-sha is not an object in the clone (shallow, or force-pushed away)',
        'repo' => $pushDeclared,
        'env' => ['GITHUB_EVENT_NAME' => 'push', 'TRACKER_LINT_BASE_SHA' => ABSENT_SHA],
        'expect_exit' => 2,
        'expect_out' => 'is not a commit in this clone',
    ],
    [
        'id' => 'C8',
        'what' => 'pull_request merge commit, marker on the FIRST of three PR commits, clone complete',
        'repo' => $pr,
        'env' => ['GITHUB_EVENT_NAME' => 'pull_request', 'TRACKER_LINT_PR_COMMITS' => '3'],
        'expect_exit' => 0,
        'expect_out' => 'DECLARED SURGERY',
    ],
    [
        'id' => 'C9',
        'what' => 'pull_request whose range holds fewer commits than the PR has — the grafted clone',
        'repo' => $pr,
        'env' => ['GITHUB_EVENT_NAME' => 'pull_request', 'TRACKER_LINT_PR_COMMITS' => '9'],
        'expect_exit' => 2,
        'expect_out' => 'THE CLONE IS TOO SHALLOW FOR THIS RULE',
    ],
    [
        'id' => 'C10',
        'what' => 'pull_request event with TRACKER_LINT_PR_COMMITS unset — CI wiring dropped',
        'repo' => $pr,
        'env' => ['GITHUB_EVENT_NAME' => 'pull_request', 'TRACKER_LINT_PR_COMMITS' => null],
        'expect_exit' => 2,
        'expect_out' => 'CANNOT MEASURE R7 — GITHUB_EVENT_NAME is "pull_request" but TRACKER_LINT_PR_COMMITS is empty',
    ],
    [
        'id' => 'C11',
        'what' => 'push of 3 commits carrying an ORDINARY edit — the threshold is a threshold, not a tripwire',
        'repo' => $pushOrdinary,
        'env' => ['GITHUB_EVENT_NAME' => 'push', 'TRACKER_LINT_BASE_SHA' => $pushOrdinary['base']],
        'expect_exit' => 0,
        'expect_out' => 'under both limits',
    ],
];

$failed = [];

foreach ($cases as $case) {
    [$exit, $out] = run_gate($case['repo']['dir'], $case['env']);

    $exitOk = $exit === $case['expect_exit'];
    $outOk = str_contains($out, $case['expect_out']);
    $ok = $exitOk && $outOk;

    if (! $ok) {
        $failed[] = $case['id'];
    }

    fwrite(STDOUT, sprintf("%s  %s  %s\n", $ok ? '[ok]  ' : '[FAIL]', $case['id'], $case['what']));
    fwrite(STDOUT, sprintf("        expected exit %d and %s\n",
        $case['expect_exit'], json_encode($case['expect_out'])));
    fwrite(STDOUT, sprintf("        observed exit %d%s, expected text %s\n",
        $exit, $exitOk ? '' : '  <-- WRONG', $outOk ? 'present' : 'ABSENT  <-- WRONG'));

    if ($verbose || ! $ok) {
        foreach (explode("\n", trim($out)) as $line) {
            fwrite(STDOUT, "        | {$line}\n");
        }
    }

    fwrite(STDOUT, "\n");
}

if (! $keep) {
    rrmdir($scratch);
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("tracker-lint-controls: FAILED — %d of %d case(s) landed elsewhere: %s\n",
        count($failed), count($cases), implode(', ', $failed)));
    exit(1);
}

fwrite(STDOUT, sprintf("tracker-lint-controls: passed (%d cases; the push arm, the pull_request arm, "
    ."three cannot-measure paths and two demonstrations of the pre-M49 defect).\n", count($cases)));
exit(0);

// ── Fixture construction. ────────────────────────────────────────────────────────────────────────

/**
 * A linear history: base -> A (the tracker edit) -> B -> C.
 *
 * The edit is in the FIRST commit of the three deliberately. That is the whole shape under test:
 * `base..HEAD` contains it and `HEAD~1..HEAD` does not.
 *
 * @return array{dir: string, base: string}
 */
function build_push_fixture(string $dir, bool $declared, int $keepFiller): array
{
    git_init($dir);

    write_fixture_files($dir, FILLER_LINES);
    $base = git_commit($dir, "fixture: the tracker before the edit\n");

    write_fixture_files($dir, $keepFiller);
    $message = "fixture: the tracker edit, which is NOT the last commit of this push\n";

    if ($declared) {
        // At the start of a line, which is the only form R7 accepts.
        $message .= "\n[tracker-surgery] declared by the commit that performs it\n";
    }

    git_commit($dir, $message);

    // Two ordinary commits after it, touching anything BUT the tracker — this is what a close-out
    // looks like, and it is what pushed the real surgery out of R7's one-commit window.
    file_put_contents($dir.'/notes.txt', "an ordinary follow-up\n");
    git_commit($dir, "fixture: an ordinary commit after the edit\n");

    file_put_contents($dir.'/notes.txt', "an ordinary follow-up, and another\n");
    git_commit($dir, "fixture: a second ordinary commit after the edit\n");

    return ['dir' => $dir, 'base' => $base];
}

/**
 * A base branch, a three-commit topic branch whose FIRST commit carries the edit and the marker, and
 * a real merge commit whose first parent is the base tip — which is the shape actions/checkout hands
 * a workflow on a pull_request event.
 *
 * @return array{dir: string, base: string}
 */
function build_pr_fixture(string $dir): array
{
    git_init($dir);

    write_fixture_files($dir, FILLER_LINES);
    $base = git_commit($dir, "fixture: the base branch\n");

    git_run($dir, 'checkout -q -b topic');

    write_fixture_files($dir, FILLER_KEEP_AFTER_BIG_CUT);
    git_commit($dir, "fixture: the tracker edit, first of three PR commits\n"
        ."\n[tracker-surgery] declared on the phase-one commit, exactly as CLAUDE.md prescribes\n");

    file_put_contents($dir.'/notes.txt', "second PR commit\n");
    git_commit($dir, "fixture: the second PR commit\n");

    file_put_contents($dir.'/notes.txt', "third PR commit\n");
    git_commit($dir, "fixture: the third PR commit\n");

    // --no-ff so the merge commit exists even though the branch is a fast-forward; first parent is
    // the base tip, which is what makes HEAD~1 the correct measurement base on this event.
    git_run($dir, 'checkout -q main');
    git_run($dir, 'merge --no-ff --no-edit -q topic');

    return ['dir' => $dir, 'base' => $base];
}

/**
 * Synthetic tracker files that satisfy R1 through R6 and R8, so every case fails or passes on R7
 * alone. Deliberately NOT copies of the repository's own — see the header.
 */
function write_fixture_files(string $dir, int $fillerLines): void
{
    $filler = '';

    for ($i = 1; $i <= $fillerLines; $i++) {
        $filler .= sprintf("- filler %04d %s\n", $i, str_repeat('x', 90));
    }

    $tracker = "# Tracker fixture\n\n"
        ."## Standing Rules\n\n"
        ."One rule, so the heading exists exactly once.\n\n"
        ."## Current Status\n\n"
        ."**LANE A NEXT PROMPT** — fixture, so R6 finds one at line start.\n\n"
        ."**LANE B NEXT PROMPT** — fixture, so R6 finds one at line start.\n\n"
        ."## Next Session\n\n"
        .$filler;

    $archive = "# Archive fixture\n\n"
        ."## History\n\n"
        ."No Current Status heading here, and no Next Session heading either.\n";

    // R8 scans this for namespace literals, so it may contain no migration prefix, no increment
    // number, no sub-decision id and no next-free declaration. Kept dull on purpose.
    $imperatives = "# Imperatives fixture\n\n"
        ."This file exists so the eighth rule group has something with a floor to measure.\n"
        .str_repeat("It points at the deriving script and states no numbers of its own.\n", 60);

    file_put_contents($dir.'/PROGRESS.md', $tracker);
    file_put_contents($dir.'/PROGRESS_ARCHIVE.md', $archive);
    file_put_contents($dir.'/CLAUDE.md', $imperatives);
}

// ── git and process plumbing. ────────────────────────────────────────────────────────────────────

function git_init(string $dir): void
{
    if (! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
        fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — could not create {$dir}.\n");
        exit(2);
    }

    // -c init.defaultBranch rather than `init -b`, which older git does not accept.
    git_run($dir, '-c init.defaultBranch=main init -q');

    mkdir($dir.'/scripts', 0777, true);

    // The SHIPPED bytes, copied per run. Testing a copy of the current working tree is the point:
    // the harness must measure the gate as it stands, not a transcription of it.
    if (! copy(GATE, $dir.'/scripts/tracker-lint.php')) {
        fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — could not copy ".GATE." into {$dir}.\n");
        exit(2);
    }
}

/** Commits everything in the fixture and returns the new sha. */
function git_commit(string $dir, string $message): string
{
    // -F and never -m: the marker is full of brackets, and mutate.php's rule is that a token which
    // has to survive a shell does not survive a shell.
    //
    // OUTSIDE the repository, because `git add -A` below would otherwise commit the message file
    // itself and the next commit would record its deletion — harmless noise in the diff, and exactly
    // the sort of thing that makes a fixture hard to read when a case lands somewhere unexpected.
    $messageFile = dirname($dir).DIRECTORY_SEPARATOR.'.commit-message-'.basename($dir);
    file_put_contents($messageFile, $message);

    git_run($dir, 'add -A');
    git_run($dir, 'commit -q --no-verify -F '.escapeshellarg($messageFile));
    unlink($messageFile);

    $out = git_run($dir, 'rev-parse HEAD');

    return trim($out[0] ?? '');
}

/**
 * @return string[]
 */
function git_run(string $dir, string $args): array
{
    // Identity is supplied per invocation so the harness needs no global git config, which a CI
    // runner does not have. No caret appears in any $args this file passes.
    //
    // ⛔ core.autocrlf AND core.eol ARE PINNED, AND THEY WERE FOUND THE HARD WAY. This host's global
    // git config turns LF into CRLF on checkout, so the pull_request fixture — the only one that
    // checks a branch out and merges — came back with CR bytes in every line. R5 is a line-ending
    // rule and duly went red, and R2's line-anchored headings went red behind it: three failures in
    // two rule groups, none of them R7, in the cases built to exercise R7. A harness that inherits
    // the host's git config is measuring the host.
    $cmd = 'git -C '.escapeshellarg($dir)
        .' -c user.name=tracker-lint-controls -c user.email=controls@example.invalid'
        .' -c core.autocrlf=false -c core.eol=lf '
        .$args.' 2>&1';

    $out = [];
    $status = 0;
    exec($cmd, $out, $status);

    if ($status !== 0) {
        fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — git failed: {$args}\n");
        fwrite(STDERR, '              '.implode("\n              ", $out)."\n");
        exit(2);
    }

    return $out;
}

/**
 * Runs the copied gate inside a fixture with an exact environment.
 *
 * proc_open with an explicit env array rather than putenv, because a case has to be able to UNSET a
 * variable — C5 and C10 are entirely about the variable being absent — and putenv cannot express
 * that to a child on every platform.
 *
 * @param  array<string, string|null>  $overrides
 * @return array{0: int, 1: string}
 */
function run_gate(string $dir, array $overrides): array
{
    $env = getenv();

    // Anything the gate keys on must be cleared first, or a variable set in the ambient shell (a
    // real Actions runner sets GITHUB_EVENT_NAME) leaks into a case that means to be a local run.
    foreach (['GITHUB_EVENT_NAME', 'TRACKER_LINT_BASE_SHA', 'TRACKER_LINT_PR_COMMITS'] as $key) {
        unset($env[$key]);
    }

    foreach ($overrides as $key => $value) {
        if ($value !== null) {
            $env[$key] = $value;
        }
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];

    $process = proc_open(
        [PHP_BINARY, $dir.'/scripts/tracker-lint.php', '--verbose'],
        $descriptors,
        $pipes,
        $dir,
        $env
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "tracker-lint-controls: CANNOT RUN — could not start the gate in {$dir}.\n");
        exit(2);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    // Both streams, because R7 reports a pass on stdout and every failure and cannot-measure on
    // stderr, and a control that reads only one of them can see half a verdict.
    return [$exit, (string) $stdout.(string) $stderr];
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            // git marks objects read-only, and Windows refuses to unlink those without this.
            @chmod($item->getPathname(), 0666);
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
