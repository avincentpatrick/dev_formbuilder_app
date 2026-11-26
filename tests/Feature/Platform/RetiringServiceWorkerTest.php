<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| M253 (`R-43038865`, `D236`) — the worker the portal answers `/sw.js` with
|--------------------------------------------------------------------------
| A form opened at the old address installed this app's worker there, and a browser replaces a worker only with one
| fetched from the same URL — never through a redirect. So the portal serves `public/sw-retire.js` at `/sw.js`. It must
| empty the old caches, remove itself, reload its pages into the redirect, and serve nothing in the meantime.
*/

function retiringWorker(): string
{
    return (string) file_get_contents(public_path('sw-retire.js'));
}

it('takes over at once, empties every cache, removes itself and reloads its pages', function (): void {
    $source = retiringWorker();

    expect($source)->toContain('self.skipWaiting()')
        ->and($source)->toContain('caches.keys()')
        ->and($source)->toContain('caches.delete(key)')
        ->and($source)->toContain('self.registration.unregister()')
        ->and($source)->toContain('client.navigate(client.url)');
});

it('answers no request itself, so every one reaches the network and the redirect', function (): void {
    // Comments stripped: the docblock names the event it deliberately does not handle.
    $code = (string) preg_replace('#/\*.*?\*/#s', '', retiringWorker());

    expect($code)->not->toContain("addEventListener('fetch'")
        ->and($code)->not->toContain('respondWith');
});
