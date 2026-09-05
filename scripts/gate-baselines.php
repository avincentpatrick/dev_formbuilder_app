<?php

declare(strict_types=1);

/*
 * Gate-baseline generator (M36).
 *
 * WHY THIS EXISTS. "Quote gate numbers from a CI log, never from a hand-off" has been standing advice
 * in this project since M30, when TWO figures in one hand-off were found stale and low. Advice with no
 * artefact behind it decays exactly as fast as the numbers do: at the moment this script was written,
 * the live Lane A hand-off quoted Pest at 4595 / 19,433 while the most recent successful run on main
 * reported 4611 / 19465. One increment old, already wrong, and nothing in the loop could tell.
 *
 * So the numbers stop living in prose. This writes docs/gate-baselines.md from a REAL run, stamped
 * with the run id, the sha and the date, and hand-offs reference the file instead of restating it.
 * That is the same one-authority-referenced-rather-than-copied fix Standing Rule 7(b) already applied
 * to the lane boundary, against the same defect: two copies of a fact drifting apart with each reader
 * correctly believing their own.
 *
 * ⛔ A METRIC THIS SCRIPT CANNOT FIND IS WRITTEN AS "NOT FOUND", NEVER GUESSED AND NEVER CARRIED OVER
 * FROM THE PREVIOUS FILE. A stale number that looks measured is the whole defect; a visible gap is
 * merely a gap. If a job's output format changes, the row goes blank and somebody fixes the pattern.
 *
 * ⚠️ IT READS ONLY A SUCCESSFUL RUN BY DEFAULT, and it checks `steps: []` is not the reason a job
 * "passed" — an outage-killed job reports conclusion `cancelled` with zero steps, and this project has
 * merged on that reading before (I5, during a GitHub Actions major outage).
 *
 * Usage:
 *   php scripts/gate-baselines.php                 # latest successful CI run on main
 *   php scripts/gate-baselines.php --run=<id>      # a specific run — STILL VALIDATED, see below
 *
 * ⛔ M39. THE RUN IS VALIDATED WHEREVER IT CAME FROM. This file's own provenance once named a
 * `pull_request` run on a feature branch: the default path above was correct and `--run=` walked
 * around it. `--run=` is kept — the escape hatch was never the defect, the missing validation was,
 * and a check that only guards the default guards the case nobody gets wrong.
 *   php scripts/gate-baselines.php --dry-run       # print, do not write
 */

/**
 * How far behind `origin/main` a run's head sha may be and still be baselined from (M74).
 *
 * ⚠️ A COMMIT COUNT, NEVER A DURATION. See the guard below for the argument; in one line, the
 * nightly `schedule` run this script is required to accept is hours old by construction and zero
 * commits behind, so age would refuse the one run added as insurance against an outage.
 */
const MAX_COMMITS_BEHIND = 20;

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['run::', 'dry-run']);
$runId = isset($opts['run']) ? (string) $opts['run'] : null;

if ($runId === null) {
    fwrite(STDOUT, "gate-baselines: no --run given; finding the latest successful CI run on main...\n");
    $json = sh(gate_baselines_gh().' run list --branch main --workflow CI --status success --limit 1 --json databaseId,headSha,createdAt');
    $runs = json_decode($json, true);

    if (! is_array($runs) || $runs === []) {
        fwrite(STDERR, "gate-baselines: could not find a successful CI run on main.\n");
        exit(1);
    }

    $runId = (string) $runs[0]['databaseId'];
}

fwrite(STDOUT, "gate-baselines: reading run {$runId}...\n");

$meta = json_decode(sh(gate_baselines_gh().' run view '.escapeshellarg($runId).' --json databaseId,headSha,createdAt,conclusion,url,event,headBranch'), true);

if (! is_array($meta)) {
    fwrite(STDERR, "gate-baselines: could not read run {$runId}.\n");
    exit(1);
}

if (($meta['conclusion'] ?? '') !== 'success') {
    fwrite(STDERR, "gate-baselines: run {$runId} concluded '".($meta['conclusion'] ?: 'pending').
                   "', not 'success'. Baselines come from a green run only.\n");
    exit(1);
}

