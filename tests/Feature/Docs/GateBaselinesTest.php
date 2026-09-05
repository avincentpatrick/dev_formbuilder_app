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
];

function gateBaselinesFixtureDir(): string
{
    return base_path('tests/fixtures/gate-baselines');
}

/**
 * Run the generator over one scenario, always in --dry-run, and return [exitCode, stdout+stderr].
 *
 * @return array{0: int, 1: string}
 */
function gateBaselinesRun(string $scenario): array
{
    $php = escapeshellarg(PHP_BINARY);
    $dir = gateBaselinesFixtureDir();

    putenv('GATE_BASELINES_SCENARIO='.$scenario);
    putenv('GATE_BASELINES_GH='.$php.' '.escapeshellarg($dir.'/gh.php'));
    putenv('GATE_BASELINES_GIT='.$php.' '.escapeshellarg($dir.'/git.php'));

    $command = $php.' '.escapeshellarg(base_path('scripts/gate-baselines.php')).' --dry-run';

    $output = [];
    $status = 0;
    // Read the status directly: a pipe hides the exit status.
    exec($command.' 2>&1', $output, $status);

    putenv('GATE_BASELINES_SCENARIO');
    putenv('GATE_BASELINES_GH');
    putenv('GATE_BASELINES_GIT');

    return [$status, implode("\n", $output)];
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

    // ⚠️ THE WEAKEST ARM, AND SAYING SO IS THE POINT. Deleting the sha-shape check does not make this
    //    case pass — git would be handed an empty rev and the ancestry arm would refuse instead. It
    //    is non-vacuous only at the level of the MESSAGE, which is why the assertion is on the
    //    sentence. It is defence-in-depth for a diagnostic, not an independent control.
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
