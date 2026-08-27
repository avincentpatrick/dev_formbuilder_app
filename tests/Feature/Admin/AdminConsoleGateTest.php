<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSuperAdminMfa;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;

/*
|--------------------------------------------------------------------------
| Increment M35 — the super-admin console is gated as a SURFACE, not as three route names somebody typed.
|--------------------------------------------------------------------------
| `routes/admin.php` states the policy in prose: every page of the central-host console sits behind
| `auth` + `superadmin` + `superadmin.mfa` + `step-up`, and exactly one route — the 2FA enrollment landing —
| is deliberately outside the inner two, so an un-enrolled operator is not sent to a page they cannot reach.
| Nothing asserted that. What existed was a hand-maintained list of route NAMES in
| `StepUpReauthenticationTest`, prefaced by a comment claiming it covered "every page of the console" and
| naming THREE of the thirteen gated routes, plus per-page behavioural denials for the six pages somebody
| happened to write them for.
|
| ⛔ THE MUTATION BOTH OF THOSE ARE BLIND TO IS THE CHEAP ONE: A ROUTE DECLARED IN THE WRONG GROUP.
| MEASURED BEFORE THIS FILE WAS WRITTEN RATHER THAN ASSERTED AFTERWARDS. Moving
| `admin.feedback.screenshot` out of the inner `superadmin.mfa` + `step-up` group into the outer one — a
| two-line move of the kind a merge makes by accident, confirmed at the live route table to drop both gates
| — left `tests/Feature/{Admin,Auth,Feedback}` at **238 passed / 1,156 assertions**, identical to the
| baseline in both numbers. Not one test in this repository noticed that a route streaming EVERY TENANT'S
| feedback screenshots had become reachable by a super-admin with no confirmed second factor and no recent
| password confirmation.
|
| A hand-list cannot catch that, and the reason is structural rather than a lapse: a route the list does not
| name cannot fail it, and the list is only ever extended by somebody who already remembered. So this file
| DISCOVERS the surface from the route table and asserts the property over whatever it finds. A page added
| to the console tomorrow is gated tomorrow, or this file names it in a failure message.
|
| ⚠️ THE HONEST SCOPE, so nobody reads more into this than is in it. It watches the TABLE: which middleware
| a route arrives carrying, and which host serves it. It cannot judge whether a middleware DOES anything —
| gutting `EnsureSuperAdmin::handle()` leaves every case here green. What catches that is the behavioural
| denials in the per-page console suites (`SuperAdminConsoleTest`, `TenantDetailConsoleTest`,
| `PlatformAuditConsoleTest`, `PlatformSettingsConsoleTest`, `ImpersonationConsoleTest`,
| `FeedbackConsoleTest`), and the alias case at the bottom of this file, which pins that each name still
| resolves to the class those suites exercise. The two guards are complements in the M13 shape: one watches
| the table, the other watches what the table cannot express.
*/

/**
 * Console routes that legitimately sit outside the inner gates, each with the reason it must.
 *
 * ⚠️ AN ENTRY HERE IS A DECISION, NOT A FORMALITY — it is the one supported way to put a page of the
 * super-admin console in front of an operator who has confirmed neither a second factor nor a recent
 * password. The bar is: name what the route can do, and say why doing it without those two is safe.
 *
 * @var array<string, string>
 */
const ADMIN_CONSOLE_ROUTES_OUTSIDE_THE_INNER_GATES = [
    // The enrollment landing itself. Inside `superadmin.mfa` it would redirect to itself; behind `step-up`
    // an un-enrolled operator would confirm a password and be bounced to enrollment having gained nothing,
    // which is how a security control teaches people to click through it. It renders Fortify's own global
    // 2FA setup and can do nothing else. `routes/admin.php:46-48` records the same reasoning at the route.
    'admin.mfa.setup' => 'the 2FA enrollment landing — inside either inner gate it is unreachable by the only operator who needs it',
];

/** A route in a failure message: enough to find it in `routes/admin.php` without opening the test. */
function describeConsoleRoute(RoutingRoute $route): string
{
    return $route->methods()[0].' '.$route->uri().' ('.($route->getName() ?? 'unnamed').')';
}

/**
 * Does this route arrive carrying `$class`, resolved through the router's own alias map?
 *
 * ⚠️ THE INDIRECTION IS NOT DEFENSIVE — it is what `GroupBPolicyGateTest` records paying for. `route:list`
 * PRINTS the resolved class while `gatherMiddleware()` RETURNS the declared alias, so a check written
 * against what the command printed finds nothing at all. Asking the router to resolve the alias makes this
 * agree with whichever spelling a route used, and keeps it correct if one ever declares the FQCN directly.
 */
function consoleRouteCarries(RoutingRoute $route, string $class): bool
{
    $aliases = app('router')->getMiddleware();

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue; // a closure or an instance — never one of the four names below
        }

        if (($aliases[explode(':', $middleware, 2)[0]] ?? $middleware) === $class) {
            return true;
        }
    }

    return false;
}

/**
 * The console routes NOT carrying `$class`, described for a failure message.
 *
 * @return list<string>
 */
