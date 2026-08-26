<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Increment M30 — the rate limiters nothing asserted, and the one that had already broken.
|--------------------------------------------------------------------------
| A named rate limiter is TWO facts that drift apart independently: a closure registered under a string,
| and a `throttle:<string>` alias on a route. Nothing in this repository asserted either half for the
| Fortify limiters, and nothing anywhere asserted that a limiter's KEY actually varies with the thing it
| claims to be keyed on.
|
| ⚠️ THE ROW THAT SCHEDULED THIS FILE WAS RIGHT THAT NOTHING ASSERTS THE TWO FORTIFY LIMITERS AND WRONG
| ABOUT WHY IT MATTERS, SO THE CASES BELOW ARE AIMED AT THE MUTATION THAT SURVIVES RATHER THAN THE ONE IT
| NAMED. Renaming a `RateLimiter::for()` is ALREADY caught, loudly: on this framework version
| `ThrottleRequests::resolveMaxAttempts()` throws `MissingRateLimiterException` for an unregistered name, so
| a rename 500s every login POST and reddens `AuthenticationTest` and `TwoFactorChallengeTest` today. (The
| "resolves to an UNLIMITED PASSTHROUGH" rationale quoted in `SsoLoginWebTest` was true on Laravel <= 9 and
| is false here — filed in the backlog rather than corrected there, because that file is the other lane's.)
|
| What is NOT caught is nulling a `config/fortify.php` limiter name: Fortify `array_filter`s the middleware
| away, and the route simply loses its throttle. For `login` that degrades to Fortify's own
| `EnsureLoginIsNotThrottled` pipeline fallback; for `two-factor` there is NO fallback anywhere — the vendor
| controller counts nothing, the form request counts nothing, and this app registers no `Lockout` listener —
| so `throttle:two-factor` is the ONLY bound on guessing a 6-digit TOTP or a recovery code. That is the
| mutation the binding case exists for, and it is why these assertions read the LIVE ROUTE TABLE rather than
| the config array: reading the config back would only prove the file parses.
*/

/**
 * Every live route whose middleware resolves to `ThrottleRequests` bound to this limiter name.
 *
 * ⚠️ RESOLVED THROUGH THE ROUTER'S OWN ALIAS MAP, never matched on the printed string — `GroupBPolicyGateTest`
 * lost a first draft to exactly that difference. `route:list` PRINTS
 * `Illuminate\Routing\Middleware\ThrottleRequests:guest` while `gatherMiddleware()` RETURNS the declared
 * alias `throttle:guest`, so a check written against the command's output finds nothing and reports every
 * route as unthrottled. Asking the router to resolve keeps this correct whichever spelling a route used.
 *
 * @return list<RoutingRoute>
 */
function routesThrottledBy(string $limiter): array
{
    $aliases = app('router')->getMiddleware();

    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static function (RoutingRoute $route) use ($aliases, $limiter): bool {
            return array_any($route->gatherMiddleware(), static function (mixed $middleware) use ($aliases, $limiter): bool {
                if (! is_string($middleware)) {
                    return false; // a closure or an instance — never a `throttle:` alias
                }

                [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

                return ($aliases[$name] ?? $name) === ThrottleRequests::class && $parameters === $limiter;
            });
        },
    ));
}

/**
 * Invoke a registered limiter exactly as the middleware would, for one route and one token value.
 *
 * ⚠️ THE POINT IS TO ASK THE QUESTION THE DEFECT IS ABOUT — *does this route's traffic share a bucket with
 * everybody else's* — rather than to inspect which parameter name the limiter happens to read. A test that
 * held its own copy of the parameter names would be the paired-list hazard of Standing Rule 7(b-bis) one
 * file later: two lists that must move together, with nothing to make them.
 *
 * @return list<string> the `->by()` value of each `Limit` the closure returns
 */
function limiterKeysFor(string $limiter, RoutingRoute $route, string $tokenValue): array
{
    $uri = (string) preg_replace('/\{[^}]+\}/', $tokenValue, $route->uri());
    $request = Request::create('http://acme.meridian.test/'.ltrim($uri, '/'), 'GET');

    // Bind before resolving. `$request->route('x')` reads the route's bound parameters, and `throttle:` runs
    // ahead of SubstituteBindings, so these stay raw strings exactly as production sees them.
    $route->bind($request);
    $request->setRouteResolver(fn (): RoutingRoute => $route);

    $limits = (RateLimiter::limiter($limiter))($request);

    return array_map(
        static fn (Limit $limit): string => (string) $limit->key,
        is_array($limits) ? array_values($limits) : [$limits],
    );
}

