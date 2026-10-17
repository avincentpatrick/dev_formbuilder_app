<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M176 (`R-a11cc78a`, `D143`, `D144`) — deploy.ps1 backs up and counts the rows around its migration.
|--------------------------------------------------------------------------
| Read from the script itself, as `BuildRetentionTest` and `DeploySkipAllowlistTest` read it: the script runs only
| on the testing server, so what CI can pin is its shape — which step comes before which, and what each failure
| does. The behaviour was proved by a PowerShell 5.1 harness with a stub `php` (refusal → the site back up and no
| migrate; a drop → the site left down), the `BuildRetentionTest` precedent; the first real run is merge point 2's.
*/

function deployScript(): string
{
    return (string) file_get_contents(base_path('deploy.ps1'));
}

/** Where `$needle` first appears in deploy.ps1, after `$from` — failing loudly when it is absent. */
function deployStep(string $needle, int $from = 0): int
{
    $at = strpos(deployScript(), $needle, $from);
    expect($at)->not->toBeFalse("deploy.ps1 no longer contains: {$needle}");

    return (int) $at;
}

it('backs up and counts the rows once the site is down, and before anything is replaced', function (): void {
    $window = deployStep('# 5. The maintenance window.');
    $down = deployStep("@('artisan', 'down'", $window);
    $backup = deployStep("@('artisan', 'deploy:backup'", $window);
    $census = deployStep("@('artisan', 'deploy:census', \"--write=", $window);
    $reset = deployStep("'reset', '--hard', \$sha", $window);

    expect($down)->toBeLessThan($backup)
        ->and($backup)->toBeLessThan($census)
        ->and($census)->toBeLessThan($reset);
});

it('refuses to migrate when the backup or the count fails, bringing the site back up on the old code', function (): void {
    $script = deployScript();
    $backup = deployStep("@('artisan', 'deploy:backup'");
    $reset = deployStep("'reset', '--hard', \$sha");
    $guard = substr($script, $backup, $reset - $backup);

    expect($guard)->toContain('catch')
        ->and($guard)->toContain("Invoke-Native php @('artisan', 'up')")
        ->and($guard)->toContain('Refused to migrate');
});

it('compares the rows after migrating, inside the window, so a drop leaves the site down', function (): void {
    $migrate = deployStep("@('artisan', 'migrate', '--force')");
    $compare = deployStep("@('artisan', 'deploy:census', \"--compare=", $migrate);
    $completed = deployStep('$windowCompleted = $true', $migrate);

    expect($compare)->toBeLessThan($completed);
});

it('leaves the docs-only path without a window, a backup or a count', function (): void {
    $docsOnly = deployStep('# 2b.');
    $window = deployStep('# 5. The maintenance window.');

    expect(substr(deployScript(), $docsOnly, $window - $docsOnly))->not->toContain('deploy:backup');
});
