<?php

declare(strict_types=1);

/*
 * Mutation / positive-control harness (M36).
 *
 * WHY THIS EXISTS. This project gates merges on tests, and four separate increments have now shown
 * that a GREEN gate proves nothing about a gate you have just written. The only thing that does is a
 * deliberate defect that turns it red. Every clause below comes from a measured failure in this
 * repository; none is precautionary.
 *
 *   M31  A positive control returned GREEN because its mutation NEVER APPLIED — the shell ate the `$`
 *        inside a double-quoted perl expression, the substitution silently matched nothing, and the
 *        suite reported "64 passed". That is indistinguishable from "the guard is decorative and the
 *        control disproves it". It was caught only because the command happened to print the line.
 *        R2 and R3 below are that lesson: the token must occur EXACTLY ONCE, and the sha256 MUST MOVE.
 *
 *   M34  A "read-only" verification agent ran `php artisan test`, whose `migrate:fresh` DROPPED THE
 *        SCHEMA under a live mutation run, producing three phantom failures that read as real ones.
 *        The tell was the ASSERTION TOTAL moving (856 against a known 867), not the failure count.
 *        R1 is that lesson: refuse to run while any Pest or artisan-test process is alive.
 *
 *   M9   `git checkout --` is NOT a mutation restore. It reverts to HEAD, silently eating uncommitted
 *        work, and a text-mode restore rewrites line endings so the file "matches" while differing in
 *        bytes. R5 is that lesson: restore from a byte copy taken before the write, and verify the
 *        sha256 returns to its exact original value.
 *
 *   M34  A red set is SCOPE-BOUND. M34's looked like one test each until a suite outside the scope run
 *        was measured and also went red. The report names the scope it ran, and says so.
 *
 * AND THE RULE THAT SUBSUMES THE REST: a red proves nothing if you cannot show it was green. The
 * baseline runs FIRST, unmutated, and a non-green baseline aborts before anything is written.
 *
 * TOKENS ARE READ FROM FILES, NEVER FROM ARGV. That is not fastidiousness — it is the M31 defect's
 * root cause. A PHP token is full of dollar signs, backslashes and backticks, and every shell between
 * here and the substitution is entitled to eat them. A file round-trip has no shell in it.
 *
 * WHAT A RESULT MEANS
 *   CAUGHT    the mutant turned at least one test red that was green in the baseline. The gate works.
 *   SURVIVED  the mutant changed nothing. The tests cannot see this defect — which is the finding.
 *
 * Usage:
 *   php scripts/mutate.php --file=<path> --old=<file> --new=<file> --tests="<pest paths>" [--label=x]
 *                          [--container=<name>] [--skip-baseline]
 *
 * Runs on the HOST. Pest is executed inside the app container, because that is where this project's
 * suite runs; override with --container or MUTATE_CONTAINER for a second lane's stack.
 */

const EXIT_CAUGHT = 0;
const EXIT_ABORTED = 1;
const EXIT_SURVIVED = 2;

$root = dirname(__DIR__);
$opts = getopt('', ['file:', 'old:', 'new:', 'tests:', 'label::', 'container::', 'skip-baseline']);

foreach (['file', 'old', 'new', 'tests'] as $required) {
    if (! isset($opts[$required])) {
        abort("missing --{$required}. See the usage block at the top of this file.");
    }
}

$file = (string) $opts['file'];
$label = (string) ($opts['label'] ?? basename($file));
$tests = (string) $opts['tests'];
$container = (string) ($opts['container'] ?? (getenv('MUTATE_CONTAINER') ?: 'dev_formbuilder_app-app-1'));
$absolute = is_absolute_path($file) ? $file : $root.'/'.$file;

if (! is_file($absolute)) {
    abort("target does not exist: {$file}");
}

// R1. Never run beside a live suite. Standing Rule 7(c), and M34's phantom failures.
$probe = "ps -eo args | grep -E '[v]endor/bin/pest|[a]rtisan test' | wc -l";
$running = (int) trim(shell_out(sprintf(
    'docker exec %s sh -c %s',
    escapeshellarg($container),
    escapeshellarg($probe)
)));

if ($running !== 0) {
    abort("{$running} Pest/artisan-test process(es) already running in {$container}. ".
          'A concurrent migrate:fresh will drop the schema under this run (M34).');
}

// R2. The target must be clean, or the restore in R5 cannot be trusted.
if (trim(shell_out('git status --porcelain '.escapeshellarg($file))) !== '') {
    abort("{$file} has uncommitted changes. Commit or stash them — the restore compares bytes ".
          'against the file as it stands NOW, so a dirty target silently bakes in your edits.');
}

$old = read_token((string) $opts['old']);
$new = read_token((string) $opts['new']);
$original = (string) file_get_contents($absolute);
$shaBefore = hash('sha256', $original);

$occurrences = substr_count($original, $old);

if ($occurrences !== 1) {
    abort("the old token occurs {$occurrences} time(s) in {$file}; it must occur EXACTLY ONCE. ".
          'Widen the token with surrounding context until it is unique.');
}

// The baseline. A red proves nothing if you cannot show it was green.
$baseline = null;

if (isset($opts['skip-baseline'])) {
    report('--skip-baseline: this run CANNOT distinguish a mutation-caused failure from a '.
           'pre-existing one. Use it only when you have just measured the baseline yourself.');
} else {
    report("BASELINE — running {$tests} unmutated");
    $baseline = run_pest($container, $tests);
    report($baseline['summary']);

    if ($baseline['failed'] > 0) {
        abort("the baseline is NOT green ({$baseline['failed']} failed). Fix that first — a red ".
              'mutant run would be meaningless against a red baseline.');
    }
}

