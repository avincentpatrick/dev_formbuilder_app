<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| M181 (`R-c7247dbf`, `D125` C) — every deploy copies the What's new notes into the `releases` table
|--------------------------------------------------------------------------
| Read from the script, as `DeployDataGuardTest` reads it. The copy runs inside the maintenance window, after the
| migration that creates the table and after the row count has compared it, so a malformed notes file leaves the
| site down like any other failed step rather than live with notes that disagree with what shipped. A release older
| than the command (a `-Ref` rollback to it) cannot copy, and says so rather than failing.
|
| ⚠️ A CHANGED deploy.ps1 TAKES EFFECT ONE DEPLOY LATE (its own header): the deploy that brings this step in still
| runs the old script, so the first copy on the testing server is the deploy after M181's.
*/

function releaseSyncScript(): string
{
    return (string) file_get_contents(base_path('deploy.ps1'));
}

/** Where `$needle` first appears in deploy.ps1, after `$from` — failing loudly when it is absent. */
function releaseSyncStep(string $needle, int $from = 0): int
{
    $at = strpos(releaseSyncScript(), $needle, $from);
    expect($at)->not->toBeFalse("deploy.ps1 no longer contains: {$needle}");

    return (int) $at;
}

it('copies the notes after the migration and its row count, inside the window', function (): void {
    $migrate = releaseSyncStep("@('artisan', 'migrate', '--force')");
    $compare = releaseSyncStep("@('artisan', 'deploy:census', \"--compare=", $migrate);
    $sync = releaseSyncStep("@('artisan', 'releases:sync')", $migrate);
    $completed = releaseSyncStep('$windowCompleted = $true', $migrate);

    expect($compare)->toBeLessThan($sync)
        ->and($sync)->toBeLessThan($completed);
});

it('skips the copy, with a warning, on a release that predates the command', function (): void {
    $sync = releaseSyncStep("@('artisan', 'releases:sync')");
    $guard = strrpos(substr(releaseSyncScript(), 0, $sync), 'if (Test-Path');

    expect($guard)->not->toBeFalse()
        ->and(substr(releaseSyncScript(), (int) $guard, $sync - (int) $guard))
        ->toContain('app\Console\Commands\SyncReleasesCommand.php');

    $else = substr(releaseSyncScript(), $sync, 600);

    expect($else)->toContain('else')
        ->and($else)->toContain('Write-Warning');
});

it('names the command the script runs as one this application has', function (): void {
    expect(is_file(app_path('Console/Commands/SyncReleasesCommand.php')))->toBeTrue()
        ->and(array_key_exists('releases:sync', Artisan::all()))->toBeTrue();
});
