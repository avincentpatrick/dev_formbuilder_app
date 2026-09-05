<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Controls for `scripts/npm-audit-judge.php` (M72).
|--------------------------------------------------------------------------
| The judge exists because `npm audit --omit=dev --audit-level=high` exits 1 for BOTH "a high
| advisory was found" and "the registry could not be reached", and that conflation reddened `main`
| twice on consecutive increments (M69's PR run 33818367732, M70's post-merge run 33852073344). It is
| a merge-blocking control, so it needs controls of its own.
|
| ⛔ WHY THIS IS A PEST FILE AND NOT A `scripts/*-controls.php` SIBLING. `php artisan test` discovers
| everything under `tests/Feature`, so this needs no `composer.json` alias, no `composer run quality`
| entry and no `ci.yml` step — which is what keeps two hub files out of this increment's diff. It is
| the same argument `tests/Feature/Docs/TrackerSurgeryHarnessTest.php` records for itself, and the
| deciding half is that `scripts/mutate.php` drives Pest in a container AND NOTHING ELSE: a
| `scripts/` sibling could not be turned red by a deliberate defect, and a control that cannot be
| proved is the decorative gate M43 measured.
|
| ⚠️ THE CASE THAT CARRIES THE WHOLE DESIGN IS `unrecognised-shape`. Every other case would also pass
| against a judge written the obvious wrong way — `! isset($report['error'])`. That one would not: a
| report with neither a severity map nor an `error` key must land in CANNOT MEASURE, and a negative
| recognition test waves it through as CLEAN. Read it as the discriminator, not as an edge case.
|
| ⚠️ The exit code is read directly rather than through a pipe, because a pipe hides the exit status.
|
| ⚠️ Helpers are prefixed `npmAudit*` deliberately: Pest loads every file in a directory into one
| process, so a same-named file-scope helper is a fatal redeclaration. `trackerSurgery*`,
| `documentedCommand*` and `documentedDefault*` are already taken in this directory.
*/

/** The three-way contract the judge publishes. Exit 2 is never a pass. */
const NPM_AUDIT_JUDGE_OK = 0;

const NPM_AUDIT_JUDGE_BLOCKED = 1;

const NPM_AUDIT_JUDGE_CANNOT_MEASURE = 2;

/**
 * Run the judge over one argument list and return [exitCode, combined output].
 *
 * @param  list<string>  $arguments
 * @return array{0: int, 1: string}
 */
