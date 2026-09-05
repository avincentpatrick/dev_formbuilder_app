<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controls for the recency guard in `scripts/gate-baselines.php` (M74).
|--------------------------------------------------------------------------
| WHAT HAPPENED. Closing out M73, the no---run branch took run 33184885256 — a real, successful,
| `push` run on `main` from 2026-08-28 — while the intended run had already concluded. The file was
| written, reported success, and carried baselines from a tree 141 commits behind.
|
| ⛔ M39'S GUARD PASSES THAT RUN ON ALL THREE OF ITS ARMS. conclusion `success`, headBranch `main`,
| event not `pull_request`. It asks WHAT KIND OF RUN THIS IS and never WHETHER IT IS THE RUN WE MEANT.
| Recency is the one property it does not assert and the one that failed.
|
| ⛔ WHY TWO SEAMS AND NOT ONE. A `gh`-only seam proves the environment rather than the guard. With a
| real `git`, `foreign-sha` cannot be driven at all: a fabricated sha exits 128 (`bad object`), which
| is the "could not decide" arm, so the case would go green through a branch it does not name — the
| M49 shape exactly, which is why every refusal below asserts its OWN sentence and not a shared
| prefix. And git is absent inside the app container where Pest runs (MutateHarnessTest records the
| same absence), so the arms would measure the runner.
|
| ⛔ EVERY CASE RUNS `--dry-run`, AND THAT IS NOT TIDINESS. The script's default action is a WRITE to
| docs/gate-baselines.md; a control that ran it for real would overwrite the repository's tracked
| baselines with fixture garbage on every suite run. The guard sits far above the write, so
| `--dry-run` exercises it in full. The last case asserts the file's bytes never moved.
|
| ⚠️ THE TWO PASSING CASES ARE NOT DECORATION — THEY ARE THE REFUSES-EVERYTHING PARTNER. A guard that
| rejected every run would satisfy all four refusal cases below. `recent-push` and `nightly-schedule`
| are the only two that catch it, and `nightly-schedule` additionally catches the wrong DESIGN: it is
| hours old with zero commits behind, so a wall-clock age check refuses it while a commit-distance
| check passes it. ci.yml and gate-baselines.php both require `schedule` runs on main to be accepted.
|
| ⚠️ Helpers are prefixed `gateBaselines*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration. `npmAudit*`, `mutateHarness*`,
| `trackerSurgery*`, `documentedCommand*` and `documentedDefault*` are already taken here.
|
| ⚠️ The environment is set with putenv() and never as a `VAR=value cmd` prefix: exec() runs through
| cmd.exe on this project's Windows host, which has no inline env syntax.
|
| ⛔ WHY A PEST FILE AND NOT A `scripts/*-controls.php` SIBLING. `php artisan test` discovers
| everything under tests/Feature, so this needs no composer.json alias, no `composer run quality`
| entry and no ci.yml step — which keeps three hub files out of a diff that has already spent its one
| hub allowance under D13. Same argument TrackerSurgeryHarnessTest and NpmAuditJudgeTest each record.
*/

/** The scenarios the controls below name. The discovery floor asserts this list IS the directory. */
const GATE_BASELINES_SCENARIOS = [
    'recent-push',
    'nightly-schedule',
    'stale-push',
    'foreign-sha',
    'git-undecidable',
    'unreadable-sha',
    // M75 — the first scenario that gets PAST the recency guard and then fails to scrape. Every one
    // above shares `ci-log.txt`, which satisfies all twelve patterns, so until this one the
    // `$missing > 0` branch could not be reached by any control at all.
    'missing-metric',
];

function gateBaselinesFixtureDir(): string
{
    return base_path('tests/fixtures/gate-baselines');
}

