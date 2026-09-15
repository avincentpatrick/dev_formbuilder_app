<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The deploy window's page (M96, R-8a4c39fb).
|
| `deploy.ps1` opens its maintenance window with `php artisan down --render=deploy-window`. `down`
| PRERENDERS that view into `storage/framework/down`, and `public/index.php` echoes it before it loads
| Composer's autoloader, so a browser that arrives while the script is swapping `vendor/` and
| `public/build` touches neither. That only works if the view can be rendered in two awkward states.
|
|  - WITH NO BUILD AT ALL. A fresh clone's priming run (docs/deployment-infrastructure.md §8.2) has no
|    `public/build` yet, because the directory is gitignored. `DownCommand` renders the view inside its
|    try block and turns any exception into exit 1, so a view that insists on a Vite manifest would stop
|    the very first deploy before its window opened.
|  - WITH NO VIEW DATA. `down` passes only `retryAfter`, so the page cannot depend on `$message`, which is
|    why the ordinary maintenance view cannot be prerendered as it is.
|
| ⛔ NO `withoutVite()` IN THIS FILE, DELIBERATELY. Every other Pest test that renders a blade view needs
| it, and here it would make every case vacuous: the fake answers every method call with an empty string,
| so `Vite::content()` would inline nothing and a view that links `/build/` would still pass. Each case
| points the application's public path at a temporary directory it controls instead: an EMPTY one, or
| one holding a fixture manifest and a fixture stylesheet. The storage path moves too, so the `down` file
| a case writes can never put another test into maintenance mode.
*/

/**
 * Runs `$body` with the application's public and storage paths pointed at new, empty temporary
 * directories, then restores both paths and deletes the directories, however `$body` exits.
 *
 * @param  callable(string, string): void  $body  receives the public path, then the storage path
 */
function withDeployWindowPaths(callable $body): void
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.str_replace('.', '-', uniqid('meridian-deploy-window-', true));
    $public = $root.DIRECTORY_SEPARATOR.'public';
    $storage = $root.DIRECTORY_SEPARATOR.'storage';
    File::ensureDirectoryExists($public);
    File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'framework');

    $app = app();
    $originalPublic = $app->publicPath();
    $originalStorage = $app->storagePath();

    try {
        $app->usePublicPath($public);
        $app->useStoragePath($storage);

        $body($public, $storage);
    } finally {
        $app->usePublicPath($originalPublic);
        $app->useStoragePath($originalStorage);
        File::deleteDirectory($root);
    }
}

/** Writes a one-entry Vite manifest for `resources/css/app.css`, and the stylesheet it points at. */
function writeDeployWindowFixtureBuild(string $public, string $css): void
{
    File::ensureDirectoryExists($public.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets');

    file_put_contents($public.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'manifest.json', json_encode([
        'resources/css/app.css' => [
            'file' => 'assets/app-fixture.css',
            'src' => 'resources/css/app.css',
            'isEntry' => true,
        ],
    ]));

    file_put_contents($public.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'app-fixture.css', $css);
}

it('opens the window with --render=deploy-window when no build exists yet', function (): void {
    withDeployWindowPaths(function (string $public, string $storage): void {
        $downFile = $storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'down';

        expect(is_dir($public.DIRECTORY_SEPARATOR.'build'))->toBeFalse();

        try {
            expect(Artisan::call('down', ['--retry' => '15', '--render' => 'deploy-window']))->toBe(0)
                ->and(is_file($downFile))->toBeTrue();

            $template = json_decode((string) file_get_contents($downFile), true)['template'] ?? null;

            // The prerendered page is the shared maintenance card with the fixed deploy message, and it
            // can reference nothing under /build/: during the window that directory is being swapped.
            expect($template)->toBeString()
                ->and($template)->toContain('maintenance__panel')
                ->and($template)->toContain('We are updating the site')
                ->and($template)->toContain('<style')
                ->and($template)->not->toContain('/build/');
        } finally {
            Artisan::call('up');
        }

        expect(is_file($downFile))->toBeFalse();
    });
});

it('inlines the built stylesheet from the manifest rather than linking /build/', function (): void {
    withDeployWindowPaths(function (string $public, string $storage): void {
        writeDeployWindowFixtureBuild($public, '.deploy-window-fixture-marker{color:inherit}');
        $downFile = $storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'down';

        try {
            expect(Artisan::call('down', ['--retry' => '15', '--render' => 'deploy-window']))->toBe(0);

            $template = json_decode((string) file_get_contents($downFile), true)['template'] ?? '';

            // Inside a <style> element, not merely somewhere in the document.
            expect($template)->toMatch('#<style[^>]*>[^<]*\.deploy-window-fixture-marker\{color:inherit\}#')
                ->and($template)->not->toContain('/build/')
                ->and($template)->not->toContain('<link');
        } finally {
            Artisan::call('up');
        }
    });
});

it('still opens the window when the manifest names a stylesheet that is not on disk', function (): void {
    // A half-written build: the manifest exists, so the manifest check passes, but Vite::content() throws
    // "Unable to locate file from Vite manifest". The page must fall back rather than fail `down`.
    withDeployWindowPaths(function (string $public, string $storage): void {
        writeDeployWindowFixtureBuild($public, '');
        unlink($public.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'app-fixture.css');
        $downFile = $storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'down';

        try {
            expect(Artisan::call('down', ['--retry' => '15', '--render' => 'deploy-window']))->toBe(0);

            $template = json_decode((string) file_get_contents($downFile), true)['template'] ?? '';

            expect($template)->toContain('maintenance__panel')
                ->and($template)->toContain('We are updating the site')
                ->and($template)->not->toContain('/build/');
        } finally {
            Artisan::call('up');
        }
    });
});

it('renders with no view data at all', function (): void {
    withDeployWindowPaths(function (): void {
        $html = view('deploy-window')->render();

        expect($html)->toContain('maintenance__panel')
            ->and($html)->toContain('Temporarily unavailable')
            ->and($html)->toContain('We are updating the site')
            ->and($html)->not->toContain('/build/');
    });
});

it('keeps the maintenance page linking the built stylesheet and escaping its message', function (): void {
    // A CHARACTERIZATION PIN, green before and after M96 by design: the maintenance page's markup moved
    // into a partial it now shares with the deploy window, and the existing maintenance tests all run
    // under withoutVite(), so none of them could see the stylesheet link break or the message lose its
    // escaping in that move.
    withDeployWindowPaths(function (string $public): void {
        writeDeployWindowFixtureBuild($public, '.deploy-window-fixture-marker{color:inherit}');

        $html = view('maintenance', ['message' => 'Back <b>soon</b>.'])->render();

        expect($html)->toContain('build/assets/app-fixture.css')
            ->and($html)->toContain('rel="stylesheet"')
            ->and($html)->toContain('maintenance__panel')
            ->and($html)->toContain('Back &lt;b&gt;soon&lt;/b&gt;.')
            ->and($html)->not->toContain('Back <b>soon</b>.');
    });
});
