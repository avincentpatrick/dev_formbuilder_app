<?php

declare(strict_types=1);

/*
 * Positive-control harness for pre-push-guard's PROTOCOL_PATHS classifier (M104).
 *
 * WHY THIS EXISTS. `scripts/pre-push-guard.php` had NO coverage of any kind — no Pest file, no host
 * control, nothing. M104 widened its documentation exemption by one path (`docs/pipeline.md`), and
 * `docs/feature-backlog.md`'s row R-c24216c5 asked for exactly this before the change landed: "the
 * path is one line, but the list is the guard's whole definition of documentation-only, so the
 * change wants a control proving a pipeline-only push is admitted while a code push still is not."
 *
 * ⛔ THE HAZARD IS NOT THE ADDED PATH, IT IS A WIDENED EXEMPTION THAT QUIETLY STOPS REFUSING. An
 * exemption matching EVERYTHING would make C1 pass and make the guard furniture. That is why C2 and
 * C3 are here and why they are not edge cases: C1 alone cannot distinguish "pipeline.md is now
 * exempt" from "nothing is checked any more".
 *
 * ⛔ WHY THIS IS A HOST SCRIPT AND NOT A tests/Feature/Docs/ PEST FILE. This guard's input is the
 * COMMIT GRAPH — it derives its path set from `git diff --name-only base..sha` and reads the claim
 * with `git show origin/main:<path>` — and **git is not installed in the app container**, measured
 * on this host: `command -v git` returns nothing. A Pest control would answer CANNOT MEASURE
 * locally while passing in CI, which is the SuiteCollectionFloorTest state this repository has an
 * open row about. `scripts/citation-liveness-lint-controls.php` and `tracker-lint-controls.php`
 * refused that trade for the same reason; this is the third instance and the same answer.
 *
 * ⛔ THE COST IS STATED RATHER THAN HIDDEN: `scripts/mutate.php` CANNOT DRIVE THIS. It runs Pest in
 * a container and nothing else. These controls are proved the way the other two were — by running
 * them against a DELIBERATELY REVERTED guard and comparing the verdicts by hand. C1 is the
 * discriminator: against the pre-M104 guard it exits 1. Recorded in the increment's release.
 *
 * HOW IT WORKS. It writes one throwaway git repository, copies the SHIPPED BYTES of the guard into
 * <fixture>/scripts/, and runs it there. The guard takes its root from dirname(__DIR__) of its own
 * location, so the copy makes the fixture the repository root and no --root= surface has to be
 * added to the guard under test. That is citation-liveness-lint-controls' mechanism exactly.
 *
 * ⚠️ THE FIXTURE IS SYNTHETIC. A harness keyed to the live tree would change its own arithmetic
 * every time an increment edited a document, and would go red on ordinary work rather than a defect.
 *
 * Exit 0 = every case landed where it should. Exit 1 = a case landed elsewhere. Exit 2 = the harness
 * could not measure, which is never a pass.
 */

const GATE = 'scripts/pre-push-guard.php';

// Deliberately not substrings of anything the fixture claim file contains. The guard's rule A is a
// str_contains() over the whole claim, so a short or common branch name would pass vacuously —
// a known weakness of that test, and NOT what these controls are measuring.
const UNCLAIMED = 'zzz-control-unclaimed-9f3a7d';
const CLAIMED = 'zzz-control-claimed-5c1b04';

$root = str_replace('\\', '/', dirname(__DIR__));
$gateBytes = @file_get_contents($root.'/'.GATE);

if ($gateBytes === false || trim((string) $gateBytes) === '') {
    cannot_measure(GATE.' is missing or empty, so there is nothing to control.');
}

$fixture = str_replace('\\', '/', sys_get_temp_dir()).'/m104-pre-push-guard-'.getmypid();

if (is_dir($fixture)) {
    rrmdir($fixture);
}

build_fixture($fixture, (string) $gateBytes);

$failures = [];
$ran = 0;

/*
 * C1 — THE DISCRIMINATOR. Recording a decision touches docs/claims/decisions.md and MUST regenerate
 * docs/pipeline.md in the same push. The branch is not named in the claim, which is the ordinary
 * close-out shape. Against the pre-M104 guard this exits 1.
 */