/**
 * Run the generator over one scenario and return [exitCode, stdout, stderr] — SEPARATELY.
 *
 * ⛔ `proc_open` RATHER THAN `exec(… 2>&1)`, AND THE OLD FORM MADE ONE PROPERTY UNPROVABLE (M75). The
 * helper used to merge the streams, so nothing here could tell stdout from stderr — and the property
 * M75 added is exactly that the NOT FOUND diagnostic goes to STDERR while `--dry-run`'s document goes
 * to STDOUT. That separation is the only reason `--dry-run` can be piped at all. A helper that cannot
 * see it cannot test it, and a case asserting on the merged text would have passed either way.
 *
 * ⚠️ IT ALSO RETIRES THE `putenv()` DANCE. The environment is handed to `proc_open` as an array, so
 * there is no process-global state to set and unset, and the Windows note the old helper carried —
 * `exec()` runs through cmd.exe, which has no inline `VAR=value cmd` syntax — stops applying rather
 * than being worked around.
 *
 * @param  array<string, string>  $env  extra environment, e.g. GATE_BASELINES_OUT
 * @return array{0: int, 1: string, 2: string}
 */
function gateBaselinesRunStreams(string $scenario, string $arguments = '--dry-run', array $env = []): array
{
    $php = escapeshellarg(PHP_BINARY);
    $dir = gateBaselinesFixtureDir();

    $environment = array_merge(getenv(), [
        'GATE_BASELINES_SCENARIO' => $scenario,
        'GATE_BASELINES_GH' => $php.' '.escapeshellarg($dir.'/gh.php'),
        'GATE_BASELINES_GIT' => $php.' '.escapeshellarg($dir.'/git.php'),
    ], $env);

    $command = $php.' '.escapeshellarg(base_path('scripts/gate-baselines.php')).' '.$arguments;
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);

    expect(is_resource($process))->toBeTrue('could not start '.$command);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // Read the status from proc_close itself: a pipe hides the exit status.
    return [proc_close($process), $stdout, $stderr];
}

/**
 * The merged view, for the recency cases that predate the split and do not care which stream spoke.
 *
 * @return array{0: int, 1: string}
 */
function gateBaselinesRun(string $scenario): array
{
    [$status, $stdout, $stderr] = gateBaselinesRunStreams($scenario);

    return [$status, $stdout."\n".$stderr];
}

it('has every fixture the controls name, and the directory holds nothing else', function (): void {
    // A discovery floor, reached before the failure rather than after it. Without it a renamed or
    // lost scenario makes the stub exit 64 for every case; four of the six ASSERT a non-zero exit,
    // so two thirds of this file would go green over nothing at all.
    foreach (GATE_BASELINES_SCENARIOS as $scenario) {
        $path = gateBaselinesFixtureDir().'/'.$scenario.'.json';

        expect(is_file($path))->toBeTrue('scenario fixture is missing: '.$path);
        expect(json_decode((string) file_get_contents($path), true))
            ->toBeArray('scenario fixture is not a JSON object: '.$path);
    }

    $onDisk = glob(gateBaselinesFixtureDir().'/*.json') ?: [];

    expect(count($onDisk))->toBe(
        count(GATE_BASELINES_SCENARIOS),
        'The fixture directory holds '.count($onDisk).' scenario(s) and the controls name '.
        count(GATE_BASELINES_SCENARIOS).'. A fixture nothing asserts is a shape nobody has decided about.'
    );

    foreach (['gh.php', 'git.php', 'ci-log.txt'] as $support) {
        expect(is_file(gateBaselinesFixtureDir().'/'.$support))->toBeTrue('missing stub: '.$support);
    }
});

it('accepts a current push run on main, and scrapes every metric from its log', function (): void {
    [$status, $output] = gateBaselinesRun('recent-push');

    expect($status)->toBe(0, $output);

    // ⛔ THE NON-VACUITY PARTNER FOR ALL FOUR REFUSALS. A guard that refused every run passes each of
    //    them and fails only here.
    // ⚠️ And the exit code alone is not enough: --dry-run also exits 1 when a metric pattern misses,
    //    so a zero here asserts BOTH that the guard admitted the run and that all twelve rows carry a
    //    value. The figures themselves are deliberately implausible fixtures and are never asserted —
    //    docs/gate-baselines.md is the only place real gate numbers live.
    expect($output)->not->toContain('**NOT FOUND**');
});

