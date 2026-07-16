<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Increment G8a — GET /sw.js re-serves the Vite-built guest service worker from the root path so its scope
| can cover /f/ (a /build/ static can't carry Service-Worker-Allowed under artisan serve). It streams
| public/build/sw.js with the headers a service worker needs, and 404s when the app hasn't been built.
|--------------------------------------------------------------------------
*/

function swPath(): string
{
    return public_path('build/sw.js');
}

it('serves the built service worker with service-worker headers', function (): void {
    $path = swPath();
    $existed = File::exists($path);
    $original = $existed ? File::get($path) : null;

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '/* g8a test worker */');

    try {
        $response = $this->get('/sw.js');

        $response->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/f/');

        expect($response->baseResponse->headers->get('Content-Type'))->toContain('text/javascript');
        expect($response->baseResponse->headers->get('Cache-Control'))->toContain('no-cache');
        expect($response->getContent())->toContain('g8a test worker');
    } finally {
        if ($existed) {
            File::put($path, (string) $original);
        } else {
            File::delete($path);
        }
    }
});

it('404s when the app has not been built (no sw.js present)', function (): void {
    $path = swPath();
    $existed = File::exists($path);
    $original = $existed ? File::get($path) : null;

    if ($existed) {
        File::delete($path);
    }

    try {
        $this->get('/sw.js')->assertNotFound();
    } finally {
        if ($existed) {
            File::put($path, (string) $original);
        }
    }
});
