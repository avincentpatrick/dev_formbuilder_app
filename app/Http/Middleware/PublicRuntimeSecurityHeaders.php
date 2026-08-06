<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scoped Content-Security-Policy + Permissions-Policy for the capture-bearing pages (Increment G5b2 / G6):
 * the guest form runtime (`GET /f/{slug}`) and the manual-encode page (`GET /forms/{form}/submissions/create`).
 *
 * Five CSP directives:
 *  - `img-src` (G5b2 / ADR-0006 D3) — allowlists `self` + inline `data:`/`blob:` + the configured OpenStreetMap
 *    tile origins ({@see config('geo.tile_csp_origins')}) so the Leaflet map may load remote raster tiles + a
 *    captured photo's `blob:` preview, while every other image origin is blocked.
 *  - `media-src` (G6) — `self` + `data:`/`blob:` so a captured audio/video answer plays back from its local
 *    `blob:` URL; every other media origin is blocked.
 *  - `worker-src 'self'` + `manifest-src 'self'` (G8a) — the guest PWA registers a same-origin service worker
 *    (re-served from `/sw.js`) and links a same-origin web manifest (`/f/{slug}/manifest.webmanifest`);
 *    pinning both to `self` is defensive hardening (with `default-src` unset they'd otherwise be unrestricted).
 *  - `frame-ancestors *` (Increment I1) — see below.
 *
 * ── `frame-ancestors *` IS A DECISION, NOT AN OVERSIGHT ────────────────────────────────────────────
 * Until I1 this header set no `frame-ancestors` at all, which produced the right behaviour for the wrong
 * reason: the guest runtime was embeddable because nothing had ever considered whether it should be. It is
 * now stated, because PRD Feature #3 REQUIRES it — "the form is embeddable via iframe or script snippet on
 * third-party domains", and the parity matrix scopes the MVP to exactly that iframe. The whole reason this
 * runtime is a standalone Vue SPA rather than an Inertia page (technical-architecture §7) is that it has to
 * survive being framed by someone else.
 *
 * So the clickjacking risk here is ACCEPTED, not overlooked. What makes that tolerable is what the framed
 * page can do: it holds no authenticated session (`SESSION_DOMAIN` is null and guests have no account), its
 * only privileged act is submitting the very form the embedder is displaying on purpose, and its tenant
 * context comes from a signed token rather than an ambient cookie. A narrower policy — a per-form allowlist
 * of embed origins — is the honest upgrade path and is recorded on the backlog; it is not achievable by
 * tightening this constant, because the set is per-form data, not deployment config.
 *
 * The authenticated app makes the OPPOSITE choice, in {@see AppSecurityHeaders}: `frame-ancestors 'none'`.
 * That asymmetry is the point — the surface with a session refuses framing, the surface without one permits
 * it — and the two middlewares meet on exactly one route, which is why AppSecurityHeaders merges rather
 * than replaces. See its docblock.
 *
 * `default-src`/`script-src`/`connect-src` are deliberately left unset (the Vite HMR bundle, the inline Inertia
 * bootstrap, and the SPA's same-origin API calls need them open) — a broader policy is a separate hardening
 * concern, and `PublicRuntimeSecurityHeadersTest` asserts their ABSENCE so that introducing `default-src`
 * forces every other directive to be enumerated in the same change. `frame-ancestors` does not disturb that
 * tripwire: it has no `default-src` fallback, so adding it restricts nothing else.
 *
 * The Permissions-Policy grants `camera`/`microphone` to `self` so the native `<input capture>` (and a
 * future in-page getUserMedia recorder) keeps working when the form is embedded in an iframe (Increment G6).
 */
final class PublicRuntimeSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Don't clobber a policy another layer already set (defensive — nothing else sets CSP today).
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self)');
        }

        return $response;
    }

    /**
     * Build the `img-src` (tiles + blob preview) + `media-src` (blob audio/video playback) directives, plus
     * the G8a `worker-src`/`manifest-src` self-pins for the guest PWA and I1's explicit `frame-ancestors`.
     * `img-src` stays first.
     */
    private function contentSecurityPolicy(): string
    {
        $origins = array_values(array_filter(
            (array) config('geo.tile_csp_origins', []),
            static fn ($origin): bool => is_string($origin) && $origin !== '',
        ));

        $imgSrc = 'img-src '.implode(' ', array_merge(["'self'", 'data:', 'blob:'], $origins));
        $mediaSrc = "media-src 'self' data: blob:";
        $pwaSrc = "worker-src 'self'; manifest-src 'self'";
        $frameAncestors = 'frame-ancestors *';

        return $imgSrc.'; '.$mediaSrc.'; '.$pwaSrc.'; '.$frameAncestors;
    }
}