it('accepts the nightly schedule run, which is hours old and zero commits behind', function (): void {
    [$status, $output] = gateBaselinesRun('nightly-schedule');

    // ⛔ THE DESIGN CASE, NOT AN EDGE CASE. ci.yml and scripts/gate-baselines.php both require
    //    `schedule` and `workflow_dispatch` runs on main to be accepted — the nightly cron is
    //    deliberate insurance against an outage-skipped verification. This run is eight hours old
    //    with a head sha level with the trunk, so ANY wall-clock age check refuses it and a commit
    //    distance check passes it. That is the whole argument for distance over age, made executable.
    expect($status)->toBe(0, $output);
});

it('names the unscraped metric on stdout and the count on STDERR, and says nothing was written', function (): void {
    // ⛔ THE HALF OF THE ROW THAT WAS FALSE, PINNED SO IT CANNOT BE RE-FILED. The row says the NOT
    //    FOUND message "never prints at all" under --dry-run and that "the only signal is an exit
    //    code". It is not: the DOCUMENT carries a row naming the failing metric, which is strictly
    //    more actionable than the count. What was genuinely missing is the count itself, on stderr.
    [$status, $stdout, $stderr] = gateBaselinesRunStreams('missing-metric');

    expect($status)->toBe(1, $stdout.$stderr);
    expect($stdout)->toContain('**NOT FOUND**');
    expect($stdout)->toContain('citation-liveness-lint');

    // ⛔ THE STREAM IS THE ASSERTION, NOT SCENERY. --dry-run's whole output IS the document, so a
    //    diagnostic on stdout would corrupt anything piping it. Only the split helper can see this.
    expect($stderr)->toContain('1 metric(s) NOT FOUND');
    expect($stderr)->toContain('Nothing was written');
    expect($stdout)->not->toContain('metric(s) NOT FOUND');
});

it('WRITES the file when a metric is unscraped, and does not call that success', function (): void {
    // ⛔ THE ROW'S OWN REMEDY IS THE ONE THING THIS CASE REFUSES. It prescribes moving the write below
    //    the judgement — a refusal-to-write — which M70 already adjudicated for this script and
    //    rejected, and which strands close-out step 3: scripts/next.php stamps "regenerate it" until
    //    the file moves, so a refusal nags forever with nothing that can satisfy it. A NOT FOUND row
    //    in the file says WHICH metric is unscraped; an absent file says nothing.
    // ⚠️ THIS IS THE FIRST CONTROL IN THIS FILE THAT EXERCISES THE WRITE AT ALL, via GATE_BASELINES_OUT.
    //    Everything else runs --dry-run so the repository's own baselines are never touched — an
    //    invariant the last case in this file still asserts by sha256, and which this seam preserves
    //    by redirecting the destination rather than by weakening any guard.
    $out = tempnam(sys_get_temp_dir(), 'gate-baselines-');

    try {
        [$status, $stdout, $stderr] = gateBaselinesRunStreams('missing-metric', '', ['GATE_BASELINES_OUT' => $out]);

        expect($status)->toBe(1, $stdout.$stderr);
        expect(file_get_contents($out))->toContain('**NOT FOUND**');

        // ⛔ THE DEFECT, PINNED. This sentence used to print UNCONDITIONALLY, three lines above the
        //    judgement — so the script announced success and then contradicted itself on stderr.
        expect($stdout)->not->toContain('wrote docs/gate-baselines.md');
        expect($stderr)->toContain('1 metric(s) NOT FOUND');
        expect($stderr)->toContain('written anyway');
    } finally {
        @unlink($out);
    }
});

it('still says so plainly when the scrape DID find everything — the non-vacuity partner', function (): void {
    // Without this, "does not print the success sentence" is satisfied by a script that never prints
    // it at all, which is the same defect wearing the opposite sign.
    $out = tempnam(sys_get_temp_dir(), 'gate-baselines-');

    try {
        [$status, $stdout, $stderr] = gateBaselinesRunStreams('recent-push', '', ['GATE_BASELINES_OUT' => $out]);

        expect($status)->toBe(0, $stdout.$stderr);
        expect($stdout)->toContain('wrote docs/gate-baselines.md');
        expect($stderr)->not->toContain('NOT FOUND');
        expect(file_get_contents($out))->not->toContain('**NOT FOUND**');
    } finally {
        @unlink($out);
    }
});

