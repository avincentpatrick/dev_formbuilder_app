<?php

declare(strict_types=1);

/*
 * Does this pull request change only BHW Connect (M214, `bhw-ci-fast-path`, ADR-0024, D210)?
 *
 * WHY THIS EXISTS.
 *
 * Meridian's six CI jobs took about 30 minutes on a pull request that changes nothing of Meridian's, and
 * BHW Connect's owner merges on green. This answers one question for CI's `scope` job. When the answer is
 * yes, Meridian's jobs skip themselves, the secret scan still runs, and BHW's own job does the rest.
 *
 * THE RULE.
 *
 *   bhw_only=true only on a pull request whose diff is not empty, with every changed path under
 *   `apps/bhw-connect/` and none under `apps/bhw-connect/ops/`. Everything else is bhw_only=false.
 *
 *   ⛔ `ops/` IS NOT BHW-ONLY. It is a shared path in `.github/CODEOWNERS`, the deploy runs it, and
 *   `tests/Feature/Deploy/DeployWorkflowTest.php` reads `ops/write-settings.ps1`, so a change there can turn
 *   Meridian's Pest red. The row named only the secret scan as a carve-out.
 *
 *   ⛔ ONLY THE LITERAL ANSWER SKIPS, SO EVERY DOUBT RUNS EVERYTHING. A job skipped by its condition reports
 *   success on a required check, and so does a job whose `needs:` failed. So the jobs test for this exact
 *   `true` under `!cancelled()`: if this script cannot answer, it exits 2, its job fails, the output is empty,
 *   and all of them run. Measured on a throwaway repository (M214's claim): a failed detection job ran the
 *   required job rather than passing it.
 *
 *   Paths are `git diff --name-only --no-renames`, so a rename out of Meridian into `apps/` shows both of its
 *   paths and is not BHW-only. git quotes a path with unusual bytes, and a quoted path matches no prefix here,
 *   which is also the safe direction.
 *
 * USAGE (CI):
 *   php scripts/ci-scope.php --event=<github.event_name> [--base=<sha> --head=<sha>]
 *
 * Prints one `bhw_only=<true|false>` line, for `$GITHUB_OUTPUT`, and explains itself on stderr.
 *
 * EXIT CODES: 0 answered · 2 cannot measure, which is never a skip.
 */

const BHW_PREFIX = 'apps/bhw-connect/';
const SHARED_PREFIX = 'apps/bhw-connect/ops/';

$options = getopt('', ['event:', 'base:', 'head:']);
$event = is_string($options['event'] ?? null) ? $options['event'] : '';

if ($event === '') {
    cannot_measure('--event is required.');
}

if ($event !== 'pull_request') {
    answer(false, "the event is {$event}, not a pull request: this always runs in full.");
}

$base = is_string($options['base'] ?? null) ? $options['base'] : '';
$head = is_string($options['head'] ?? null) ? $options['head'] : '';

if ($base === '' || $head === '') {
    cannot_measure('--base and --head are required on a pull request.');
}

$mergeBase = git_lines(['merge-base', $base, $head])[0] ?? '';

if ($mergeBase === '') {
    cannot_measure("git merge-base {$base} {$head} printed nothing.");
}

$changed = git_lines(['diff', '--name-only', '--no-renames', $mergeBase, $head]);

if ($changed === []) {
    answer(false, 'the diff is empty, and nothing is never a reason to skip.');
}

foreach ($changed as $path) {
    if (! str_starts_with($path, BHW_PREFIX)) {
        answer(false, "{$path} is outside ".BHW_PREFIX.'.');
    }

    if (str_starts_with($path, SHARED_PREFIX)) {
        answer(false, "{$path} is shared, and a Meridian test reads that folder.");
    }
}

answer(true, sprintf("all %d changed path(s) are BHW Connect's own: Meridian's jobs skip themselves, and the secret scan runs.", count($changed)));

// ---------------------------------------------------------------------------------------------

function answer(bool $bhwOnly, string $why): never
{
    fwrite(STDERR, "ci-scope: {$why}\n");
    fwrite(STDOUT, 'bhw_only='.($bhwOnly ? 'true' : 'false')."\n");
    exit(0);
}

/** @return list<string> */
function git_lines(array $arguments): array
{
    $output = [];
    $status = 0;
    exec('git '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $status);

    if ($status !== 0) {
        cannot_measure('git '.implode(' ', $arguments)." failed:\n".implode("\n", $output));
    }

    return array_values(array_filter(array_map('trim', $output), static fn (string $l): bool => $l !== ''));
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "ci-scope: CANNOT MEASURE — {$why}\n");
    exit(2);
}
