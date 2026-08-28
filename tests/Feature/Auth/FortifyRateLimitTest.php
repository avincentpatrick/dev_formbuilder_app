<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleFortifyEndpoints;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M43 — the Fortify write routes Fortify itself leaves unmetered.
|--------------------------------------------------------------------------
| The backlog row named ONE endpoint, `POST /user/confirm-password`. The live route table has fourteen
| Fortify write routes and Fortify ships `throttle:` on three of them, so the row was a floor: three of
| the uncovered ones — `POST /forgot-password`, `POST /reset-password` and `POST /register` — need no
| session at all.
|
| ⚠️ WHY THIS IS A SECOND FILE AND NOT FOUR MORE CASES IN `RateLimiterBindingTest`. That file's whole
| thesis is that a named limiter is two facts — a registration and a `throttle:<name>` ROUTE-LEVEL ALIAS —
| and its `routesThrottledBy()` helper is alias resolution by construction. The mechanism here is a third
| thing: a middleware on `config/fortify.php`'s group array holding a route-name => limiter map, because
| Fortify has no per-route hook and there is nowhere to hang an alias. That helper cannot see this and
| should not be taught to; folding a third mechanism into a file with a sharp argument blunts it.
|
| ⚠️⚠️ THE STRUCTURAL CASES AND THE BEHAVIOURAL CASES MEASURE GENUINELY DIFFERENT FACTS, AND THE PROOF IS
| A CONTROL RATHER THAN A CLAIM: replacing the delegation in `ThrottleFortifyEndpoints::handle()` with a
| bare `return $next($request)` leaves EVERY structural case green and reddens all four behavioural ones.
| A map-only gate would be decorative — it would assert that a lookup table has the right shape while the
| routes it names went unthrottled.
|
| The map is READ FROM THE MIDDLEWARE, never mirrored here. A second hand-maintained copy of nine route
| names is the paired-artefact hazard of Standing Rule 7(b-bis) reproduced one file later, and the whole
| point of the coverage case is that a route this file has never heard of still reddens it.
*/

/**
 * The two write routes deliberately left unbound, each with the reason it is not an oversight.
 *
 * `logout` accepts no credential, and throttling it strands somebody in a session they are trying to
 * leave. `user-profile-information.update` IS a real exposure — it nulls `email_verified_at` and sends a
 * verification mail on every address change, so it is a second mail cannon aimed at arbitrary recipients —
 * and it is out because this increment's scope was set at the eight credential-bearing routes. It is filed
 * as its own backlog row rather than left here as a silent gap.
 */
const FORTIFY_UNBOUND_BY_DECISION = ['logout', 'user-profile-information.update'];

/**
 * Every live Fortify route, discovered by the NAMESPACE of its controller.
 *
 * Discovery rather than a list: a route added by a vendor upgrade appears here without this file knowing
 * its name, which is the property `AdminConsoleGateTest` was built around. The prefix is derived from a
 * ::class constant rather than written as a string literal, so it cannot be misspelt and cannot drift.
 *
 * @return list<RoutingRoute>
 */
function fortifyRoutes(): array
{
    $prefix = substr(ConfirmablePasswordController::class, 0, -strlen('ConfirmablePasswordController'));

    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static function (RoutingRoute $route) use ($prefix): bool {
            $uses = $route->getAction('uses');

            return is_string($uses) && str_starts_with($uses, $prefix);
        },
    ));
}

/**
 * The verbs on a route that can change state. HEAD is an artefact of registering GET.
 *
 * @return list<string>
 */
function fortifyWriteVerbs(RoutingRoute $route): array
{
    return array_values(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
}

/**
 * The `throttle:` parameters already declared on a route, resolved through the router's own alias map.
 *
 * ⚠️ NEVER MATCHED ON THE PRINTED STRING. `route:list` prints the resolved CLASS while `gatherMiddleware()`
 * returns the declared ALIAS, so a check written against the command's output reports every route as
 * unthrottled. `RateLimiterBindingTest` records the same lesson; this is a second caller of it.
 *
 * @return list<string>
 */
function fortifyThrottleParams(RoutingRoute $route): array
{
    $aliases = app('router')->getMiddleware();
    $found = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue; // a closure or an instance — never a `throttle:` alias
        }

        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

        if (($aliases[$name] ?? $name) === ThrottleRequests::class) {
            $found[] = $parameters;
        }
    }

    return $found;
}

