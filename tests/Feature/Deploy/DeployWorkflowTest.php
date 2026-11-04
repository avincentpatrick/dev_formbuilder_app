<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The deploy workflow's two steps, read from `.github/workflows/deploy.yml` (M209, ADR-0024 §D6).
|--------------------------------------------------------------------------
| Since `bhw-p1-box`, every green CI run on `main` deploys Meridian and then BHW Connect from the same
| checkout. Nothing else in the suite reads this file, so a step dropped, reordered, or given an `if:`
| would change what reaches the testing site with every gate still green. The order is the safety
| argument: Meridian's `deploy.ps1` resets the checkout and goes live first, and BHW's step, carrying no
| `if:`, runs only when that succeeded — so a BHW failure can never stop or roll back Meridian.
|
| ⚠️ A STATIC READING. What the steps DO on the box is proved by the deploy runs recorded in
| `docs/claims/lane-a.md` for M209: the runner's first BHW build, a BHW-only push, and a rollback.
*/

/** The deploy job's steps as text, one entry per `- name:`, in order. */
function deployWorkflowSteps(): array
{
    $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
    $steps = preg_split('/^      - name: /mu', $workflow);

    expect($steps)->toBeArray();
    array_shift($steps); // everything before the first step

    return array_values($steps);
}

it('deploys Meridian first and BHW Connect second, from the same app path', function (): void {
    $steps = deployWorkflowSteps();

    expect($steps)->toHaveCount(2, 'the deploy job should hold exactly two steps');
    expect($steps[0])->toContain('& "$app\deploy.ps1" -AppPath $app');
    expect($steps[1])->toContain('& "$app\apps\bhw-connect\ops\deploy-bhw.ps1" -AppPath $app');

    // Both resolve the live directory the same way, from the same repository variable.
    foreach ($steps as $step) {
        expect($step)->toContain('MERIDIAN_APP_PATH: ${{ vars.MERIDIAN_APP_PATH }}');
    }
});

it('runs BHW Connect only after Meridian succeeded, and never forever', function (): void {
    $bhw = deployWorkflowSteps()[1];

    // No `if:` means "only when every earlier step succeeded". `always()` or `failure()` would run BHW's
    // deploy after a Meridian deploy that stopped half way, against a checkout in an unknown state.
    expect(preg_match('/^\s+if:/mu', $bhw))->toBe(0);
    expect(preg_match('/^\s+continue-on-error:\s*true/mu', $bhw))->toBe(0);
    expect(preg_match('/^\s+timeout-minutes:\s*\d+/mu', $bhw))->toBe(1);
});

it('keeps production deploys from overlapping', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));

    expect($workflow)->toContain('group: production-deploy')
        ->and($workflow)->toContain('cancel-in-progress: false');
});
