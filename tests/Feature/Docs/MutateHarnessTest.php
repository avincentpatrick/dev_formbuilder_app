<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controls for `scripts/mutate.php` (M73).
|--------------------------------------------------------------------------
| ⛔ THE HARNESS THIS PROJECT USES TO PROVE ITS GATES HAD NO CONTROLS OF ITS OWN, AND IT WAS
| MANUFACTURING VERDICTS. When `docker exec` failed, `run_pest()` captured no `Tests:` line, `failed`
| stayed 0, the baseline gate `0 > 0` passed an UNMEASURED baseline, and the run printed
| "SURVIVED — the mutant changed NOTHING… That is the finding — file it rather than explaining it
| away" and exited EXIT_SURVIVED. A Docker outage did not merely skip a check: it fabricated a finding
| that read as a measured result and invited a backlog row. Filed by M72 (run_pest) and M71 (the
| concurrent-suite probe) as two rows; they are one file and one shape, and M73 closed them together.
|
| ⛔ WHY THIS IS A PEST FILE AND NOT A `scripts/mutate-controls.php` SIBLING. `php artisan test`
| discovers everything under `tests/Feature`, so this needs no `composer.json` alias, no
| `composer run quality` entry and no `ci.yml` step — three hub files kept out of the diff, which is
| what let this row be batched at all. It is the argument `tests/Feature/Docs/NpmAuditJudgeTest.php`
| and `tests/Feature/Docs/TrackerSurgeryHarnessTest.php` both record for themselves.
|
| ⛔ AND THE OBVIOUS CONTROL PROVES THE WRONG GUARD. The concurrent-suite probe runs FIRST, so pointing
| `--container` at a name that does not exist aborts there and `run_pest()` is never reached — the
| "control" would prove R1 twice and report success. Telling the two apart needs a runtime that is
| reachable AND has no PHP. One exists on the dev host (`dev_formbuilder_app-redis-1`: busybox `sh`,
| `ps` and `wc`, no `php`) and none exists on a CI runner, where that case would silently collapse back
| onto R1. `MUTATE_DOCKER` names a stub instead, so both arms are deterministic everywhere.
|
| ⚠️ THE STUB REPLACES THE RUNTIME, NEVER A GUARD. Every refusal, the token-uniqueness check, the
| sha256-must-move clause and the byte-comparison restore all run exactly as they do in anger. The
| `sees-mutation` stub reads the target file off disk, so the write and the restore are proved by
| observation rather than asserted about.
|
| ⚠️ Helpers are prefixed `mutateHarness*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration. `npmAudit*`, `trackerSurgery*`,
| `documentedCommand*` and `documentedDefault*` are already taken here.
|
| ⚠️ No DB. Nothing here touches Eloquent, so this file applies no RefreshDatabase.
*/

/** The three-way contract `scripts/mutate.php` publishes. Exit 2 is a FINDING, never a pass. */
const MUTATE_CAUGHT = 0;

const MUTATE_ABORTED = 1;

const MUTATE_SURVIVED = 2;

/** A stub container runtime, invoked through PHP so the seam is portable to Windows and Linux alike. */
function mutateHarnessStub(string $name): string
{
    return escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('tests/fixtures/mutate/'.$name.'.php'));
}

/**
 * Run the harness against the committed throwaway target with a stubbed runtime.
 *
 * The exit code is read directly rather than through a pipe, because a pipe hides the exit status.
 *
 * @param  list<string>  $extra
 * @return array{0: int, 1: string}
 */
function mutateHarnessRun(string $stub, array $extra = [], ?string $file = null): array
{
    $arguments = [
        '--file='.($file ?? 'tests/fixtures/mutate/target.txt'),
        '--old='.base_path('tests/fixtures/mutate/old.txt'),
        '--new='.base_path('tests/fixtures/mutate/new.txt'),
        '--tests=tests/Fixture/SentinelTest.php',
        '--label=mutate harness control',
    ];

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/mutate.php'));

    foreach ([...$arguments, ...$extra] as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    // ⚠️ `putenv`, NOT a `VAR=value cmd` PREFIX. PHP's exec() goes through cmd.exe on the Windows dev
    // host, which has no inline environment-variable syntax — the prefix would be parsed as the command
    // name and the run would fail for a reason that has nothing to do with the harness. A child process
    // inherits the parent's environment on both platforms, so this is the portable form.
    $previous = getenv('MUTATE_DOCKER');
    putenv('MUTATE_DOCKER='.$stub);

    try {
        $output = [];
        $status = 0;
        exec($command.' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    } finally {
        $previous === false ? putenv('MUTATE_DOCKER') : putenv('MUTATE_DOCKER='.$previous);
    }
}

/** The bytes of the throwaway target, so every case can assert the tree was left alone. */
function mutateHarnessTargetBytes(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/mutate/target.txt'));
}

/** Is `git` on PATH? Everything past R2 depends on it, and it is absent inside the app container. */
function mutateHarnessGitAvailable(): bool
{
    $output = [];
    $status = 0;
    exec('git --version 2>&1', $output, $status);

    return $status === 0;
}

/*
 * ⛔ AN AUDIBLE SKIP, NOT A SILENT ONE, AND THE REASON IS THE FIX ITSELF. `scripts/mutate.php`'s own
 * header says it "Runs on the HOST", and M73 made that true rather than merely stated: with git absent
 * — which is exactly the case inside `dev_formbuilder_app-app-1` — R2 now ABORTS instead of reading
 * empty output as a clean tree. So the four cases below cannot be driven where git is missing, because
 * the harness correctly refuses before reaching the guard each one is about.
 *
 * CI's Pest job runs on the runner, where `actions/checkout` guarantees git, so nothing is skipped
 * there. The final case deliberately carries NO skip: it is the one that asserts this very refusal, and
 * it is meaningful in both environments.
 */
function mutateHarnessNeedsGit(): string
{
    return 'git is not on PATH — scripts/mutate.php runs on the HOST and its R2 guard correctly aborts '.
           'here, so this case cannot reach the guard it is about. Run it on the host.';
}

it('REFUSES rather than assuming no suite is running when the container cannot be probed', function (): void {
    $before = mutateHarnessTargetBytes();

    [$status, $output] = mutateHarnessRun(mutateHarnessStub('docker-unreachable'));

    // ⛔ THE EXIT CODE IS THE ASSERTION. Before M73 this run reached the verdict and exited SURVIVED (2),
    // which reads as a measured finding. It must now abort (1) — and the two are only one apart, so the
    // check is written against the constant rather than "non-zero", which SURVIVED also satisfies.
    expect($status)->toBe(MUTATE_ABORTED, "expected ABORT, got:\n".$output);
    expect($output)->toContain('could not probe');
    // ⚠️ THE BANNER, NOT THE BARE WORD. The abort messages themselves say "is NOT a SURVIVED
    // verdict", so asserting the absence of the literal would match this file's own diagnostic —
    // the M49 shape, where a check passes or fails through a different branch than the one it names.
    expect($output)->not->toContain('SURVIVED — mutate harness control');
    expect($output)->not->toContain('The mutant changed NOTHING');

    // The daemon's own diagnostic must survive into the message: `is_numeric` alone would have refused
    // while printing nothing, because this probe lacked the `2>&1` that preflight.php's has.
    expect($output)->toContain('Cannot connect to the Docker daemon');

    expect(mutateHarnessTargetBytes())->toBe($before);
})->skip(fn (): bool => ! mutateHarnessGitAvailable(), mutateHarnessNeedsGit());

it('REFUSES an unmeasured baseline instead of reading "no summary line" as zero failures', function (): void {
    $before = mutateHarnessTargetBytes();

    // ⛔ THIS IS THE CASE THAT SEPARATES THE TWO GUARDS, and the only one that is red for the
    // `run_pest()` fix alone. The stub's probe answers `0`, so R1 passes HONESTLY and execution reaches
    // the baseline; the Pest arm then fails the way a php-less container does. Revert R1 and this case
    // still passes; revert the run_pest refusal and it goes red. Delete the stub's `sh` arm and it
    // collapses onto the case above — which is exactly the trap it exists to avoid.
    [$status, $output] = mutateHarnessRun(mutateHarnessStub('docker-no-pest'));

    expect($status)->toBe(MUTATE_ABORTED, "expected ABORT, got:\n".$output);
    expect($output)->toContain('the baseline did not RUN');
    expect($output)->toContain('UNMEASURED');

    // The harness must not have reached its verdict at all.
    // ⚠️ THE BANNER, NOT THE BARE WORD. The abort messages themselves say "is NOT a SURVIVED
    // verdict", so asserting the absence of the literal would match this file's own diagnostic —
    // the M49 shape, where a check passes or fails through a different branch than the one it names.
    expect($output)->not->toContain('SURVIVED — mutate harness control');
    expect($output)->not->toContain('The mutant changed NOTHING');
    expect($output)->not->toContain('CAUGHT');

    // It got past R1, which is what makes this a different case from the one above rather than a
    // second copy of it. A positive assertion, because absence of the R1 message would also be
    // satisfied by a harness that never ran.
    expect($output)->toContain('BASELINE');
    expect($output)->not->toContain('could not probe');

    expect(mutateHarnessTargetBytes())->toBe($before);
})->skip(fn (): bool => ! mutateHarnessGitAvailable(), mutateHarnessNeedsGit());

it('still reaches SURVIVED on a measured, green run — the refusals are not a blanket refusal', function (): void {
    $before = mutateHarnessTargetBytes();

    // ⚠️ THE NON-VACUITY PARTNER. Both cases above assert an abort, and a harness that aborted on
    // EVERY input would satisfy them and be useless. This proves the guards are discriminating: a
    // measured baseline and a measured mutant, both green, still produce the SURVIVED finding.
    [$status, $output] = mutateHarnessRun(mutateHarnessStub('docker-blind'));

    expect($status)->toBe(MUTATE_SURVIVED, "expected SURVIVED, got:\n".$output);
    expect($output)->toContain('SURVIVED');
    expect($output)->toContain('The mutant changed NOTHING');

    // R5 ran: the file is back to its exact original bytes.
    expect(mutateHarnessTargetBytes())->toBe($before);
    expect($output)->toContain('restored; sha256 back to');
})->skip(fn (): bool => ! mutateHarnessGitAvailable(), mutateHarnessNeedsGit());

it('reaches CAUGHT, and proves the mutant really reached disk and was really restored', function (): void {
    $before = mutateHarnessTargetBytes();
    expect($before)->toContain('sentinel');

    // ⛔ THE STRONGEST OF THE FOUR: this stub READS THE TARGET, so the verdict is a consequence of the
    // bytes on disk rather than of a canned summary. Green baseline and red mutant differ only in what
    // was written between them, so CAUGHT here is direct evidence that R3's write landed — and the
    // byte-identical target afterwards is direct evidence that R5's restore ran.
    [$status, $output] = mutateHarnessRun(mutateHarnessStub('docker-sees-mutation'));

    expect($status)->toBe(MUTATE_CAUGHT, "expected CAUGHT, got:\n".$output);
    expect($output)->toContain('CAUGHT');
    expect($output)->toContain('the sentinel line is intact');

    expect(mutateHarnessTargetBytes())->toBe($before);
})->skip(fn (): bool => ! mutateHarnessGitAvailable(), mutateHarnessNeedsGit());

it('RESTORES the target before refusing an unmeasured MUTANT run, so an outage cannot corrupt the tree', function (): void {
    $before = mutateHarnessTargetBytes();
    expect($before)->toContain('sentinel');

    // ⛔ THE M62 CLAUSE, AND THE ONE THE ROWS DID NOT NAME. run_pest() is called a second time AFTER the
    // mutant is on disk, so a refusal written inside run_pest() — the obvious place — would abort
    // between the write and the restore and leave the mutant behind; a second run would then stack
    // another on top of it, which is precisely how M62 corrupted a tree. The refusal therefore lives at
    // the CALL SITE with the original bytes in scope, and restores first.
    //
    // `--skip-baseline` is what reaches it: without it the run aborts at the baseline (the case above)
    // and the mutant arm is never exercised.
    [$status, $output] = mutateHarnessRun(mutateHarnessStub('docker-no-pest'), ['--skip-baseline']);

    expect($status)->toBe(MUTATE_ABORTED, "expected ABORT, got:\n".$output);
    expect($output)->toContain('the mutant run did not RUN');
    // ⚠️ THE BANNER, NOT THE BARE WORD. The abort messages themselves say "is NOT a SURVIVED
    // verdict", so asserting the absence of the literal would match this file's own diagnostic —
    // the M49 shape, where a check passes or fails through a different branch than the one it names.
    expect($output)->not->toContain('SURVIVED — mutate harness control');
    expect($output)->not->toContain('The mutant changed NOTHING');
    expect($output)->not->toContain('CAUGHT');

    // ⛔ THE ASSERTION THAT MATTERS IS THIS ONE, NOT THE EXIT CODE. The mutant was written — the run got
    // past R3's sha256-must-move clause — and the tree must still be byte-identical afterwards.
    expect($output)->toContain('sha256');
    expect(mutateHarnessTargetBytes())->toBe($before);
})->skip(fn (): bool => ! mutateHarnessGitAvailable(), mutateHarnessNeedsGit());

it('REFUSES a target whose cleanliness it could not establish, rather than reading silence as clean', function (): void {
    // ⛔ R2's `trim('') !== ''` is false whether the tree is clean or `git` could not run at all — and
    // git is ABSENT from the app container (php:8.4-fpm-alpine never installs it) while
    // /var/www/html/.git is visible over the bind mount, so an in-container invocation had a
    // permanently vacuous R2. There is no seam for the git binary, so this drives the other half of the
    // same guard: a path git reports on but which is not clean.
    $untracked = base_path('tests/fixtures/mutate/untracked-probe.txt');
    file_put_contents($untracked, "not committed\n");

    try {
        [$status, $output] = mutateHarnessRun(
            mutateHarnessStub('docker-blind'),
            [],
            'tests/fixtures/mutate/untracked-probe.txt',
        );

        expect($status)->toBe(MUTATE_ABORTED, "expected ABORT, got:\n".$output);
        // ⚠️ THE BANNER, NOT THE BARE WORD. The abort messages themselves say "is NOT a SURVIVED
        // verdict", so asserting the absence of the literal would match this file's own diagnostic —
        // the M49 shape, where a check passes or fails through a different branch than the one it names.
        expect($output)->not->toContain('SURVIVED — mutate harness control');
        expect($output)->not->toContain('The mutant changed NOTHING');

        // ⚠️ WHICH ARM FIRES IS DECIDED BY THE ENVIRONMENT, AND BOTH ARE THIS GUARD REFUSING. On the
        // host and on a CI runner git resolves, so R2 sees `?? untracked` and names it. Run this same
        // file inside the app container and git is not there at all — which is the vacuous half M72
        // filed, and it now aborts instead of reading empty output as a clean tree. Asserting the arm
        // that actually fired keeps the message specific in both, rather than loosening to a shared
        // prefix that a different failure could also satisfy (M49).
        exec('git --version 2>&1', $probe, $gitStatus);

        if ($gitStatus === 0) {
            expect($output)->toContain('uncommitted changes');
        } else {
            expect($output)->toContain('could not run `git status`');
            expect($output)->toContain('the cleanliness of the target is UNKNOWN');
        }
    } finally {
        @unlink($untracked);
    }
});
