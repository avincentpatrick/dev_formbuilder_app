<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Control for the secret scan's clone-shape fence (M77).
|--------------------------------------------------------------------------
| `gitleaks detect --source .` reads git HISTORY. On a shallow clone it therefore reports
| "no leaks found" while blind — a vacuous success on a REQUIRED status check, which is the failure
| family this repository is built around. The measurement is in `.gitleaksignore`'s own header: at a
| bounded depth of 2 the scan saw two commits; unbounded it saw 818 and immediately reported three
| findings, whose commit-scoped fingerprints are still in that file. One of them sits ~600 commits
| back and its text survives only in `PROGRESS_ARCHIVE.md`, so a working-tree scan could not have
| produced it.
|
| ⛔ WHY `R7` IS NOT ALREADY THIS GATE, WHICH IS THE HALF TWO ROWS GOT WRONG. `scripts/tracker-lint.php`
| asserts a clone shape, and `ci.yml`'s own comment claims that makes a depth reduction "fail LOUDLY".
| It does not. R7 is satisfied by ANY depth at or above the PR's commit count plus one — measured
| against a real 7-commit merge by shallow-cloning a scratch mirror at depths 1..10: red at 6, green
| at 7, with the clone holding 17 of 815 commits. The whole range between that floor and full history
| passes R7 and blinds the scan, and on a `push`, `schedule` or `workflow_dispatch` event R7 asserts
| nothing about clone shape at all.
|
| ⛔ THE FENCE MUST COMPARE THE PRINTED STRING, AND THAT IS NOT A STYLE PREFERENCE.
| `git rev-parse --is-shallow-repository` EXITS 0 IN BOTH STATES — it prints `true` or `false`. So
| `if git rev-parse ...; then fail; fi` is always red and `if ! git rev-parse ...` is never red: one
| breaks the trunk, the other is the decorative gate M43 measured. Both were verified by running the
| command against a full clone and against shallow clones at every depth 1..10.
|
| ⚠️ WHY THIS FILE ASSERTS THE CHECKOUT DEPTH AS WELL AS THE FENCE, WHICH LOOKS LIKE THE
| TWO-COPIES-OF-A-FACT DEFECT THIS REPOSITORY GATES ELSEWHERE. The fence catches shallowness at RUN
| time, from any cause, which is the real protection. It cannot be driven by `scripts/mutate.php`,
| because mutate.php drives Pest and nothing else — so without an arm that reddens when the declared
| depth moves, this gate could never be proved by a deliberate defect, and CLAUDE.md is explicit that
| a green gate proves nothing about a gate you have just written. The depth arm exists to be
| mutated. It is the same argument `NpmAuditJudgeTest` records for asserting `--omit=dev`.
|
| ⚠️ The fence's message in `ci.yml` deliberately does NOT spell the checkout key followed by its
| value: `mutate.php` aborts unless its token occurs EXACTLY ONCE in the file, so a fence quoting the
| token it guards would trade this control's provability for a nicer error string. The last case here
| pins that, because it is invisible until the day someone "improves" the wording.
*/

/**
 * The workflow's bytes. Read per-case rather than in a `beforeEach` so a case can be run alone.
 */
function secretScanWorkflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
}

/**
 * The `run:` body of the named step in the named job, as text.
 *
 * Parsed by bracketing on the step's `- name:` line rather than with a YAML parser, because no YAML
 * extension is loaded in the app container and adding one to run a test is a worse trade than a
 * bounded scan. The assertions below all fail closed if the bracketing finds nothing.
 */
function secretScanStepBody(string $stepName): string
{
    $lines = explode("\n", secretScanWorkflow());
    $start = null;

    foreach ($lines as $i => $line) {
        if (str_contains($line, '- name: '.$stepName)) {
            $start = $i;

            break;
        }
    }

    expect($start)->not->toBeNull("no step named '{$stepName}' in ci.yml — this control is scanning nothing");

    $body = [];

    for ($i = $start + 1; $i < count($lines); $i++) {
        // The next step at the same indentation ends this one.
        if (preg_match('/^      - name: /', $lines[$i]) === 1) {
            break;
        }
        $body[] = $lines[$i];
    }

    return implode("\n", $body);
}

