<?php

declare(strict_types=1);

use App\Support\Platform\BuildInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M181 (`R-c7247dbf`, `D124`) — the version a person sees
|--------------------------------------------------------------------------
| A committed `VERSION` file at the repository root names the release (0.9.0 now, 1.0.0 at launch). `APP_VERSION`
| in the environment still overrides it, so an operator can label a hotfix build without a commit; with neither, the
| truthful placeholder `dev` — never a made-up number. The file is read at runtime, so `config:cache` cannot freeze an
| old one into the cached config.
*/

/** A throwaway VERSION file holding `$contents`. */
function buildInfoVersionFile(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'version');
    file_put_contents($path, $contents);

    return $path;
}

it('reads the committed VERSION file when APP_VERSION is not set', function (): void {
    config(['app.version' => null]);

    expect((new BuildInfo(buildInfoVersionFile("0.9.0\n")))->version())->toBe('0.9.0');
});

it('lets APP_VERSION override the file', function (): void {
    config(['app.version' => '0.9.1-hotfix']);

    expect((new BuildInfo(buildInfoVersionFile('0.9.0')))->version())->toBe('0.9.1-hotfix');
});

it('treats an empty APP_VERSION as unset', function (): void {
    config(['app.version' => '']);

    expect((new BuildInfo(buildInfoVersionFile('0.9.0')))->version())->toBe('0.9.0');
});

it('answers dev when there is no VERSION file', function (): void {
    config(['app.version' => null]);

    expect((new BuildInfo(sys_get_temp_dir().'/no-such-version-file'))->version())->toBe('dev');
});

it('answers dev, never the text, when the VERSION file is not a version', function (): void {
    config(['app.version' => null]);

    expect((new BuildInfo(buildInfoVersionFile('banana')))->version())->toBe('dev')
        ->and((new BuildInfo(buildInfoVersionFile('')))->version())->toBe('dev')
        ->and((new BuildInfo(buildInfoVersionFile('1.0')))->version())->toBe('dev');
});

it('ships a VERSION file of three numbers, which is what an unconfigured install reports', function (): void {
    config(['app.version' => null]);
    $committed = trim((string) file_get_contents(base_path('VERSION')));

    expect($committed)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(app(BuildInfo::class)->version())->toBe($committed);
});

it('no longer defaults APP_VERSION to dev in config, which would hide the file', function (): void {
    $source = (string) file_get_contents(config_path('app.php'));

    expect($source)->toContain("'version' => env('APP_VERSION'),")
        ->and($source)->not->toContain("env('APP_VERSION', 'dev')");
});

it('shares the version with every page, a guest page included', function (): void {
    config(['app.version' => null]);
    $committed = trim((string) file_get_contents(base_path('VERSION')));

    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome', false)
            ->where('app.version', $committed));
});
