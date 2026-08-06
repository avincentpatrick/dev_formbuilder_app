<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every surface that carries an authenticated session (Increment I1).
 *
 * Before this, the application had NO app-side security-headers middleware at all: the only CSP in the tree
 * was {@see PublicRuntimeSecurityHeaders}, scoped to four capture-bearing routes, and the only other security
 * headers anywhere were two inline `nosniff`s on the attachment and branding-logo responses. I1 had to decide
 * `frame-ancestors` for the public runtime, and deciding it in one direction while leaving the surface that
 * actually holds a session undecided would have been answering the easier half of the question.
 *
 * ── THE ASYMMETRY IS THE DESIGN ────────────────────────────────────────────────────────────────────
 * The public guest runtime sets `frame-ancestors *` because PRD Feature #3 requires third-party embedding.
 * This middleware sets `frame-ancestors 'none'` because the admin app is the exact inverse: a real session,
 * destructive actions one click deep, and no reason anyone would ever frame it legitimately.
 *
 * ── MOUNTED PER GROUP, NEVER GLOBALLY ON `web` ─────────────────────────────────────────────────────
 * A global mount would land on the guest runtime — which also uses `web` — and break every embed in the
 * product. So it is named on four groups explicitly: the authenticated tenant app, the invitation/branding
 * group, the central platform pages, and Fortify's auth routes (where clickjacking a login form is the
 * classic case). Adding a fifth surface is an edit here plus an edit at the route; that is the intended cost.
 *
 * ── ⚠️ THE MERGE, AND WHY IT IS NOT DEFENSIVE PADDING ──────────────────────────────────────────────
 * `PublicRuntimeSecurityHeaders` is ALSO mounted on `GET /forms/{form}/submissions/create` — the manual-encode
 * page, which is authenticated (so it gets this middleware) and map-bearing (so it gets that one). It is the
 * one route in the app where both apply.
 *
 * That middleware refuses to clobber an existing `Content-Security-Policy`. So if this one were to WRITE the
 * header first, its guard would trip and the OpenStreetMap `img-src` allowlist would never be emitted — and
 * the failure mode is that the map tiles silently stop loading, with no error in any log, on one page, behind
 * a middleware whose name mentions neither maps nor images. Route middleware runs inside group middleware, so
 * the ordering happens to be right today; relying on that would make a blank map a refactor away.
 *
 * Therefore: MERGE into whatever policy is already present rather than skipping when one exists. Composing is
 * correct in both orders, and `AppSecurityHeadersTest` pins the combined header on that exact route so the
 * property is asserted rather than reasoned about. For the same reason this class is NOT added to
 * `bootstrap/app.php`'s middleware priority list — naming a middleware there is load-bearing (see the note at
 * `InitializeTenancyByPublicHost`'s registration), and it would silently reorder both.
 *
 * ── WHAT IT DELIBERATELY DOES NOT SET ──────────────────────────────────────────────────────────────
 * No `default-src`, `script-src` or `connect-src`. Inertia bootstraps inline and Vite serves HMR from another
 * origin in dev; introducing `default-src` here would break both and force every directive to be enumerated
 * at once, which `docs/piping-output-encoding-design.md` §262 records as its own hardening increment.
 * `frame-ancestors` is safe to state alone precisely because it has no `default-src` fallback.
 */
final class AppSecurityHeaders
{
    /** The one directive this middleware contributes. Kept separate so the merge can look for it by name. */
    private const FRAME_ANCESTORS = "frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(
            'Content-Security-Policy',
            $this->withFrameAncestors($response->headers->get('Content-Security-Policy')),
        );

        // Legacy belt-and-braces: browsers that honour CSP ignore this, and browsers that predate
        // `frame-ancestors` honour this. DENY rather than SAMEORIGIN — the app frames nothing of its own.
        $response->headers->set('X-Frame-Options', 'DENY');

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Send the full URL only same-origin; cross-origin navigations leak the origin alone. Keeps tenant
        // subdomains and form ids out of third-party referer logs without breaking same-site analytics.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * Merge `frame-ancestors 'none'` into an existing policy, keeping every other directive and REPLACING
     * any `frame-ancestors` already present.
     *
     * ⚠️ Replacing rather than deferring is the correction a test forced, and the bug it found was real.
     * The first draft left an existing `frame-ancestors` untouched, on the reasonable-sounding theory that a
     * policy which had already decided framing knew better. It does not — because the only middleware that
     * sets one is {@see PublicRuntimeSecurityHeaders}, whose `frame-ancestors *` is scoped to the GUEST
     * runtime but which is ALSO mounted on the authenticated manual-encode page for its map allowlist. So
     * deferring handed the guest runtime's "anyone may frame this" to a logged-in page: a clickjacking hole
     * on an authenticated surface, opened by the very change meant to close one.
     *
     * This middleware is mounted only on surfaces that carry a session. On those, `'none'` is the answer
     * regardless of what an earlier layer said, and a laxer inherited value is by definition an accident of
     * middleware sharing rather than a decision about this route. Two `frame-ancestors` directives in one
     * header is undefined behaviour, so the old one is dropped rather than appended to.
     */
    private function withFrameAncestors(?string $policy): string
    {
        if ($policy === null || trim($policy) === '') {
            return self::FRAME_ANCESTORS;
        }

        $kept = array_values(array_filter(
            array_map(trim(...), explode(';', $policy)),
            static fn (string $directive): bool => $directive !== ''
                && ! str_starts_with(strtolower($directive), 'frame-ancestors'),
        ));

        $kept[] = self::FRAME_ANCESTORS;

        return implode('; ', $kept);
    }
}
