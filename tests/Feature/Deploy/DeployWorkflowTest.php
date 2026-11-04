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

/*
| M210 (`D199`): BHW's own settings, from repository secrets. Three files must name the same six settings —
| ops/write-settings.ps1's allowlist and the env of both workflows that run it — or a secret is set in
| GitHub and silently never reaches the box, or a name reaches the script that it was never meant to write.
*/

/** The settings ops/write-settings.ps1 may write, as its `$Settings = @(...)` declares them. */
function bhwSettingsAllowlist(): array
{
    $script = (string) file_get_contents(base_path('apps/bhw-connect/ops/write-settings.ps1'));
    expect(preg_match('/^\$Settings\s*=\s*@\(([^)]*)\)/mu', $script, $m))->toBe(1);
    preg_match_all("/'([A-Z0-9_]+)'/", $m[1], $names);

    return $names[1];
}

/** The BHW_SETTING_<NAME>: ${{ secrets.BHW_<NAME> }} pairs a workflow file maps, as NAME => secret name. */
function bhwSettingsMapped(string $workflow): array
{
    $text = (string) file_get_contents(base_path('.github/workflows/'.$workflow));
    preg_match_all('/^\s+BHW_SETTING_([A-Z0-9_]+):\s*\$\{\{\s*secrets\.([A-Z0-9_]+)\s*\}\}\s*$/mu', $text, $m, PREG_SET_ORDER);

    $mapped = [];
    foreach ($m as [, $name, $secret]) {
        $mapped[$name] = $secret;
    }

    return $mapped;
}

it('maps exactly the six BHW settings, each from its own BHW_ secret, in both workflows', function (): void {
    $allowed = bhwSettingsAllowlist();

    expect($allowed)->toEqual(['GEMINI_API_KEY', 'GROQ_API_KEY', 'OPENROUTER_API_KEY', 'MISTRAL_API_KEY', 'CRON_SECRET', 'USAGE_EVENTS_IP_SALT']);

    foreach (['deploy.yml', 'bhw-settings.yml'] as $workflow) {
        $mapped = bhwSettingsMapped($workflow);
        expect(array_keys($mapped))->toEqual($allowed, "{$workflow} maps a different set of BHW settings");
        foreach ($mapped as $name => $secret) {
            expect($secret)->toBe('BHW_'.$name);
        }
    }
});

it('writes the settings before BHW deploys, and restarts it when one changed', function (): void {
    $bhw = deployWorkflowSteps()[1];

    $write = strpos($bhw, '& "$app\apps\bhw-connect\ops\write-settings.ps1"');
    $deploy = strpos($bhw, '& "$app\apps\bhw-connect\ops\deploy-bhw.ps1" -AppPath $app -RestartIfSkipped:($changed -gt 0)');
    expect($write)->not->toBeFalse()
        ->and($deploy)->not->toBeFalse()
        ->and($write)->toBeLessThan($deploy);
});

it('applies the settings on demand without ever deploying Meridian', function (): void {
    $text = (string) file_get_contents(base_path('.github/workflows/bhw-settings.yml'));

    // Run by hand only, inside the deploy's own concurrency group, on the box.
    expect(preg_match('/^on:\s*\n\s+workflow_dispatch:\s*$/mu', $text))->toBe(1);
    expect(preg_match('/^\s+(push|pull_request|workflow_run|schedule):/mu', $text))->toBe(0);
    expect($text)->toContain('group: production-deploy')
        ->and($text)->toContain('runs-on: self-hosted')
        ->and($text)->toContain('-RestartIfSkipped:($changed -gt 0)');

    // deploy.ps1 moves the checkout; this workflow must only ever use the commit it last moved to.
    expect($text)->not->toContain('deploy.ps1" -AppPath')
        ->and($text)->not->toContain('\deploy.ps1');
});

it('takes the BHW dev copy on demand, deploying nothing and keeping the upload a day', function (): void {
    // M211 (`bhw-p4-loop`, `D207`): the schema, its migration version and a made-up seed, from the box's
    // database, which only the runner can read. It must never become a deploy, and what it uploads is
    // what a pull request then commits to this public repository, so it lives a day and no longer.
    $text = (string) file_get_contents(base_path('.github/workflows/bhw-dev-snapshot.yml'));

    expect(preg_match('/^on:\s*\n\s+workflow_dispatch:\s*$/mu', $text))->toBe(1);
    expect(preg_match('/^\s+(push|pull_request|workflow_run|schedule):/mu', $text))->toBe(0);
    expect($text)->toContain('runs-on: self-hosted')
        ->and($text)->toContain('& "$app\apps\bhw-connect\ops\dev-snapshot.ps1" -AppPath $app')
        ->and($text)->toContain('retention-days: 1');
    expect($text)->not->toContain('deploy.ps1')
        ->and($text)->not->toContain('deploy-bhw.ps1')
        ->and($text)->not->toContain('write-settings.ps1');
});