// ── M39. THE FILE WRITTEN TO END STALE NUMBERS WAS ITSELF STAMPED FROM A PR-BRANCH RUN.
//    docs/gate-baselines.md carried run 33132909007 as its provenance; that run's event is
//    `pull_request` on branch `m36-loop-verification-harness`. The DEFAULT path above was correct —
//    it asks for a successful run on main — and the `--run=` escape hatch walked straight around it.
//
//    ⛔ SO THE GUARD GOES ON THE SELECTED RUN, NOT ON THE PATH THAT SELECTED IT. `--run=` is KEPT:
//    the escape hatch was never the defect, the missing validation was. A check that only guards the
//    default is a check that guards the case nobody gets wrong.
//
//    ⚠️ IT REJECTS `pull_request`, NOT "anything that is not a push". `schedule` and
//    `workflow_dispatch` runs on main are REAL measurements of the trunk — the nightly cron exists as
//    deliberate insurance against an outage-skipped verification — and refusing them would break a
//    designed feature to fix a different one.
$event = (string) ($meta['event'] ?? '');
$headBranch = (string) ($meta['headBranch'] ?? '');

if ($headBranch !== 'main') {
    fwrite(STDERR, "gate-baselines: run {$runId} ran on branch '{$headBranch}', not 'main'. A baseline\n".
                   "                measures the trunk; a branch run measures a proposal. Re-run with no\n".
                   "                --run= to take the latest successful CI run on main.\n");
    exit(1);
}

if ($event === 'pull_request') {
    fwrite(STDERR, "gate-baselines: run {$runId} is a 'pull_request' run. It tests the MERGE of a branch\n".
                   "                with main rather than main itself, so its figures can include work that\n".
                   "                has not landed. Accepted events on main: push, schedule,\n".
                   "                workflow_dispatch.\n");
    exit(1);
}

// ── M74. THE M39 ARMS ABOVE ASK *WHAT KIND OF RUN IS THIS*. THIS ONE ASKS *IS IT THE RUN WE MEANT*.
//    Measured during M73's own close-out: the no---run branch took run 33184885256 — a real,
//    successful, `push` run on `main` from 2026-08-28 — while the intended run had already concluded
//    and appears first on every subsequent invocation of the identical command. The file was written,
//    reported success, and carried baselines from a tree 141 commits behind.
//
//    ⛔ EVERY M39 ARM PASSES THAT RUN. conclusion `success`, headBranch `main`, event `push`: all
//    three green. The guard checked what kind of run it was and never whether it was recent, and
//    `gh run list` documents NO ordering guarantee and offers no sort flag — so `[0]` is a
//    convention, not a contract. `--run=` discipline is NOT the remedy, because the default path is
//    what every close-out uses.
//
//    ⚠️ AND THE ONLY TELL WAS A LINE THAT READS LIKE A SCRAPER BUG. The stale run also reported
//    "1 metric(s) NOT FOUND", because a log that old predates a pattern this script now expects. On a
//    run merely a few days stale there would have been no signal at all.
$headSha = (string) ($meta['headSha'] ?? '');

if (preg_match('/^[0-9a-f]{40}$/', $headSha) !== 1) {
    fwrite(STDERR, "gate-baselines: run {$runId} reports no usable head sha, so its recency cannot be\n".
                   "                judged. Refusing rather than measuring an unknown tree.\n");
    exit(1);
}

// ⛔ THREE-WAY, NOT TWO. `--is-ancestor` answers 0 (yes) or 1 (no) and reserves everything else for
//    "could not decide" — a bad object, a missing origin/main, no git on PATH. Collapsing the third
//    into either verdict is the succeeds-on-empty-input family this repository has measured six
//    times, so it gets its own message and its own refusal.
//    ⚠️ Operand order is the REVERSE of scripts/preflight.php:123, which asks the opposite question
//    (is the trunk an ancestor of my branch). Do not "fix" one to match the other.
//    ⚠️ And this must NOT `git fetch`: a read that mutates local git state is a surprise, and the
//    operator can always fetch and re-run.
sh(gate_baselines_git().' merge-base --is-ancestor '.escapeshellarg($headSha).' origin/main', $ancestorStatus);

if ($ancestorStatus === 1) {
    fwrite(STDERR, "gate-baselines: run {$runId}'s head sha {$headSha} is not an ancestor of\n".
                   "                origin/main. It measures a tree that is not on the trunk.\n");
    exit(1);
}

if ($ancestorStatus !== 0) {
    fwrite(STDERR, "gate-baselines: git could not decide whether {$headSha} is on origin/main\n".
                   "                (exit {$ancestorStatus}). Fetch origin and re-run. Refusing rather\n".
                   "                than assuming, because a check that cannot measure is not a pass.\n");
    exit(1);
}

