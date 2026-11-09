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
| ⛔ M232 — THE DEPTH ARM READS THE SECRET-SCANNING JOB'S OWN CHECKOUT, NEVER THE WHOLE FILE. It used
| to assert that `ci.yml` contains the key and its value, and a comment here said that token occurred
| exactly once. `M192` added a second job that checks out with full history and `M214` a third, so
| the arm stayed green with the scan's own job bounded: measured with `mutate.php`, a depth of 2
| there left every case here passing (`R-473558cf`). The job is found by the step it runs, not by
| its id, so renaming it cannot blind the arm either. `tests/Support/WorkflowText.php` is the reader.
|
| ⚠️ ITS MUTATION TOKEN IS TWO LINES NOW, NOT ONE. `mutate.php` refuses a token that occurs more than
| once, and the key alone does. The comment line directly above the key in that job, plus the key, is
| unique, so the job's own depth stays drivable without editing `ci.yml` to make it so. A third case
| that kept the fence's message free of the key went with this: it existed only to keep a one-line
| token unique, and the arm now reads nothing outside the checkout step, so a message quoting the key
| can neither blind the arm nor block its proof.
*/

use Tests\Support\WorkflowText;

/**
 * The secret-scanning job's text: the one job that runs the gitleaks step. Read per-case rather than in a
 * `beforeEach` so a case can be run alone.
 */
function secretScanJob(): string
{
    $workflow = WorkflowText::read('.github/workflows/ci.yml');

    return WorkflowText::job($workflow, WorkflowText::jobRunning($workflow, 'Secret scan (gitleaks)'));
}

it('checks out the secret-scanning job with unbounded history', function (): void {
    // ⛔ THE MUTATION TARGET. `php scripts/mutate.php --file=.github/workflows/ci.yml`, bounding THIS
    // job's depth, must turn this case red; that is the only thing that proves this file is a gate
    // rather than a description. Bounding another job's depth must leave it green.
    $checkout = WorkflowText::executable(WorkflowText::checkout(secretScanJob()));

    preg_match_all('/^\s*fetch-depth:\s*(\S+)\s*$/m', $checkout, $depths);

    expect($depths[1])->toBe(
        ['0'],
        'the secret-scanning job must check out with full history, declared once: gitleaks reads git '.
        'HISTORY, so a bounded clone reports no leaks while blind. Its checkout reads: '.$checkout
    );
});

it('fences the secret scan on clone shape, comparing the printed string rather than the exit status', function (): void {
    // ⛔ EXECUTABLE LINES ONLY, AND THIS ARM LEARNED IT THE HARD WAY ON ITS FIRST RUN. The step's
    // comment block has to NAME the scan command to explain why the fence exists, so an ordering
    // check over the raw body found `gitleaks detect` inside prose at offset 152 and concluded the
    // fence ran after the scan. That is the sixth instance of this repository's token-in-prose trap
    // and the second inside this very file — `ci.yml` records the --audit-level one fourteen lines
    // above the step being asserted here. A gate reads what RUNS; the prose is free to describe it.
    $executable = WorkflowText::executable(WorkflowText::step(secretScanJob(), 'Secret scan (gitleaks)'));

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

    //     And it says why, or CI fails with no explanation.
    expect($executable)->toContain('::error');

    // (4) The fence must run BEFORE the scan, or it fences nothing.
    $fenceAt = strpos($executable, 'is-shallow-repository');
    $scanAt = strpos($executable, 'gitleaks detect');
    expect($fenceAt)->toBeLessThan(
        $scanAt,
        'the clone-shape fence runs AFTER the scan, so the scan has already reported on a blind clone'
    );
});