function npmAuditRun(array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/npm-audit-judge.php'));

    foreach ($arguments as $argument) {
        $command .= ' '.escapeshellarg($argument);
    }

    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

/** The committed fixture for one report shape. */
function npmAuditFixture(string $name): string
{
    return base_path('tests/fixtures/npm-audit/'.$name.'.json');
}

/** Run the judge over a committed fixture. @return array{0: int, 1: string} */
function npmAuditJudge(string $fixture): array
{
    return npmAuditRun([npmAuditFixture($fixture)]);
}

it('has every fixture the controls below name, and each is real JSON', function (): void {
    // A discovery floor. Without it, a rename or a lost fixture makes every case below run against a
    // missing file, all five come back CANNOT MEASURE, and three of them are ASSERTING that — so the
    // suite would go green over nothing. Same reasoning as the discovery floors in
    // DocumentedDefaultDriftTest, reached before the failure rather than after it.
    $names = ['clean', 'moderate-only', 'high-in-production', 'enoaudit', 'unrecognised-shape'];

    foreach ($names as $name) {
        $path = npmAuditFixture($name);

        expect(is_file($path))->toBeTrue('fixture is missing: '.$path);
        expect(json_decode((string) file_get_contents($path), true))
            ->toBeArray('fixture is not a JSON object: '.$path);
    }

    $onDisk = glob(base_path('tests/fixtures/npm-audit').'/*.json') ?: [];

    expect(count($onDisk))->toBe(
        count($names),
        'The fixture directory holds '.count($onDisk).' file(s) and the controls name '.count($names).
        '. A fixture nothing asserts is a shape nobody has decided about.'
    );
});

it('judges a clean production tree as clean, and says what it measured', function (): void {
    [$status, $output] = npmAuditJudge('clean');

    expect($status)->toBe(NPM_AUDIT_JUDGE_OK, $output);
    expect($output)->toContain('JUDGED CLEAN');

    // ⛔ The tally is asserted, not just the verdict. A judge that printed "clean" without having read
    // a count map would pass a verdict-only assertion, which is the vacuous half of this gate.
    expect($output)->toContain('high=0');
    expect($output)->toContain('critical=0');
});

it('does NOT block on moderate advisories, because the scope decision was locked with the user', function (): void {
    // The threshold is high/critical in PRODUCTION deps and its policy is user-ratified
    // (PROGRESS_ARCHIVE.md:544, commit 7154d5f / PR #61). M72 fixed the conflation and moved NOTHING
    // about the scope; this case is what stops a later increment quietly tightening it.
    [$status, $output] = npmAuditJudge('moderate-only');

    expect($status)->toBe(NPM_AUDIT_JUDGE_OK, $output);
    expect($output)->toContain('moderate=1');
    expect($output)->toContain('JUDGED CLEAN');
});

it('blocks on a high advisory in production dependencies, and names the package', function (): void {
    [$status, $output] = npmAuditJudge('high-in-production');

    expect($status)->toBe(NPM_AUDIT_JUDGE_BLOCKED, $output);
    expect($output)->toContain('BLOCKED');

    // Naming the package is what makes the red actionable instead of a count. Asserting it here is
    // also what turns a mutation of npm_audit_blocking_packages() red rather than silently cosmetic.
    expect($output)->toContain('axios');
    expect($output)->toContain('high');
});

it('reports an unreachable registry as CANNOT MEASURE rather than as a vulnerability', function (): void {
    // This is the defect the whole increment is about: the same exit code for an outage and a finding.
    [$status, $output] = npmAuditJudge('enoaudit');

    expect($status)->toBe(NPM_AUDIT_JUDGE_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(NPM_AUDIT_JUDGE_BLOCKED, 'an outage must not read as a blocking advisory');
    expect($output)->toContain('CANNOT MEASURE');
    expect($output)->toContain('ENOAUDIT');
});

it('reports a report shape it does not understand as CANNOT MEASURE, never as clean', function (): void {
    // ⛔ THE DISCRIMINATOR. This fixture carries NEITHER a severity count map NOR an `error` key. A
    // judge keying negatively — "no `error`, therefore clean" — passes every other case in this file
    // and fails only this one. If this case is ever weakened, the gate reverts to the defect it was
    // built to remove, one layer up and harder to see.
    [$status, $output] = npmAuditJudge('unrecognised-shape');

    expect($status)->toBe(NPM_AUDIT_JUDGE_CANNOT_MEASURE, $output);
    expect($status)->not->toBe(NPM_AUDIT_JUDGE_OK, 'an unrecognised shape must never be judged clean');
    expect($output)->toContain('CANNOT MEASURE');
});

it('refuses an empty or missing report instead of reading it as a clean tree', function (): void {
    // npm writes nothing to stdout when it cannot reach the endpoint at all, so "empty" is the outage.
    // The succeeds-on-empty-input family this project has now measured five times.
    [$missingStatus, $missingOutput] = npmAuditRun([base_path('tests/fixtures/npm-audit/no-such-file.json')]);

    expect($missingStatus)->toBe(NPM_AUDIT_JUDGE_CANNOT_MEASURE, $missingOutput);

    $empty = tempnam(sys_get_temp_dir(), 'npm-audit-empty');
    file_put_contents($empty, '');

    try {
        [$emptyStatus, $emptyOutput] = npmAuditRun([$empty]);

        expect($emptyStatus)->toBe(NPM_AUDIT_JUDGE_CANNOT_MEASURE, $emptyOutput);
        expect($emptyOutput)->toContain('empty');
    } finally {
        @unlink($empty);
    }
});

it('refuses an unrecognised option, and refuses it even beside --help', function (): void {
    // getopt() silently discards what it does not recognise and cannot report the discard, so the
    // refusal reads $argv. Three scripts in scripts/ have that shape with a DESTRUCTIVE default.
    [$status, $output] = npmAuditRun(['--bogus', npmAuditFixture('clean')]);

    expect($status)->toBe(NPM_AUDIT_JUDGE_CANNOT_MEASURE, $output);
    expect($output)->toContain('unrecognised option');

    // The --help arm sits AFTER the refusal deliberately, so a typo cannot be laundered into exit 0.
    [$bothStatus] = npmAuditRun(['--help', '--bogus']);

    expect($bothStatus)->toBe(NPM_AUDIT_JUDGE_CANNOT_MEASURE, 'a bad flag beside --help must still refuse');

    [$helpStatus, $helpOutput] = npmAuditRun(['--help']);

    expect($helpStatus)->toBe(NPM_AUDIT_JUDGE_OK, $helpOutput);
    expect($helpOutput)->toContain('Usage:');
});

it('is wired into ci.yml as a fetch step and a judge step, with the bash -e fence intact', function (): void {
    // ⚠️ A STRUCTURAL ARM, PAIRED WITH THE BEHAVIOURAL ONES ABOVE — M43's lesson is that a structural
    // gate alone can be fully green and entirely decorative, so this asserts the three things that
    // SILENTLY revert the design in the workflow rather than in the judge:
    //   (1) the fetch step must be continue-on-error, or npm's own exit 1 kills the job before the
    //       judge ever runs and the conflation is back;
    //   (2) GitHub's default shell is `bash -e`, so without the set +e / set -e fence the judge's
    //       non-zero aborts the step before `code=$?` is read — and the design reverts to a hard block
    //       that no test would notice;
    //   (3) --audit-level must NOT come back onto the npm command line: the threshold lives in the
    //       judge precisely so it is drivable by a mutation.
    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->toContain('npm audit --json --omit=dev');
    expect($workflow)->toContain('scripts/npm-audit-judge.php');
    expect($workflow)->toContain('continue-on-error: true');
    expect($workflow)->toContain('set +e');
    expect($workflow)->toContain('set -e');

    // The production-only scope is the half that was locked with the user and must not drift.
    expect($workflow)->toContain('--omit=dev');

    // ⛔ THE THRESHOLD FLAG MUST NOT COME BACK ONTO THE npm COMMAND LINE — but this is asserted over
    // EXECUTABLE lines only, never over the whole file. The comment block above that step has to
    // NAME the old command to explain the defect, and a whole-file `not->toContain` would therefore
    // be red on arrival against its own explanation. That is the M42 shape exactly: a machine token
    // carried in prose. The gate reads what runs; the prose is free to describe what no longer does.
    $executable = array_values(array_filter(
        explode("\n", $workflow),
        static fn (string $line): bool => str_contains($line, 'npm audit')
            && ! str_starts_with(ltrim($line), '#')
    ));

    expect($executable)->not->toBe([], 'no executable npm audit line found — this arm is scanning nothing');

    foreach ($executable as $line) {
        expect($line)->not->toContain(
            '--audit-level',
            'the severity threshold belongs in scripts/npm-audit-judge.php, where a mutation can drive '.
            'it, and not on a command line nothing can test: '.trim($line)
        );
    }
});