/**
 * An ACTIVE member of the seeded workspace.
 *
 * Named apart from `FortifyRouteContextTest`'s `fortifyMember` deliberately: a Pest helper is a global
 * function, so a redeclaration is a fatal rather than a shadow.
 */
function fortifyRateLimitMember(): User
{
    $user = User::factory()->create();
    enterTenant(test()->tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    return $user;
}

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

// ── Discovery must not be able to collapse silently ─────────────────────────────────────────────────

it('discovers the Fortify route table rather than trusting a list', function (): void {
    $all = fortifyRoutes();
    $writes = array_values(array_filter($all, fn (RoutingRoute $r): bool => fortifyWriteVerbs($r) !== []));

    // Floors, not equalities: a vendor upgrade may legitimately add a route, and this file's job is to
    // notice that rather than to pin a version. What it must never do is discover nothing and pass.
    expect(count($all))->toBeGreaterThanOrEqual(24, 'Fortify route discovery collapsed — every case below is vacuous')
        ->and(count($writes))->toBeGreaterThanOrEqual(12, 'Fortify write-route discovery collapsed');

    // Two anchors: the route the backlog row named, and one of the three that needs no session.
    $names = array_map(fn (RoutingRoute $r): ?string => $r->getName(), $writes);
    expect($names)->toContain('password.confirm.store');
    expect($names)->toContain('password.email');
});

// ── Coverage, as an equality in both directions ────────────────────────────────────────────────────

it('bounds every Fortify write route exactly once, by alias or by map', function (): void {
    $map = ThrottleFortifyEndpoints::limiters();
    $uncovered = [];
    $doubled = [];

    foreach (fortifyRoutes() as $route) {
        if (fortifyWriteVerbs($route) === []) {
            continue;
        }

        $name = (string) $route->getName();
        $aliased = fortifyThrottleParams($route) !== [];
        $mapped = array_key_exists($name, $map);
        $skipped = in_array($name, FORTIFY_UNBOUND_BY_DECISION, true);
        $label = implode('|', fortifyWriteVerbs($route)).' /'.$route->uri().' ('.$name.')';

        if (! $aliased && ! $mapped && ! $skipped) {
            $uncovered[] = $label;
        }

        // ⚠️ THE OTHER DIRECTION, AND IT IS THE ONE THAT KEEPS `/login` HONEST. Fortify already binds
        // `throttle:login` there; adding `login.store` to the map would stack a second bucket on it and
        // halve whichever ceiling an operator thought they had set. That is the defect this repository
        // recorded on `guest-challenge`, so it is asserted rather than left to the fall-through's good
        // behaviour.
        if ($aliased && $mapped) {
            $doubled[] = $label;
        }
    }

    expect($uncovered)->toBe([], 'a Fortify write route is bounded by nothing and is not on the decided-unbound list');
    expect($doubled)->toBe([], 'a Fortify write route is throttled twice — once by alias and once by the map');
});

it('names only live write routes in the map', function (): void {
    // ⛔ THE CASE THAT CATCHES THE `.store` TRAP. Fortify's write routes carry a suffix its view routes do
    // not: `register.store`, `password.confirm.store` and `two-factor.regenerate-recovery-codes`, against
    // `register`, `password.confirm` and `two-factor.recovery-codes`. A map keyed on the obvious-looking
    // name throttles three GET pages the axe suite scans, leaves three endpoints open, and every
    // behavioural case below still passes — because the pages are not what anything posts to.
    $live = [];

    foreach (fortifyRoutes() as $route) {
        if ($route->getName() !== null) {
            $live[$route->getName()] = fortifyWriteVerbs($route);
        }
    }

    foreach (array_keys(ThrottleFortifyEndpoints::limiters()) as $name) {
        expect($live)->toHaveKey($name, "the map names {$name}, which is not a live Fortify route");
        expect($live[$name])->not->toBe([], "the map names {$name}, which is a READ route — the .store trap");
    }
});

it('registers every limiter the map names', function (): void {
    // Two facts that drift independently, and on this framework version the drift is loud rather than
    // silent: `ThrottleRequests::resolveMaxAttempts()` throws `MissingRateLimiterException` for an
    // unregistered name, so a rename 500s the route instead of quietly unthrottling it.
    foreach (array_unique(ThrottleFortifyEndpoints::limiters()) as $limiter) {
        expect(RateLimiter::limiter($limiter))->not->toBeNull("RateLimiter::for('{$limiter}') is not registered");
    }

    // The alias identity the coverage case resolves through. If `throttle` ever stops resolving to this
    // class, `fortifyThrottleParams()` silently returns nothing and every route reads as unaliased.
    expect(app('router')->getMiddleware()['throttle'])->toBe(ThrottleRequests::class);
});

// ── The refusal actually happens, and it is keyed per identity ──────────────────────────────────────

it('refuses a sixth password-reset request in a minute, and not the same person at a different address', function (): void {
    Notification::fake();

    $body = ['email' => 'reset-target@authtest.local'];

    // The ceiling is 5/min on lower(email)|ip. Five pass — whether or not the address exists, because the
    // throttle runs long before the broker looks anything up.
    foreach (range(1, 5) as $ignored) {
        $this->post('/forgot-password', $body)->assertStatus(302);
    }

    $this->post('/forgot-password', $body)->assertStatus(429);

    // ⚠️ THE KEY INCLUDES THE ADDRESS, AND ONLY THIS ASSERTION CAN SAY SO. Keyed on the IP alone, one
    // enumerating script would lock every legitimate reset for everyone sharing its address — a control
    // whose main effect under attack is denying service to real users.
    $this->post('/forgot-password', ['email' => 'someone-else@authtest.local'])->assertStatus(302);
});

it('refuses a sixth registration attempt in a minute from one address', function (): void {
    // Deliberately invalid bodies: this case is about the bucket, not about registration, and an empty
    // payload keeps it fast and creates no users. ThrottleRequests counts the request either way — it runs
    // ahead of validation, and ahead of GateRegistration's 404.
    $this->post('/register', [])->assertStatus(302);

    foreach (range(2, 5) as $ignored) {
        $this->post('/register', []);
    }

    $this->post('/register', [])->assertStatus(429);

    // A different address is a different bucket. The hourly arm (20) is nowhere near tripping at seven.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/register', [])->assertStatus(302);
});

it('refuses an eleventh password confirmation in a minute, and never bleeds across users', function (): void {
    $alice = fortifyRateLimitMember();
    $bob = fortifyRateLimitMember();

    $this->actingAs($alice)->post('/user/confirm-password', ['password' => 'password'])->assertStatus(302);

    foreach (range(2, 10) as $ignored) {
        $this->actingAs($alice)->post('/user/confirm-password', ['password' => 'password']);
    }

    $this->actingAs($alice)->post('/user/confirm-password', ['password' => 'password'])->assertStatus(429);

    // ⚠️⚠️ THE ASSERTION THAT MATTERS MOST IN THIS FILE. Same address, same minute, different account.
    // Keyed on anything but the identity — an empty `by('')`, or the IP — this is a 429 and the whole
    // deployment shares one bucket for the redemption door of its own step-up gate. That is the M30 defect
    // verbatim, and the only reason it is caught here is that this request exists.
    $this->actingAs($bob)->post('/user/confirm-password', ['password' => 'password'])->assertStatus(302);
});

it('refuses a sixth two-factor confirmation in a minute', function (): void {
    // The sharpest of the seven: a SIX-DIGIT secret whose only other bound is nothing at all — the vendor
    // controller counts nothing, no form request counts, and this app registers no Lockout listener. Five
    // wrong codes a minute is the difference between a ~139-day exhaustion and an afternoon.
    $user = fortifyRateLimitMember();
    confirmPasswordNow();

    $this->actingAs($user)->post('/user/two-factor-authentication')->assertSessionHasNoErrors();

    $guess = ['code' => '000000'];

    $first = $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', $guess);
    expect($first->getStatusCode())->not->toBe(429, 'the first guess must reach the controller, or this case is vacuous');

    foreach (range(2, 5) as $ignored) {
        $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', $guess);
    }

    $this->actingAs($user)->post('/user/confirmed-two-factor-authentication', $guess)->assertStatus(429);
});