it('refuses a stale run on main, which every M39 arm accepts', function (): void {
    [$status, $output] = gateBaselinesRun('stale-push');

    expect($status)->toBe(1, $output);
    expect($output)->toContain('141 commits behind origin/main');
    // The specific sentence, never a shared prefix: this run is a successful push on main, so it must
    // be refused for its DISTANCE and not by one of the three arms that already existed.
    expect($output)->not->toContain('not an ancestor');
    expect($output)->not->toContain('could not decide');
});

it('refuses a run whose head sha is not on the trunk, which distance alone cannot catch', function (): void {
    [$status, $output] = gateBaselinesRun('foreign-sha');

    expect($status)->toBe(1, $output);
    expect($output)->toContain('is not an ancestor of');

    // ⛔ THE DISCRIMINATOR. scripts/state.php's commits_behind_trunk() computes distance and never
    //    ancestry — measured: `git rev-list --count <non-ancestor>..origin/main` returns a happy
    //    finite number rather than an error. This fixture reports 886 behind, comfortably over the
    //    ceiling, so a distance-only guard would ALSO refuse it and look correct. Asserting the
    //    ancestry sentence is what separates the two.
    expect($output)->not->toContain('commits behind origin/main');
});

it('refuses when git cannot decide, rather than reading silence as a pass', function (): void {
    [$status, $output] = gateBaselinesRun('git-undecidable');

    expect($status)->toBe(1, $output);
    expect($output)->toContain('could not decide whether');

    // ⛔ A CANNOT-MEASURE CONTROL CAN PASS THROUGH A DIFFERENT BRANCH THAN THE ONE IT NAMES (M49), so
    //    the "not an ancestor" sentence must be absent. `--is-ancestor` answers 0 or 1 and reserves
    //    every other status for "could not decide"; collapsing the third into either verdict is the
    //    succeeds-on-empty-input family this repository has now measured six times.
    expect($output)->not->toContain('is not an ancestor of');
});

it('refuses a run reporting no usable head sha', function (): void {
    [$status, $output] = gateBaselinesRun('unreadable-sha');

    expect($status)->toBe(1, $output);
    expect($output)->toContain('no usable head sha');

    // ⚠️ THE FIRST DRAFT OF THIS COMMENT WAS WRONG AND THE MUTATION SAID SO, WHICH IS WHY IT IS
    //    CORRECTED HERE RATHER THAN QUIETLY. It predicted that disarming the sha-shape check would
    //    leave this case GREEN — git handed an empty rev, the ancestry arm refusing instead, the
    //    control non-vacuous only at the level of the message. Measured: disarming it turns this case
    //    RED on its own. The stub answers `--is-ancestor` from its fixture and does not model git's
    //    rejection of an empty rev, so the run sails past both later arms and exits 0.
    // ⚠️ SO THE ARM IS INDEPENDENTLY CONTROLLED HERE AND ONLY PARTLY LOAD-BEARING IN PRODUCTION,
    //    where real git would refuse the empty rev a moment later with a different sentence. It earns
    //    its place as a diagnostic — "no usable head sha" names the cause, "git could not decide"
    //    does not — and the message assertion below is what pins that distinction.
    expect($output)->not->toContain('could not decide whether');
});

it('never writes docs/gate-baselines.md while proving any of this', function (): void {
    // ⛔ The generator's DEFAULT action is a write. If a control ever loses its --dry-run, this suite
    //    would silently replace the repository's real baselines with the implausible fixture figures
    //    and every later reader would quote them. Bytes, not mtime: a rewrite with identical content
    //    is still a rewrite, but a content change is the harm.
    $path = base_path('docs/gate-baselines.md');
    $before = hash_file('sha256', $path);

    foreach (GATE_BASELINES_SCENARIOS as $scenario) {
        gateBaselinesRun($scenario);
    }

    expect(hash_file('sha256', $path))->toBe($before, 'a control wrote to docs/gate-baselines.md');
});
