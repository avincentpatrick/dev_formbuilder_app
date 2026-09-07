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
 * The write routes deliberately left unbound, each with the reason it is not an oversight.
 *
 * `logout` accepts no credential, and throttling it strands somebody in a session they are trying to leave.
 *
 * ⛔ `user-profile-information.update` LEFT THIS LIST IN `M87`, AND THE REASON IT WAS HERE DID NOT SURVIVE
 * BEING CHECKED. It was excluded as a route that verifies no credential — but M43's scope already included
 * two that verify none, `register.store` and `password.email`, and the second is a pure mail dispatcher,
 * which is the exact analogue the exposure was argued from. ⚠️ The "eight credential-bearing routes" figure
 * is itself only true counting distinct URI paths: `two-factor.enable` and `two-factor.disable` share one
 * path, so the map ships nine names on eight paths and three documents repeat "eight" without saying so.
 */
const FORTIFY_UNBOUND_BY_DECISION = ['logout'];

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

/*
 * Increment M87 — THE OTHER DIRECTION OF THE DECIDED-UNBOUND LIST, WHICH WAS NOT ASSERTED AT ALL.
 *
 * ⛔ THE HOLE, STATED AS IT WAS FOUND. The case above reddens when a name is REMOVED from the list while
 * still unbounded. It cannot see the opposite: a name LEFT on the list after the route was bound reads as
 * `$skipped = true`, which only ever suppresses a finding, so the suite stays green while a recorded
 * decision of record says the route is deliberately unprotected. That is not a hypothetical — it is the
 * exact mistake available to the increment that bound `user-profile-information.update`, one edit away
 * from being made, and nothing in this file or in `FortifyTwoFactorCoverageTest` would have caught it.
 *
 * ⚠️ IT IS ASSERTED ON THE ROUTE TABLE, NOT ON THE CONSTANT. A case comparing the constant against a
 * literal would pin today's list and have to be edited every time the list legitimately changes, which is
 * the second-copy hazard this file's header already refuses. What is checked is the PROPERTY: a name on
 * this list must be bound by nothing, or the list is lying about it.
 */