// ⛔ DISTANCE, NOT WALL-CLOCK AGE, AND THE CARVE-OUT IS THE WHOLE REASON. A nightly `schedule` run is
//    eight hours old by breakfast and is a PERFECT measurement of a quiet trunk; any sane age check
//    refuses it, and the block above says in terms that refusing those would break a designed feature
//    to fix a different one. Distance says zero behind and passes it — and refuses the same cron run
//    once the trunk has genuinely moved, which is the behaviour actually wanted.
//
//    ⚠️ THIS IS A RAW COUNT, INCLUDING `paths-ignore`d COMMITS, AND DELIBERATELY NOT state.php's
//    commits_behind_trunk(). That function is not reusable here on three separate grounds, each
//    measured rather than assumed: it computes DISTANCE ONLY AND NEVER ANCESTRY (a non-ancestor sha
//    yields a happy finite count, so it cannot supply the arm above); scripts/state.php executes and
//    exits at file scope, so it cannot be required; and shelling to it would be CIRCULAR, because its
//    derive_baselines() reads docs/gate-baselines.md to find the sha it measures and this script is
//    that file's writer. So the two git calls are inlined, and the open row's "the measurement exists
//    and is not wired to the writer" is half false.
//
//    The ceiling is generous by an order of magnitude against the 141 that motivated it, and sits
//    above the handful of close-out commits that legitimately land between a run and its stamp.
$behindRaw = trim(sh(gate_baselines_git().' rev-list --count '.escapeshellarg($headSha.'..origin/main'), $behindStatus));

if ($behindStatus !== 0 || preg_match('/^\d+$/', $behindRaw) !== 1) {
    fwrite(STDERR, "gate-baselines: could not count how far run {$runId} is behind origin/main\n".
                   "                (exit {$behindStatus}, output '{$behindRaw}'). Refusing.\n");
    exit(1);
}

if ((int) $behindRaw > MAX_COMMITS_BEHIND) {
    fwrite(STDERR, "gate-baselines: run {$runId} is {$behindRaw} commits behind origin/main (ceiling ".
                   MAX_COMMITS_BEHIND.").\n                Its figures measure a tree that has moved. Re-run once a newer CI run\n".
                   "                on main has concluded, or pass --run= for a run you have checked yourself.\n");
    exit(1);
}

// An outage-killed job reports `cancelled` with steps: [] — it never acquired a runner and proves
// nothing. A run whose jobs have real steps is the only kind worth baselining from (I5).
$jobs = json_decode(sh(gate_baselines_gh().' api '.escapeshellarg("repos/:owner/:repo/actions/runs/{$runId}/jobs").
                       ' --jq '.escapeshellarg('[.jobs[] | {name, conclusion, steps: (.steps|length)}]')), true);

$stepless = [];

foreach (is_array($jobs) ? $jobs : [] as $job) {
    if ((int) ($job['steps'] ?? 0) === 0) {
        $stepless[] = (string) $job['name'];
    }
}

if ($stepless !== []) {
    fwrite(STDERR, "gate-baselines: these jobs ran ZERO steps — no runner was acquired, so the run is\n".
                   '                not a measurement: '.implode(', ', $stepless)."\n");
    exit(1);
}

$log = sh(gate_baselines_gh().' run view '.escapeshellarg($runId).' --log');

if (trim($log) === '') {
    fwrite(STDERR, "gate-baselines: the log for run {$runId} is empty (it may have expired).\n");
    exit(1);
}

// Logs carry ANSI colour, and it arrives in TWO forms: a real ESC byte, or the literal two
// characters caret-bracket, depending on what sat between gh and here. Measured: the run this
// script was written against contained ZERO ESC bytes and the patterns silently matched nothing,
// which is a NOT FOUND row rather than a wrong number only because of how this file reports.
$plain = (string) preg_replace('/(?:\x1b|\^\[)\[[0-9;]*m/', '', $log);

/**
 * Each metric names the job it comes from, so a blank row says WHERE to look.
 *
 * @var array<string, array{job: string, pattern: string, format: callable(array<int, string>): string}>
 */