it('checks out the secret-scanning job with unbounded history', function (): void {
    // ⛔ THE MUTATION TARGET. `php scripts/mutate.php --file=.github/workflows/ci.yml` swapping this
    // token for a bounded depth must turn this case red; that is the only thing that proves this
    // file is a gate rather than a description. The token occurs exactly once in the workflow, which
    // is what makes it drivable at all.
    expect(secretScanWorkflow())->toContain('fetch-depth: 0');
});

it('fences the secret scan on clone shape, comparing the printed string rather than the exit status', function (): void {
    // ⛔ EXECUTABLE LINES ONLY, AND THIS ARM LEARNED IT THE HARD WAY ON ITS FIRST RUN. The step's
    // comment block has to NAME the scan command to explain why the fence exists, so an ordering
    // check over the raw body found `gitleaks detect` inside prose at offset 152 and concluded the
    // fence ran after the scan. That is the sixth instance of this repository's token-in-prose trap
    // and the second inside this very file — `ci.yml` records the --audit-level one fourteen lines
    // above the step being asserted here. A gate reads what RUNS; the prose is free to describe it.
    $executable = implode("\n", array_filter(
        explode("\n", secretScanStepBody('Secret scan (gitleaks)')),
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#') && trim($line) !== ''
    ));

    // The scan itself must still be the thing that runs.
    expect($executable)->toContain('gitleaks detect --source .');

    // (1) The fence exists at all.
    expect($executable)->toContain('git rev-parse --is-shallow-repository');

    // (2) It compares the OUTPUT, not the exit status. `--is-shallow-repository` exits 0 in both
    //     states, so an exit-status form is either always-red or never-red. Asserting the command
    //     substitution and the `!= "false"` comparison pins the only correct shape.
    expect($executable)->toContain('"$(git rev-parse --is-shallow-repository)" != "false"');

    // (3) It actually fails the job. A fence that only warns is the npm-audit shape D16 exists for,
    //     and here there is no reachability excuse: clone shape is always knowable.
    expect($executable)->toContain('exit 1');

    // (4) The fence must run BEFORE the scan, or it fences nothing.
    $fenceAt = strpos($executable, 'is-shallow-repository');
    $scanAt = strpos($executable, 'gitleaks detect');
    expect($fenceAt)->toBeLessThan(
        $scanAt,
        'the clone-shape fence runs AFTER the scan, so the scan has already reported on a blind clone'
    );
});

it('keeps the fence message free of the token its own mutation proof depends on', function (): void {
    // ⛔ THIS IS THE CASE THAT LOOKS LIKE PEDANTRY AND IS NOT. `scripts/mutate.php` refuses to run
    // unless its old token occurs EXACTLY ONCE in the target file. The natural wording for this
    // fence's error — "must check out with <key>: 0" — puts a second copy of that token in the file
    // and turns the proof from CAUGHT into an abort with no verdict. A future reader improving the
    // message has no way to know that; this case tells them, by name, at the moment they do it.
    $body = secretScanStepBody('Secret scan (gitleaks)');

    $echoLines = array_values(array_filter(
        explode("\n", $body),
        static fn (string $line): bool => str_contains($line, '::error')
    ));

    expect($echoLines)->not->toBe([], 'the fence emits no ::error annotation — CI would fail with no explanation');

    foreach ($echoLines as $line) {
        expect($line)->not->toContain(
            'fetch-depth: 0',
            'this message spells the exact token scripts/mutate.php mutates, so the token now occurs '.
            'twice in ci.yml and mutate.php will ABORT rather than prove this gate. Describe the '.
            'setting instead of quoting it: '.trim($line)
        );
    }
});
