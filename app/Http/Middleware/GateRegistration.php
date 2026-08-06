<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\RegistrationGate;
use Closure;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes `/register` when signup is not open (Increment I5, PRD Feature #10).
 *
 * ⚠️ IT IS MOUNTED ON `config/fortify.php`'s `middleware` ARRAY, WHICH APPLIES TO **EVERY** FORTIFY ROUTE.
 * That is why the first thing it does is check the path and pass everything else straight through. Fortify
 * has no per-route middleware hook — {@see Features::registration()} registers both the GET and the POST
 * with the config-level stack — so a route-scoped mount is not available, and forgetting the guard below
 * would lock every user out of `/login`, `/forgot-password` and the 2FA challenge at once.
 *
 * ── 404, NEVER 403 ─────────────────────────────────────────────────────────────────────────────────────
 * The same non-disclosure posture as {@see EnsureSuperAdmin} and the guest runtime's three failure gates:
 * a 403 on `acme.meridian.test/register` confirms that a workspace called "acme" exists and is
 * invitation-only, which is precisely the pair of facts a stranger probing subdomains is after. A 404 says
 * only "there is nothing here".
 *
 * BOTH VERBS, and the POST arm is the one that matters: hiding the link and the form while leaving the
 * handler reachable would be a UI change dressed up as a control.
 */
final class GateRegistration
{
    public function __construct(private readonly RegistrationGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('register')) {
            return $next($request);
        }

        if (! $this->gate->allows($request)) {
            abort(404);
        }

        return $next($request);
    }
}