function consoleRoutesMissing(string $class, bool $exemptAllowlisted = false): array
{
    $missing = [];

    foreach (adminConsoleRoutes() as $route) {
        if ($exemptAllowlisted && array_key_exists((string) $route->getName(), ADMIN_CONSOLE_ROUTES_OUTSIDE_THE_INNER_GATES)) {
            continue;
        }

        if (! consoleRouteCarries($route, $class)) {
            $missing[] = describeConsoleRoute($route);
        }
    }

    return $missing;
}

it('serves the whole console from the central host and from no other', function (): void {
    // The console reads across every tenant. A console route that lost its domain constraint would also
    // answer on `{slug}.meridian.test`, where the tenant group has already put a tenant's context on the
    // connection — so the blast radius of this one is not "a wrong URL".
    $central = (string) config('tenancy.central_domain');

    $stray = [];

    foreach (adminConsoleRoutes() as $route) {
        if ($route->getDomain() !== $central) {
            $stray[] = describeConsoleRoute($route).' → '.($route->getDomain() ?? 'ANY HOST');
        }
    }

    expect($stray)->toBe([], "Console routes not pinned to the central host:\n".implode("\n", $stray));
});

it('puts every console route behind authentication and the super-admin flag', function (): void {
    // No exemption list here, and the enrollment landing is not one: it is outside the INNER two gates,
    // never outside these. A page that tells an operator how to enroll is still a page of the console.
    $unauthenticated = consoleRoutesMissing(Authenticate::class);
    $ungated = consoleRoutesMissing(EnsureSuperAdmin::class);

    expect($unauthenticated)->toBe([], "Console routes with no `auth`:\n".implode("\n", $unauthenticated));
    expect($ungated)->toBe([], "Console routes with no `superadmin`:\n".implode("\n", $ungated));
});

it('puts every console route behind confirmed 2FA and a fresh password, or names the exception and why', function (): void {
    $unenrolled = consoleRoutesMissing(EnsureSuperAdminMfa::class, exemptAllowlisted: true);
    $unconfirmed = consoleRoutesMissing(RequireRecentPassword::class, exemptAllowlisted: true);

    expect($unenrolled)->toBe([], "Console routes with no `superadmin.mfa` and no allowlisted reason:\n".implode("\n", $unenrolled));
    expect($unconfirmed)->toBe([], "Console routes with no `step-up` and no allowlisted reason:\n".implode("\n", $unconfirmed));
});

/*
| Non-vacuity in both directions — the rule `GroupBPolicyGateTest` states and `ClientUuidScopeTest` before
| it. A guard that walks an empty set passes forever, and an allowlist that outlives its routes documents
| history rather than stopping anything.
*/
it('walks the whole console rather than an empty set', function (): void {
    // Thirteen gated routes plus the enrollment landing, measured from the live table when this was
    // written. Asserted as a FLOOR rather than an equality: a new console page must not redden this file
    // for existing, only for arriving ungated.
    expect(count(adminConsoleRoutes()))->toBeGreaterThan(10);
});

it('discovers the routes this file exists for, rather than a set that happens to be gated', function (): void {
    // The anchors guard the DISCOVERY, not the property. If `adminConsoleRoutes()` ever stopped matching —
    // a prefix change, the route file moved — every forall above would pass over a near-empty set, and the
    // count floor alone would not say which routes went missing. Two anchors: the route that prompted this
    // file, and the page the old hand-list named.
    $names = array_map(static fn (RoutingRoute $route): string => (string) $route->getName(), adminConsoleRoutes());

    expect($names)->toContain('admin.feedback.screenshot');
    expect($names)->toContain('admin.tenants.index');
});

it('keeps no stale exemption', function (): void {
    $names = array_map(static fn (RoutingRoute $route): string => (string) $route->getName(), adminConsoleRoutes());

    foreach (array_keys(ADMIN_CONSOLE_ROUTES_OUTSIDE_THE_INNER_GATES) as $allowlisted) {
        expect($names)->toContain($allowlisted);
    }
});

it('keeps the exemption an exception rather than the policy', function (): void {
    // If a future refactor stripped the inner gates from the console wholesale, the forall above would
    // still pass for anything sitting in the allowlist. This pins that the overwhelming majority of the
    // surface is gated by middleware, so the allowlist stays the single line it is described as.
    $fullyGated = array_filter(
        adminConsoleRoutes(),
        static fn (RoutingRoute $route): bool => consoleRouteCarries($route, EnsureSuperAdminMfa::class)
            && consoleRouteCarries($route, RequireRecentPassword::class),
    );

    expect(count($fullyGated))->toBeGreaterThan(count(ADMIN_CONSOLE_ROUTES_OUTSIDE_THE_INNER_GATES) * 4);
});

it('resolves each console alias to the middleware the console suites exercise', function (): void {
    // Split from the per-route cases deliberately, and it is the half that catches the OTHER cheap
    // mutation: re-pointing one of these names at a permissive class leaves every forall above green,
    // because every route would still carry the name. `StepUpReauthenticationTest` states that reasoning
    // for `step-up` alone; the console has four names and they are worth the same sentence.
    $aliases = app('router')->getMiddleware();

    expect($aliases['auth'] ?? null)->toBe(Authenticate::class);
    expect($aliases['superadmin'] ?? null)->toBe(EnsureSuperAdmin::class);
    expect($aliases['superadmin.mfa'] ?? null)->toBe(EnsureSuperAdminMfa::class);
    expect($aliases['step-up'] ?? null)->toBe(RequireRecentPassword::class);
});
