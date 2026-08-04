<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Rules\ClaimableDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404s the platform's own surfaces on a host the platform does not own (H22a / ADR-0012).
 *
 * ⚠️ FOUND BY A TEST, NOT BY DESIGN. H22a's routing matrix asserted that /dashboard on a custom host
 * redirects to the central app — and it did not. It redirected to `/login` ON THE CUSTOM HOST, because
 * `auth` is priority-ordered ahead of the tenancy pipeline (the premise CentralHostFallbackTest records),
 * so an unauthenticated request never reaches identification at all. Fortify registers `/login`,
 * `/register` and `/forgot-password` with `'domain' => null`, and routes/web.php serves `/`
 * host-agnostically, so the platform's CREDENTIAL FORM would render on a hostname a tenant controls.
 * That is a phishing surface handed out with the feature, and it is not closed by anything else in the
 * increment: identification middleware never runs on those routes.
 *
 * A PURE STRING CHECK, NO DATABASE ACCESS. It runs on unauthenticated, unidentified requests — the
 * cheapest possible point — and must not become a `domains` lookup, which would put a query in front of
 * every login page render for no gain.
 *
 * The predicate ALLOWS the central host AND its subdomains, which is not incidental: tenant users log in
 * at `acme.meridian.test/login`, so restricting to the apex alone would lock every tenant out. It refuses
 * exactly one class of host — a custom domain — and it is the same equality-plus-dot-boundary predicate
 * {@see InitializeTenancyByPublicHost::isSubdomain()} and {@see ClaimableDomain} use, so what
 * routes, what may be claimed, and what may render a login form cannot drift apart.
 *
 * NOT applied to `/up` (health) or `/sw.js`: both are deliberately host-agnostic build/ops artifacts that
 * carry no identity and no credentials.
 */
final class RequirePlatformHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPlatformHost($request->getHost())) {
            abort(404);
        }

        return $next($request);
    }

    private function isPlatformHost(string $host): bool
    {
        /** @var array<int, string> $centralDomains */
        $centralDomains = (array) config('tenancy.central_domains', []);

        foreach ($centralDomains as $central) {
            if ($host === $central || str_ends_with($host, '.'.$central)) {
                return true;
            }
        }

        return false;
    }
}