// ── The two Fortify limiters (the row this file closes) ───────────────────────────────────────

it('registers the login and two-factor limiters that config names by string', function (): void {
    // Registration and binding are separate facts that drift apart independently. This is the first of them.
    foreach (['login', 'two-factor'] as $name) {
        expect(RateLimiter::limiter($name))
            ->not->toBeNull("`config/fortify.php` maps a limiter named `{$name}` that nothing registers.");
    }
});

it('binds the Fortify limiters to the two routes they are the only bound on', function (): void {
    // ⚠️ THE MUTATION THIS CATCHES IS NULLING config/fortify.php's `limiters.login` / `limiters.two-factor`.
    // Fortify `array_filter`s the middleware out of the route definition, so the route keeps working and
    // silently loses its bound, and nothing else in the suite notices. The two-factor half is the severe
    // one: no fallback exists anywhere, so an attacker gets unmetered guesses at a 6-digit code.
    expect(array_map(static fn (RoutingRoute $r): string => $r->uri(), routesThrottledBy('login')))
        ->toContain('login');

    expect(array_map(static fn (RoutingRoute $r): string => $r->uri(), routesThrottledBy('two-factor')))
        ->toContain('two-factor-challenge');
});

// ── The guest limiter's key actually varies with the token (the live defect M30 found) ────────

it('gives every guest route its own bucket per token, on every route bound to the limiter', function (): void {
    $routes = routesThrottledBy('guest');

    // ⚠️ NON-VACUITY FIRST. A route walk that finds nothing passes every assertion below it — the exact
    // shape of the still-open backlog row about the two structural lint gates that `exit(0)` on an empty
    // scan. Five routes carry `throttle:guest` as of M30; asserting `>= 2` rather than `=== 5` proves
    // discovery worked without making this a change-detector for every new guest route.
    expect(count($routes))->toBeGreaterThanOrEqual(
        2,
        'Discovery regression: fewer than two routes resolved to ThrottleRequests:guest, so nothing below is measured.',
    );

    foreach ($routes as $route) {
        $first = limiterKeysFor('guest', $route, 'token-aaaaaaaaaaaaaaaa');
        $second = limiterKeysFor('guest', $route, 'token-bbbbbbbbbbbbbbbb');

        // The per-IP arm is IDENTICAL by design (one synthetic client), so the two key SETS must differ in
        // exactly the per-token arm. Comparing whole sets is what keeps this independent of which parameter
        // name the limiter reads and of how many Limits it returns.
        expect($first)->not->toBe(
            $second,
            "Route `{$route->uri()}` is bound to `throttle:guest` but produces the SAME bucket key for two "
            .'different tokens, so all of its traffic shares one deployment-wide limit. The limiter is '
            .'reading a route parameter this route does not declare.',
        );
    }
});

it('never falls back to the guest limiter\'s IP arm on a route that is actually bound to it', function (): void {
    // The fallback in `AppServiceProvider` is a floor for a mismatch nobody caught, not a design: it keeps
    // the worst case per-caller instead of deployment-wide. If a LIVE route reaches it, the per-token limit
    // has quietly become a second per-IP limit, and the case above cannot see that — two IP-keyed arms
    // differ from nothing, so the set comparison passes. This is the half that says so out loud.
    //
    // ⚠️ THE FIRST DRAFT OF THIS CASE WAS `->not->toContain($key, $message)` AND IT WAS VACUOUS, WHICH THE
    // POSITIVE CONTROL CAUGHT AND A GREEN RUN NEVER WOULD HAVE. Pest's `toContain` takes VARIADIC NEEDLES,
    // not a needle and a message — so the explanatory sentence was being asserted as a second thing the
    // array should not contain, and the case stayed green with the real key sitting in it. Prefer an
    // expectation whose signature genuinely ends in a message.
    foreach (routesThrottledBy('guest') as $route) {
        $fellBack = array_any(
            limiterKeysFor('guest', $route, 'token-aaaaaaaaaaaaaaaa'),
            static fn (string $key): bool => str_starts_with($key, 'gtok-ip:'),
        );

        expect($fellBack)->toBeFalse(
            "Route `{$route->uri()}` falls through to the guest limiter's IP fallback: it declares no "
            .'parameter the limiter reads, so its per-token bound has quietly become a second per-IP one.',
        );
    }
});
