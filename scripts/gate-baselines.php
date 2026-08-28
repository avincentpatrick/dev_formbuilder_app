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
 *   php scripts/gate-baselines.php --run=<id>      # a specific run
 *   php scripts/gate-baselines.php --dry-run       # print, do not write
 */

$root = dirname(__DIR__);
chdir($root);

$opts = getopt('', ['run::', 'dry-run']);
$runId = isset($opts['run']) ? (string) $opts['run'] : null;

if ($runId === null) {
    fwrite(STDOUT, "gate-baselines: no --run given; finding the latest successful CI run on main...\n");
    $json = sh('gh run list --branch main --workflow CI --status success --limit 1 --json databaseId,headSha,createdAt');
    $runs = json_decode($json, true);

    if (! is_array($runs) || $runs === []) {
        fwrite(STDERR, "gate-baselines: could not find a successful CI run on main.\n");
        exit(1);
    }

    $runId = (string) $runs[0]['databaseId'];
}

fwrite(STDOUT, "gate-baselines: reading run {$runId}...\n");

$meta = json_decode(sh('gh run view '.escapeshellarg($runId).' --json databaseId,headSha,createdAt,conclusion,url'), true);

if (! is_array($meta)) {
    fwrite(STDERR, "gate-baselines: could not read run {$runId}.\n");
    exit(1);
}

if (($meta['conclusion'] ?? '') !== 'success') {
    fwrite(STDERR, "gate-baselines: run {$runId} concluded '".($meta['conclusion'] ?: 'pending').
                   "', not 'success'. Baselines come from a green run only.\n");
    exit(1);
}

// An outage-killed job reports `cancelled` with steps: [] — it never acquired a runner and proves
// nothing. A run whose jobs have real steps is the only kind worth baselining from (I5).
$jobs = json_decode(sh('gh api '.escapeshellarg("repos/:owner/:repo/actions/runs/{$runId}/jobs").
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

$log = sh('gh run view '.escapeshellarg($runId).' --log');

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

**Measured from CI run [{$runId}]({$meta['url']}) · sha `{$meta['headSha']}` · {$meta['createdAt']}**

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

function sh(string $command): string
{
    $output = [];
    exec($command.' 2>&1', $output);

    return implode("\n", $output);
}