check($failures, $ran, 'C1 pipeline+decisions admitted',
    fn () => push_of($fixture, UNCLAIMED, ['docs/claims/decisions.md', 'docs/pipeline.md']),
    0, 'rule A skipped');

/*
 * C2 — THE ARM THAT MUST NOT HAVE BEEN DISARMED. Product code, no claim naming the branch. If the
 * exemption ever widens to everything, this is the case that notices.
 */
check($failures, $ran, 'C2 unclaimed code still refused',
    fn () => push_of($fixture, UNCLAIMED, ['app/Thing.php']),
    1, 'REFUSED');

/*
 * C3 — ONE UNLISTED PATH IS ENOUGH. every_path_is_documentation() is an ALL, not an ANY, and a
 * close-out that also touches code is work.
 */
check($failures, $ran, 'C3 mixed push still refused',
    fn () => push_of($fixture, UNCLAIMED, ['docs/pipeline.md', 'app/Thing.php']),
    1, 'REFUSED');

/*
 * C4 — THE EXEMPTION IS NOT A BYPASS OF RULE B. M48's branch carried the surgery AND its
 * documentation; documentation-only does not buy extra commits on the trunk.
 */
check($failures, $ran, 'C4 two doc commits to trunk refused',
    fn () => push_of($fixture, UNCLAIMED, ['docs/pipeline.md'], 'refs/heads/main', 2),
    1, 'pushing 2 commits');

/*
 * C5 — A CLAIM THAT NAMES THE BRANCH ADMITS CODE. Without this, C2 and C3 could both be passing
 * because rule A refuses everything rather than because it refuses the right things.
 */
check($failures, $ran, 'C5 claimed code admitted',
    fn () => push_of($fixture, CLAIMED, ['app/Thing.php']),
    0, 'rule A ok');

/*
 * C6 — THE SUPERSET ASSERTION FAILS CLOSED. If ci.yml grows a paths-ignore entry the guard does not
 * know about, the guard must answer CANNOT MEASURE rather than guess.
 */
check($failures, $ran, 'C6 unknown paths-ignore cannot measure',
    function () use ($fixture) {
        $workflow = $fixture.'/.github/workflows/ci.yml';
        $saved = (string) file_get_contents($workflow);
        file_put_contents($workflow, $saved."      - 'docs/not-in-protocol-paths.md'\n");
        $result = push_of($fixture, UNCLAIMED, ['docs/pipeline.md']);
        file_put_contents($workflow, $saved);

        return $result;
    },
    2, 'no longer a superset');

rrmdir($fixture);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'pre-push-guard-controls: '.$failure."\n");
    }

    fwrite(STDERR, sprintf("pre-push-guard-controls: FAILED — %d of %d case(s) landed elsewhere.\n",
        count($failures), $ran));
    exit(1);
}

fwrite(STDOUT, sprintf(
    'pre-push-guard-controls: passed (%d cases over a synthetic repository; C1 is the discriminator '
    ."and exits 1 against the pre-M104 guard).\n", $ran));
exit(0);

// ────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Run one case and record where it landed. Both the exit code AND a distinguishing phrase are
 * asserted, because three of these cases share exit 1 for three different reasons.
 */
function check(array &$failures, int &$ran, string $name, callable $run, int $wantExit, string $wantText): void
{
    $ran++;
    [$exit, $output] = $run();

    if ($exit !== $wantExit) {
        $failures[] = sprintf('%s — expected exit %d, got %d. Output: %s',
            $name, $wantExit, $exit, trim($output));

        return;
    }

    if (! str_contains($output, $wantText)) {
        $failures[] = sprintf('%s — exit %d was right but the output never said %s. Output: %s',
            $name, $exit, var_export($wantText, true), trim($output));
    }
}

/**
 * Commit the given paths on a fresh branch off main and drive the guard over that range.
 *
 * @param  list<string>  $paths
 * @return array{0:int,1:string}
 */
