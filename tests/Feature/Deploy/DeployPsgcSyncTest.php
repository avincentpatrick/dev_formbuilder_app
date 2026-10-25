<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| M186 (`R-70c7673a`, `D131`) — every deploy copies the committed Philippine places into `psgc_places`
|--------------------------------------------------------------------------
| Read from the script, as `DeployReleaseSyncTest` reads it. The copy runs inside the maintenance window, after the
| migration that creates the table and BEFORE the row count compares — so the count measures the copy as well as the
| migration, and a data file that fails its own checks leaves the site down like any other failed step. A release older
| than the command (a `-Ref` rollback to it) cannot copy, and says so rather than failing.
|
| ⚠️ A CHANGED deploy.ps1 TAKES EFFECT ONE DEPLOY LATE (its own header): the deploy that brings this step in still runs
| the old script, so the first copy on the testing server is the deploy after M186's.
|
| ⚠️ Helpers are prefixed `psgcDeploy*`: Pest loads every test file into one process.
*/

function psgcDeployScript(): string
{
    return (string) file_get_contents(base_path('deploy.ps1'));
}

/** Where `$needle` first appears in deploy.ps1, after `$from` — failing loudly when it is absent. */
function psgcDeployStep(string $needle, int $from = 0): int
{
    $at = strpos(psgcDeployScript(), $needle, $from);
    expect($at)->not->toBeFalse("deploy.ps1 no longer contains: {$needle}");

    return (int) $at;
}

it('copies the places after the migration and before its row count, inside the window', function (): void {
    $migrate = psgcDeployStep("@('artisan', 'migrate', '--force')");
    $sync = psgcDeployStep("@('artisan', 'psgc:sync')", $migrate);
    $compare = psgcDeployStep("@('artisan', 'deploy:census', \"--compare=", $migrate);
    $completed = psgcDeployStep('$windowCompleted = $true', $migrate);

    expect($sync)->toBeLessThan($compare)
        ->and($compare)->toBeLessThan($completed);
});

it('skips the copy, with a warning, on a release that predates the command', function (): void {
    $sync = psgcDeployStep("@('artisan', 'psgc:sync')");
    $guard = strrpos(substr(psgcDeployScript(), 0, $sync), 'if (Test-Path');

    expect($guard)->not->toBeFalse()
        ->and(substr(psgcDeployScript(), (int) $guard, $sync - (int) $guard))
        ->toContain('app\Console\Commands\SyncPsgcCommand.php');

    $else = substr(psgcDeployScript(), $sync, 600);

    expect($else)->toContain('else')
        ->and($else)->toContain('Write-Warning');
});

it('names the command the script runs as one this application has', function (): void {
    expect(is_file(app_path('Console/Commands/SyncPsgcCommand.php')))->toBeTrue()
        ->and(array_key_exists('psgc:sync', Artisan::all()))->toBeTrue();
});
