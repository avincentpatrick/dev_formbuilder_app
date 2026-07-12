<?php

declare(strict_types=1);

use App\Http\Middleware\PublicRuntimeSecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Increment G5b2 — the scoped map CSP (ADR-0006 D3). The middleware sets an
| img-src-only Content-Security-Policy allowlisting the configured OSM tile
| origins so the Leaflet map may load remote tiles, without restricting the
| script/connect directives the Vite bundle + Inertia bootstrap need.
|--------------------------------------------------------------------------
*/

it('sets an img-src CSP allowlisting self, data/blob, and the configured tile origins', function (): void {
    $middleware = new PublicRuntimeSecurityHeaders;

    $response = $middleware->handle(
        Request::create('/f/demo'),
        fn (): Response => new Response('ok'),
    );

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toStartWith('img-src ')
        ->and($csp)->toContain("'self'")
        ->and($csp)->toContain('data:')
        ->and($csp)->toContain('blob:')
        ->and($csp)->toContain('https://*.tile.openstreetmap.org')
        // img-src ONLY — script/connect are deliberately left unrestricted (dev HMR + Inertia).
        ->and($csp)->not->toContain('script-src')
        ->and($csp)->not->toContain('default-src');
});

it('does not clobber a Content-Security-Policy another layer already set', function (): void {
    $middleware = new PublicRuntimeSecurityHeaders;

    $response = $middleware->handle(
        Request::create('/f/demo'),
        function (): Response {
            $response = new Response('ok');
            $response->headers->set('Content-Security-Policy', "default-src 'none'");

            return $response;
        },
    );

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});