function push_of(
    string $fixture,
    string $branch,
    array $paths,
    string $remoteRef = 'refs/heads/feature',
    int $commits = 1
): array {
    $base = git($fixture, 'rev-parse main');
    git($fixture, 'checkout --quiet -B '.escapeshellarg($branch).' main');

    for ($i = 0; $i < $commits; $i++) {
        foreach ($paths as $path) {
            $full = $fixture.'/'.$path;
            @mkdir(dirname($full), 0o777, true);
            file_put_contents($full, "commit {$i} for {$branch}\n");
        }

        git($fixture, 'add -A');
        git($fixture, 'commit --quiet -m '.escapeshellarg('control commit '.$i));
    }

    $head = git($fixture, 'rev-parse HEAD');
    $result = run_gate($fixture, 'refs/heads/'.$branch, $head, $remoteRef, $base);

    git($fixture, 'checkout --quiet main');
    git($fixture, 'branch -D '.escapeshellarg($branch));

    return $result;
}

/** @return array{0:int,1:string} */
function run_gate(string $fixture, string $localRef, string $localSha, string $remoteRef, string $remoteSha): array
{
    $stdin = $fixture.'/.control-stdin';
    file_put_contents($stdin, sprintf("%s %s %s %s\n", $localRef, $localSha, $remoteRef, $remoteSha));

    $command = sprintf('%s %s origin https://example.invalid < %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($fixture.'/'.GATE),
        escapeshellarg($stdin));

    $output = [];
    $exit = 0;
    exec($command, $output, $exit);
    @unlink($stdin);

    return [$exit, implode("\n", $output)];
}

function build_fixture(string $fixture, string $gateBytes): void
{
    foreach (['scripts', 'docs/claims', '.github/workflows', 'app'] as $dir) {
        if (! mkdir($fixture.'/'.$dir, 0o777, true) && ! is_dir($fixture.'/'.$dir)) {
            cannot_measure('could not create the fixture directory '.$fixture.'/'.$dir);
        }
    }

    file_put_contents($fixture.'/'.GATE, $gateBytes);

    // Every entry here is in PROTOCOL_PATHS, so the superset assertion passes. Five clears the
    // guard's floor of three honestly rather than squeaking under it.
    file_put_contents($fixture.'/.github/workflows/ci.yml', implode("\n", [
        'on:',
        '  push:',
        '    paths-ignore:',
        "      - 'PROGRESS.md'",
        "      - 'PROGRESS_ARCHIVE.md'",
        "      - 'docs/claims/**'",
        "      - 'docs/gate-baselines.md'",
        "      - 'docs/backlog-triage.md'",
        '',
    ]));

    file_put_contents($fixture.'/docs/claims/lane-a.md',
        "# Lane A — active claim\n\n## Status: ACTIVE CLAIM — a synthetic control claim (".CLAIMED.")\n");

    file_put_contents($fixture.'/app/Thing.php', "<?php\n");
    file_put_contents($fixture.'/docs/pipeline.md', "# The pipeline\n");
    file_put_contents($fixture.'/docs/claims/decisions.md', "# Decisions\n");

    git($fixture, 'init --quiet');
    git($fixture, 'config user.email control@example.invalid');
    git($fixture, 'config user.name Control');
    git($fixture, 'config commit.gpgsign false');
    git($fixture, 'add -A');
    git($fixture, 'commit --quiet -m '.escapeshellarg('fixture base'));
    git($fixture, 'branch -M main');

    // The guard reads the claim with `git show origin/main:<path>`, so the fixture needs a ref by
    // that exact name. A remote-tracking ref can be written directly; no remote is involved.
    git($fixture, 'update-ref refs/remotes/origin/main '.git($fixture, 'rev-parse main'));
}

function git(string $fixture, string $arguments): string
{
    $output = [];
    $status = 0;
    exec('git -C '.escapeshellarg($fixture).' '.$arguments.' 2>&1', $output, $status);

    if ($status !== 0) {
        cannot_measure('git '.$arguments.' failed in the fixture: '.implode(' ', $output));
    }

    return trim(implode("\n", $output));
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
        $path = $dir.'/'.$entry;
        is_dir($path) && ! is_link($path) ? rrmdir($path) : @unlink($path);
    }

    @rmdir($dir);
}

function cannot_measure(string $why): never
{
    fwrite(STDERR, "pre-push-guard-controls: CANNOT MEASURE — {$why}\n");
    exit(2);
}
