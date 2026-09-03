<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceTenantTwoFactorOnFortify;
use Illuminate\Routing\Route as RoutingRoute;

/*
|--------------------------------------------------------------------------
| Increment M68 — which Fortify routes the org-2FA gate covers, and which are out BY DECISION.
|--------------------------------------------------------------------------
| `M66` closed the `/api/v1` token mint and filed the row that says the mint was never the only way past
| `EnforceTenantTwoFactor`: the Fortify group serves tenant subdomains and carried no org-2FA gate at all,
| so an unenrolled member under `security.require_two_factor` — bounced from every page of their workspace
| — could still `PUT /user/profile-information` and `PUT /user/password`.
|
| ⛔ WHY A COVERAGE GATE AND NOT JUST THE BEHAVIOURAL CASES. Fortify has no per-route middleware hook, so
| the gate selects its own routes from a list inside a class. A list is exactly the artefact that goes
| stale in a vendor upgrade: a new Fortify write route inherits SILENCE — no gate, and no test that knows
| it exists. This file discovers every live Fortify route and requires each write route to be either
| gated or in a named `UNGATED_BY_DECISION` list, so the answer to "was this route considered?" is always
| yes-or-red and never absent. That is the shape `FortifyRateLimitTest` established for the rate limiters,
| and this is a second caller of its discovery helper — now shared in `tests/Pest.php` rather than copied.
|
| ⚠️ THE PAIRING (M43). The behavioural cases live in `TenantTwoFactorEnforcementTest` and prove the gate
| REFUSES; this file proves the gate's SCOPE is a decision rather than an accident. They fail on different
| mutations: unmount the middleware from `config/fortify.php` and the behavioural cases redden while the
| complement here stays green; move a route between the two lists and this reddens while the behavioural
| cases stay green. Neither is evidence for the other.
|
| ⚠️ NO DATABASE AND NO HTTP. It reads the route table and two class constants, so it is equally correct on
| the host and in the container, and it cannot be made to pass by seeding anything.
*/

/**
 * Every Fortify write route deliberately left OUTSIDE the org-2FA gate, and why.
 *
 * ⛔ THREE CARVE-OUTS, AND THE BACKLOG ROW NAMED ONE. Each is load-bearing, and gating any of them turns
 * the enforcement policy into a lockout:
 *
 *  · `two-factor.*` — the enrolment routes are the only way to SATISFY the gate. This is the one the row
 *    names, and `EnforceTenantTwoFactor`'s docblock calls it the whole design.
 *  · `logout` — that same docblock names it in terms: *"a Fortify route in its own group, so it is
 *    naturally outside this gate — do not 'tidy' it inside"*, because "enrol or leave" needs two doors.
 *  · `password.confirm.store` — `config/fortify.php` enables `twoFactorAuthentication(['confirmPassword'
 *    => true])`, so enrolment runs THROUGH the step-up. Measured on the live route table, where
 *    `two-factor.enable` carries `Illuminate\Auth\Middleware\RequirePassword`. Gating this closes the
 *    first carve-out one step further back, which is why reading the row alone was not enough.
 *  · the guest routes (`login.store`, `register.store`, `password.email`, `password.update`) have no
 *    authenticated user at all, so the gate would no-op on them; they are listed rather than special-cased
 *    so that a reader can see they were considered.
 *  · `verification.send` — an unverified, unenrolled member must be able to ask for their verification
 *    mail, or they deadlock against `verified`, which the app applies FIRST.
 */
const FORTIFY_TWO_FACTOR_UNGATED_BY_DECISION = [
    'login.store',
    'logout',
    'register.store',
    'password.email',
    'password.update',
    'password.confirm.store',
    'verification.send',
    'two-factor.login.store',
    'two-factor.enable',
    'two-factor.confirm',
    'two-factor.disable',
    'two-factor.regenerate-recovery-codes',
];

/**
 * A plausible floor for the discovery pass.
 *
 * Measured on the tree this gate was written against: fourteen Fortify write routes. Below that, the
 * discovery is broken rather than the vendor thinner, and a loop over nothing reports success — the shape
 * `CLAUDE.md` records for every gate that scans a tree.
 */
const FORTIFY_TWO_FACTOR_MIN_WRITE_ROUTES = 10;

it('accounts for every live Fortify write route, either gated or ungated by decision', function (): void {
    $writeRoutes = array_values(array_filter(
        fortifyRoutes(),
        static fn (RoutingRoute $route): bool => fortifyWriteVerbs($route) !== []
    ));

    expect(count($writeRoutes))->toBeGreaterThanOrEqual(
        FORTIFY_TWO_FACTOR_MIN_WRITE_ROUTES,
        'Discovery floor: only '.count($writeRoutes).' Fortify write route(s) were found. The namespace '.
        'discovery is more likely broken than the vendor thinner, and every assertion below would be '.
        'vacuous.'
    );

    $gated = EnforceTenantTwoFactorOnFortify::gatedRouteNames();

    foreach ($writeRoutes as $route) {
        $name = (string) $route->getName();

        expect(in_array($name, $gated, true) || in_array($name, FORTIFY_TWO_FACTOR_UNGATED_BY_DECISION, true))
            ->toBeTrue(
                "The Fortify write route '{$name}' (".implode('|', fortifyWriteVerbs($route)).' '.$route->uri().
                ') is in neither list. Decide: add it to EnforceTenantTwoFactorOnFortify::gatedRouteNames() '.
                'if an unenrolled member under enforcement must not reach it, or to '.
                'FORTIFY_TWO_FACTOR_UNGATED_BY_DECISION with the reason. A new route must not inherit silence.'
            );
    }
});

it('names no route in both lists, and none that does not exist', function (): void {
    // ⛔ THE ARM THAT CATCHES A LIST GOING STALE IN THE OTHER DIRECTION. The arm above is satisfied by a
    // list that has grown junk: a renamed vendor route leaves a dead entry behind, and a dead entry in the
    // GATED list is a route nobody is gating while the file says otherwise.
    $gated = EnforceTenantTwoFactorOnFortify::gatedRouteNames();
    $ungated = FORTIFY_TWO_FACTOR_UNGATED_BY_DECISION;

    expect(array_intersect($gated, $ungated))->toBe(
        [],
        'These route names are in BOTH lists, so the file contradicts itself: '.
        implode(', ', array_intersect($gated, $ungated))
    );

    $live = array_map(static fn (RoutingRoute $route): string => (string) $route->getName(), fortifyRoutes());

    foreach ([...$gated, ...$ungated] as $name) {
        expect(in_array($name, $live, true))->toBeTrue(
            "'{$name}' is named in a Fortify 2FA coverage list but is not a live route. A vendor upgrade ".
            'that renames a route leaves exactly this behind, and a dead entry in the GATED list means a '.
            'route nobody is gating.'
        );
    }
});

it('carries the gate on the Fortify group at all, so both arms above are not vacuous', function (): void {
    // The control for the whole file: every assertion above is about LISTS, and a list is equally tidy
    // whether or not the middleware is mounted. `config/fortify.php` is the only place that decides.
    /** @var array<int, string> $middleware */
    $middleware = (array) config('fortify.middleware', []);

    expect($middleware)->toContain(EnforceTenantTwoFactorOnFortify::class);

    // And it is genuinely on the routes, not merely in the config array.
    $profile = collect(fortifyRoutes())
        ->first(static fn (RoutingRoute $route): bool => $route->getName() === 'user-profile-information.update');

    expect($profile)->not->toBeNull('the profile-write route is missing from the route table entirely');
    expect($profile->gatherMiddleware())->toContain(EnforceTenantTwoFactorOnFortify::class);
});