it('keeps the decided-unbound list free of routes that are in fact bound', function (): void {
    $map = ThrottleFortifyEndpoints::limiters();
    $stale = [];

    foreach (fortifyRoutes() as $route) {
        $name = (string) $route->getName();

        if (! in_array($name, FORTIFY_UNBOUND_BY_DECISION, true)) {
            continue;
        }

        if (array_key_exists($name, $map) || fortifyThrottleParams($route) !== []) {
            $stale[] = $name;
        }
    }

    expect($stale)->toBe([], 'a route is recorded as deliberately unbound and is in fact throttled — the decision of record is stale');

    // ⛔ THE FLOOR. `$stale` is empty when the list is honest AND when the loop matched nothing at all —
    // a renamed route, or a `fortifyRoutes()` that stopped resolving. Without this the case above is
    // vacuous and green, which is the shape this repository keeps paying for.
    // ⚠️ AND NOT `toContain($decided, '<message>')`: Pest reads EVERY argument to `toContain` as another
    // needle, so the message becomes a second thing the array must hold and the case fails on its own
    // explanation. Caught by this floor going red on a correct tree the first time it was run.
    $names = array_map(static fn ($route): string => (string) $route->getName(), fortifyRoutes());
    $missing = array_values(array_diff(FORTIFY_UNBOUND_BY_DECISION, $names));

    expect($missing)->toBe([], 'a name on the decided-unbound list matches no live route, so the list is asserting nothing');
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
        // ⚠️ `array_key_exists` rather than `expect($live)->toHaveKey($name, $message)`, and the difference
        // cost a red run here. **A Pest expectation's second argument is not universally a message**:
        // `toHaveKey()`'s is the expected VALUE, so the explanatory sentence was being asserted as the
        // value stored under that key. Same family as the `toContain` trap M30 recorded — except that one
        // stayed GREEN with the wrong value in the array, and this one failed loudly. The lesson is the
        // same and only the luck differed: check the signature, do not assume the slot is for a message.
        expect(array_key_exists($name, $live))->toBeTrue("the map names {$name}, which is not a live Fortify route");
        expect($live[$name] ?? [])->not->toBe([], "the map names {$name}, which is a READ route — the .store trap");
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

/*
 * Increment M87 — `PUT /user/profile-information`, and the reason it takes THREE cases rather than one.
 *
 * The limiter has two arms, and a single "does it 429" case would pass with the name arm deleted, the
 * address arm deleted, or the two swapped. So: the address arm bites, the name arm does not bite at the
 * address arm's ceiling, and the bucket is per-identity. The third is the M30 assertion again — it is
 * cheap and it is the one that has actually caught something in this file.
 */
it('refuses a seventh address change in a minute, and does not spend that bucket on a name change', function (): void {
    $alice = fortifyRateLimitMember();

    // ⚠️ SIX NAME CHANGES FIRST, AT THE ADDRESS ARM'S CEILING. If the two arms shared a bucket — or if the
    // limiter compared nothing and keyed everything alike — the address change below would already be
    // refused, and this case is the only thing in the suite that would notice.
    foreach (range(1, 6) as $i) {
        $this->actingAs($alice)->put('/user/profile-information', [
            'name' => 'Renamed '.$i,
            'email' => $alice->email,
        ])->assertStatus(302);
    }

    $addressChange = static fn (int $i): array => ['name' => 'Alice', 'email' => 'probe'.$i.'@example.test'];

    $first = $this->actingAs($alice)->put('/user/profile-information', $addressChange(1));
    expect($first->getStatusCode())->not->toBe(429, 'the first address change must reach the controller, or this case is vacuous');

    foreach (range(2, 6) as $i) {
        $this->actingAs($alice)->put('/user/profile-information', $addressChange($i));
    }

    // ⛔ THE EXPOSURE, IN ONE REQUEST. Unbounded, this door sends a verification mail to ANY address a
    // caller names, on the queue every other transactional mail shares — and asks a cross-tenant
    // "does this account exist" question on the way, via `Rule::unique('pgsql_auth.users')`.
    $this->actingAs($alice)->put('/user/profile-information', $addressChange(7))->assertStatus(429);
});

it('keeps the profile-information bucket per identity rather than per deployment', function (): void {
    $alice = fortifyRateLimitMember();
    $bob = fortifyRateLimitMember();

    foreach (range(1, 6) as $i) {
        $this->actingAs($alice)->put('/user/profile-information', ['name' => 'Alice', 'email' => 'probe'.$i.'@example.test']);
    }

    $this->actingAs($alice)->put('/user/profile-information', ['name' => 'Alice', 'email' => 'probe7@example.test'])->assertStatus(429);

    // Same minute, same arm, different account: keyed on anything but the identity — `by('')`, or the IP
    // in a test process where every request shares one — this is a 429 and one bucket covers everybody.
    $this->actingAs($bob)->put('/user/profile-information', ['name' => 'Bob', 'email' => 'bob-new@example.test'])->assertStatus(302);
});

it('reads a case-only address edit as the name arm, not as an address change', function (): void {
    // ⚠️ `config/fortify.php` lowercases usernames, but in `ProfileInformationController::update()` — AFTER
    // the middleware — so the limiter must lowercase for itself. Without that, changing `A@x.test` to
    // `a@x.test` spends the tight bucket, and this is the case that says so.
    $alice = fortifyRateLimitMember();
    $shouted = strtoupper($alice->email);

    foreach (range(1, 7) as $ignored) {
        $this->actingAs($alice)->put('/user/profile-information', ['name' => 'Alice', 'email' => $shouted])->assertStatus(302);
    }
});
