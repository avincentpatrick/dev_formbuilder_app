<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scoped Content-Security-Policy for the map-bearing pages (Increment G5b2 / ADR-0006 D3): the guest form
 * runtime (`GET /f/{slug}`) and the manual-encode page (`GET /forms/{form}/submissions/create`). It sets ONLY
 * the `img-src` directive — allowlisting `self` + inline `data:`/`blob:` URLs + the configured OpenStreetMap
 * tile origins ({@see config('geo.tile_csp_origins')}) — so the Leaflet map may load remote raster tiles while
 * every other image origin is blocked.
 *
 * Deliberately img-src-only: leaving `default-src`/`script-src`/`connect-src` unset keeps the Vite dev-server
 * HMR bundle, the inline Inertia bootstrap, and the SPA's same-origin API calls working in dev and prod. A
 * broader policy is a separate hardening concern, out of this increment's scope.
 */
final class PublicRuntimeSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Don't clobber a policy another layer already set (defensive — nothing else sets CSP today).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->imgSrcPolicy());
        }

        return $response;
    }

    /** Build the single `img-src` directive from the configured tile origins. */
    private function imgSrcPolicy(): string
    {
        $origins = array_values(array_filter(
            (array) config('geo.tile_csp_origins', []),
            static fn ($origin): bool => is_string($origin) && $origin !== '',
        ));

        $sources = array_merge(["'self'", 'data:', 'blob:'], $origins);

        return 'img-src '.implode(' ', $sources);
    }
}