$metrics = [
    'Pest (PostgreSQL)' => [
        'job' => 'Tests (Pest on PostgreSQL)',
        'pattern' => '/Tests:.*?([\d,]+) passed \(([\d,]+) assertions\)/',
        'format' => static fn (array $m): string => "{$m[1]} passed, {$m[2]} assertions",
    ],
    'Vitest' => [
        'job' => 'Frontend build & type-check',
        'pattern' => '/Test Files\s+([\d,]+) passed \(([\d,]+)\)/',
        'format' => static fn (array $m): string => "{$m[1]} files",
    ],
    'Storybook axe' => [
        'job' => 'Design system a11y (axe)',
        'pattern' => '/Test Suites: ([\d,]+) passed.*?\n.*?Tests:\s+([\d,]+) passed/s',
        'format' => static fn (array $m): string => "{$m[1]} suites, {$m[2]} tests",
    ],
    'E2E (Playwright + axe)' => [
        'job' => 'E2E (Playwright + axe)',
        'pattern' => '/([\d,]+) passed \(([\d.]+m)\)/',
        'format' => static fn (array $m): string => "{$m[1]} passed in {$m[2]}",
    ],
    'PHPStan' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/(\[OK\] No errors)/',
        'format' => static fn (array $m): string => 'OK, no errors',
    ],
    'Pint' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/PASS\s+\.+\s+([\d,]+) files/',
        'format' => static fn (array $m): string => "{$m[1]} files (bare `pint --test`, whole project)",
    ],
    'controller-gate' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/Controller gate passed \((\d+) file/',
        'format' => static fn (array $m): string => "{$m[1]} controllers",
    ],
    'migration-lint' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/Migration linter passed \((\d+) migration/',
        'format' => static fn (array $m): string => "{$m[1]} migrations",
    ],
    'job-payload-lint' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/Job payload linter passed \((\d+) job/',
        'format' => static fn (array $m): string => "{$m[1]} jobs",
    ],
    'constraint-boundary-lint' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/Constraint boundary linter passed \((\d+) migration file\(s\) scanned, (\d+) constraint\(s\) checked, (\d+) constraint/',
        'format' => static fn (array $m): string => "{$m[1]} migrations, {$m[2]} constraints, {$m[3]} unresolved",
    ],
    'component-import-lint' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/Component-import linter passed \((\d+) SFC/',
        'format' => static fn (array $m): string => "{$m[1]} SFCs",
    ],
    'citation-liveness-lint' => [
        'job' => 'Static analysis, style & security',
        'pattern' => '/Citation liveness linter passed \((\d+) document\(s\) scanned, (\d+) citation\(s\) checked, (\d+) resolved, (\d+) unresolved; ledger tier (\d+) rotten, ceiling (\d+)/',
        'format' => static fn (array $m): string => "{$m[1]} docs, {$m[2]} citations, {$m[3]} resolved, {$m[4]} unresolved, ledger {$m[5]}/{$m[6]}",
    ],
];

$rows = [];
$missing = 0;

foreach ($metrics as $name => $spec) {
    if (preg_match($spec['pattern'], $plain, $matches) === 1) {
        $rows[] = sprintf('| %s | %s | %s |', $name, ($spec['format'])($matches), $spec['job']);
    } else {
        $missing++;
        $rows[] = sprintf('| %s | **NOT FOUND** — fix the pattern in `scripts/gate-baselines.php` | %s |', $name, $spec['job']);
    }
}

$skipped = preg_match('/(\d+) skipped/', $plain, $m) === 1 ? $m[1].' skipped' : 'no skip line';
$flaky = str_contains($plain, 'flaky') ? '⚠️ a flaky line IS present' : 'no flaky line';

$rowsJoined = implode(chr(10), $rows);

$document = <<<MARKDOWN
# Gate baselines

**Generated by `scripts/gate-baselines.php`. Do not hand-edit — regenerate it.**

**Measured from CI run [{$runId}]({$meta['url']}) · `{$event}` on `{$headBranch}` · sha `{$meta['headSha']}` · {$meta['createdAt']}**

This file exists because "quote gate numbers from a CI log, never from a hand-off" was standing advice
with no artefact behind it, and advice decays as fast as the numbers do. A hand-off restating these
figures is the defect, not a convenience — reference this file instead, exactly as Standing Rule 7(b)
requires of the lane boundary.

⛔ **A row reading NOT FOUND is a broken pattern, not a zero.** Nothing here is ever guessed or carried
forward from a previous generation; a visible gap is honest, a stale number that looks measured is the
whole problem this file was written to end.

