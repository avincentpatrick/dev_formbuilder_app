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
        // media-src (G6) + the G8a PWA self-pins for the guest service worker + web manifest.
        ->and($csp)->toContain('media-src')
        ->and($csp)->toContain("worker-src 'self'")
        ->and($csp)->toContain("manifest-src 'self'")
        // I1: framing is EXPLICITLY unrestricted here, because PRD Feature #3 requires the guest runtime to
        // be embeddable on third-party domains. Before I1 the same behaviour held by accident, which is a
        // different thing from a decision. The authenticated app makes the opposite choice — see
        // AppSecurityHeadersTest — and this assertion is what stops a well-meaning hardening PR from
        // "fixing" the asymmetry and silently breaking every embed in the product.
        ->and($csp)->toContain('frame-ancestors *')
        // script/connect/default are deliberately left unrestricted (dev HMR + Inertia + same-origin API).
        ->and($csp)->not->toContain('script-src')
        ->and($csp)->not->toContain('connect-src')
        ->and($csp)->not->toContain('default-src')
        // G11: with no default-src there is nothing for font-src to fall back to, so fonts are
        // unrestricted and the self-hosted dyslexia face loads on the one authenticated page this
        // middleware covers (the manual-encode screen, which renders inside the app shell). Pinned
        // here so a future hardening PR that introduces default-src is forced to add font-src 'self'
        // in the same change rather than silently breaking an accessibility accommodation.
        ->and($csp)->not->toContain('font-src');
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