// R3. Write the mutant, and PROVE the write landed. This is the M31 clause.
file_put_contents($absolute, str_replace($old, $new, $original));
$shaAfter = hash('sha256', (string) file_get_contents($absolute));

if ($shaAfter === $shaBefore) {
    restore($absolute, $original, $shaBefore);
    abort('the sha256 DID NOT MOVE — the mutation never applied. This is the M31 failure exactly, '.
          'and it is why a control that does not prove its own write is worthless.');
}

report("sha256 {$shaBefore}");
report("    -> {$shaAfter}");
report('mutated line, printed so the control proves itself:');

$needle = trim_first_line($new);

foreach (explode("\n", (string) file_get_contents($absolute)) as $index => $line) {
    if ($needle !== '' && str_contains($line, $needle)) {
        report(sprintf('  %s:%d  %s', $file, $index + 1, trim($line)));
    }
}

// R4. The mutant must still parse, or "red" means "syntax error".
if (str_ends_with($file, '.php')) {
    $lint = shell_out('php -l '.escapeshellarg($absolute).' 2>&1', $lintStatus);

    if ($lintStatus !== 0) {
        restore($absolute, $original, $shaBefore);
        abort("the mutant does not parse, so a red run would prove nothing:\n".$lint);
    }
}

report("MUTANT — running {$tests}");
$mutant = run_pest($container, $tests);
report($mutant['summary']);

// R5. Restore by BYTE COMPARISON. Never `git checkout --` (M9).
restore($absolute, $original, $shaBefore);
report("restored; sha256 back to {$shaBefore}");

if (trim(shell_out('git status --porcelain '.escapeshellarg($file))) !== '') {
    abort("{$file} is STILL DIRTY after the restore. Do not trust this tree.");
}

// The verdict.
$caught = $baseline === null
    ? $mutant['failed'] > 0
    : $mutant['failed'] > $baseline['failed'];

report('');
report(str_repeat('-', 78));

if ($caught) {
    report("CAUGHT — {$label}");
    report('The mutant turned at least one previously-green test red. The gate sees this defect.');

    foreach ($mutant['failures'] as $failure) {
        report('  RED: '.$failure);
    }
} else {
    report("SURVIVED — {$label}");
    report('The mutant changed NOTHING. Every test stayed green with the defect in place, so the');
    report('suite cannot see it. That is the finding — file it rather than explaining it away.');
}

report('');
report("A red set is SCOPE-BOUND. This run covered only: {$tests}");
report('A suite you did not run is not a suite you may assume, in either direction (M34).');

exit($caught ? EXIT_CAUGHT : EXIT_SURVIVED);

/**
 * @return never
 */
function abort(string $message)
{
    fwrite(STDERR, "mutate: ABORT — {$message}\n");
    exit(EXIT_ABORTED);
}

function report(string $message): void
{
    fwrite(STDOUT, $message === '' ? "\n" : "mutate: {$message}\n");
}

function is_absolute_path(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1;
}

/** Tokens come from files so that no shell can eat a dollar sign, a backslash or a backtick (M31). */
function read_token(string $path): string
{
    if (! is_file($path)) {
        abort("token file does not exist: {$path}");
    }

    $token = rtrim((string) file_get_contents($path), "\r\n");

    if ($token === '') {
        abort("token file is empty: {$path}");
    }

    return $token;
}

function trim_first_line(string $token): string
{
    return trim(explode("\n", $token)[0]);
}

function restore(string $absolute, string $original, string $expectedSha): void
{
    file_put_contents($absolute, $original);
    $sha = hash('sha256', (string) file_get_contents($absolute));

    if ($sha !== $expectedSha) {
        fwrite(STDERR, "mutate: RESTORE FAILED — {$sha} != {$expectedSha}. The original bytes are lost ".
                       "from this process; recover the file from git before doing anything else.\n");
        exit(EXIT_ABORTED);
    }
}

/**
 * @return array{summary: string, failed: int, failures: list<string>, raw: string}
 */
function run_pest(string $container, string $tests): array
{
    $raw = shell_out(sprintf(
        'docker exec %s php -d memory_limit=2G vendor/bin/pest %s 2>&1',
        escapeshellarg($container),
        $tests
    ));

    // The exit code cannot be trusted — vendor/bin/pest has returned 0 alongside "Tests: 5 failed"
    // in this repository. Read the summary line, never the status.
    $failed = 0;
    $summary = 'Tests: (no summary line found — treat this run as UNMEASURED)';
    $failures = [];

    foreach (explode("\n", $raw) as $line) {
        $plain = trim((string) preg_replace('/\e\[[0-9;]*m/', '', $line));

        if (str_starts_with($plain, 'Tests:')) {
            $summary = $plain;

            if (preg_match('/(\d+)\s+failed/', $plain, $matches) === 1) {
                $failed = (int) $matches[1];
            }
        }

        if (str_starts_with($plain, 'FAILED ')) {
            $failures[] = substr($plain, 7);
        }
    }

    return ['summary' => $summary, 'failed' => $failed, 'failures' => $failures, 'raw' => $raw];
}

function shell_out(string $command, ?int &$status = null): string
{
    $output = [];
    exec($command, $output, $status);

    return implode("\n", $output);
}