| Gate | Baseline | CI job |
|---|---|---|
{$rowsJoined}

**E2E extras:** {$skipped} · {$flaky} — `failOnFlakyTests` makes a flaky result RED, so the absence of
that line is load-bearing rather than lucky.

## Reading these correctly

- **A LOCAL full-suite Pest number is not CI's** — the gap has been measured at 335 tests. Compare
  per-directory deltas instead, and only against a run of the same base.
- **PHPStan scans `app`, `database` and `routes` only**, so a test-only or `scripts/`-only diff cannot
  move it. Saying that beats quoting an unchanged number.
- **The lint gates must run on the HOST.** Inside the app container,
  `RecursiveDirectoryIterator` descends the Windows bind mount only partially: `controller-gate`
  reports 49 of 97 there and, before M36 gave it a floor, still printed "passed". CI agrees with the
  host, which is what makes the host authoritative.
- **A SKIPPED RUN IS NOT A RUN, AND IT IS NOT A PENDING ONE EITHER.** This repository now has three
  members of the vacuous-success family, and they fail in different directions. `steps: []` is a job
  that never acquired a runner and proves nothing (I5, during an Actions outage). A green run whose
  gate never executed proves nothing (Pint, before its probe). And from M39, **a push run filtered out
  by `paths-ignore` does not exist at all** — `gh run list` returns nothing for it, so anything
  deciding whether main is verified must read "no run" as *correctly skipped*, never as *pending*.
  The ignore set is `PROGRESS.md`, `PROGRESS_ARCHIVE.md`, `docs/claims/**`, `docs/gate-baselines.md`
  and `docs/backlog-triage.md` — measured against the last four close-out commits, every one of which
  touches those paths and nothing else.
- **Pint's CI invocation is a BARE `pint --test`** over the whole project. The command every hand-off
  has prescribed — `pint --test app tests database` — scans roughly 40 files fewer and misses
  `scripts/`, `config/`, `routes/` and `bootstrap/` entirely.

MARKDOWN;

if (isset($opts['dry-run'])) {
    fwrite(STDOUT, $document);
    exit($missing === 0 ? 0 : 1);
}

file_put_contents($root.'/docs/gate-baselines.md', $document);
fwrite(STDOUT, "gate-baselines: wrote docs/gate-baselines.md from run {$runId}.\n");

if ($missing > 0) {
    fwrite(STDERR, "gate-baselines: {$missing} metric(s) NOT FOUND — patterns need fixing.\n");
    exit(1);
}

exit(0);

/**
 * ⚠️ THE BY-REF `$status` IS NOT DECORATION — IT IS WHAT MAKES THE RECENCY GUARD WRITABLE AT ALL.
 * Until M74 this helper discarded the exit status, so every caller could see output and never
 * failure. `git merge-base --is-ancestor` answers ONLY through its exit status and prints nothing,
 * so an ancestry check against the old signature would have read an empty string and passed —
 * the succeeds-on-empty-input family this repository has now measured six times. The shape is
 * `scripts/mutate.php`'s `shell_out()`, copied rather than reinvented.
 */
function sh(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command.' 2>&1', $output, $status);

    return implode("\n", $output);
}

/**
 * The GitHub CLI, overridable by `GATE_BASELINES_GH`. Shape copied from `mutate.php`'s `docker_bin()`,
 * including its argument: the stub replaces the RUNTIME, never a guard. Every refusal below still runs
 * exactly as it does in anger.
 */
function gate_baselines_gh(): string
{
    $override = getenv('GATE_BASELINES_GH');

    return ($override === false || $override === '') ? 'gh' : $override;
}

/**
 * Git, overridable by `GATE_BASELINES_GIT`.
 *
 * ⛔ A `gh`-ONLY SEAM WOULD PROVE THE ENVIRONMENT RATHER THAN THE GUARD, and that is why this second
 * one exists. With a real `git` the "head sha is not on the trunk" arm cannot be driven at all: a
 * fabricated sha makes git exit 128 (`bad object`), which is a THIRD outcome and not "not an
 * ancestor" — so the case would pass through the arm it does not name. Worse, git is absent inside
 * the app container where Pest runs (see tests/Feature/Docs/MutateHarnessTest.php), so the arms would
 * measure the runner instead of the code.
 */
function gate_baselines_git(): string
{
    $override = getenv('GATE_BASELINES_GIT');

    return ($override === false || $override === '') ? 'git' : $override;
}
