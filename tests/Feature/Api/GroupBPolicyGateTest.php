<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Increment M13 — every Group-B route answers "may THIS member touch THIS resource", or says why it need not.
|--------------------------------------------------------------------------
| `routes/api.php` has stated the rule in prose since G10b: a resource-bound Group-B route carries an
| `ability:` gate (which scopes the TOKEN) AND a `can:` policy gate on the bound resource (which re-checks
| the acting user's real permissions). `ApiAbilities::MANAGE_SCOPES` and `docs/api-specification.md` both
| lean on it explicitly — "a route added to this group without a `can:` gate would break that argument".
|
| ⛔ TWO ROUTES BROKE IT FOR TWO YEARS OF INCREMENTS AND NOTHING SAID SO, BECAUSE THE RULE AS WRITTEN HAD A
| HOLE IN ITS PREMISE. Both `/api/v1/sync` routes take their resource from the BODY or the QUERY rather
| than the URL, so there was no bound model for `can:` to attach to — and the rule, being phrased about
| resource-BOUND routes, simply did not reach them. `sync/manifest` served any form's complete authored
| schema to any holder of `read:forms`; `sync/submissions` created submissions against any form in the
| tenant for any holder of `write:submissions`. That is not a fact about either controller. It is a fact
| about a ROUTE TABLE, which is why the guard against it is this file rather than another case in
| SyncApiTest.
|
| The rule's second half is now stated and enforced: WHEN A ROUTE IS NOT RESOURCE-BOUND THE GATE MOVES INTO
| THE CONTROLLER, IT DOES NOT DISAPPEAR. A route reaching this group with neither a `can:` middleware nor a
| line in the allowlist below reddens here, and the allowlist costs its author a written sentence about why
| their route needs no per-resource question answered.
|
| ⚠️ THE HONEST SCOPE, SO NOBODY READS MORE INTO THIS FILE THAN IS IN IT. It notices a route that ARRIVES
| ungated. It cannot judge whether an allowlisted reason is TRUE — deleting the in-controller gate from
| either sync controller leaves every case here green, because the allowlist entry would still be sitting
| there. What catches that is `SyncApiTest`'s behavioural cases, and the mutation matrix recorded in that
| file is the proof they do. The two guards are complements: one watches the table, the other watches the
| gates the table cannot express.
|
| Scope: Group B only — the token-consumed resource surface. Group A (`api.v1.tokens.*`) is
| session-authenticated and mints rather than reads; Group C (`api.v1.public.*`) is the unauthenticated
| guest runtime, whose authorization is a signed share token and has no `User` to ask a policy about.
*/

/**
 * Routes that legitimately carry no `can:` gate, each with the reason it needs none.
 *
 * ⚠️ A NEW ENTRY HERE IS A DECISION, NOT A FORMALITY. Two of these eight — the sync pair — read exactly
 * like the six above them for as long as they existed, and were wrong the whole time. So the bar is: name
 * the resource the caller can influence, and say who decides access to it and where.
 *
 * @var array<string, string>
 */
const GROUP_B_ROUTES_WITHOUT_A_POLICY_GATE = [
    // No resource but the tenant itself, which is the request's own context — RLS is the whole answer.
    'api.v1.tenant.show' => 'the tenant IS the context; there is no per-instance question',

    // The gallery RLS already scopes: platform-owned templates plus this tenant's own, and nothing else.
    'api.v1.form-templates.index' => 'RLS returns platform + own; no caller-supplied resource',
    // ⚠️ THE PRECEDENT THIS INCREMENT FOLLOWED. Its `form_version_id` arrives in the BODY, exactly like
    // sync/submissions', and FormTemplateApiController answers the per-form question itself with
    // `Gate::forUser($user)->authorize('view', $version->form)`. Same asymmetry, gate not dropped.
    'api.v1.form-templates.store' => 'in-controller Gate::forUser()->authorize(view) on the source form',

    // A library item is authored from explicit scalar attributes; the request names no other resource.
    'api.v1.field-library.index' => 'RLS returns platform + own; no caller-supplied resource',
    'api.v1.field-library.store' => 'authored from explicit attributes; references no other resource',

    // ADR-0020 §D7: every member may read their OWN standing with no permission at all. The named list is
    // the other route, and it carries `can:viewAny,PointAward`.
    'api.v1.gamification.me' => 'ADR-0020 §D7 — own standing, names nobody else',

    // ── The two M13 closed. Both keep an in-controller gate for want of anything to bind to. ──
    'api.v1.sync.manifest' => 'in-controller Gate::forUser()->authorize(view) on the version\'s form (M13)',
    'api.v1.sync.submissions' => 'in-controller per-item Gate::forUser()->allows(create) on each item\'s form (M13)',
];

/**
 * Does this route carry a policy gate — resolved through the router's own alias map rather than matched on
 * the string `can:`?
 *
 * ⚠️ THIS INDIRECTION IS NOT DEFENSIVE, IT IS THE FIRST THING THAT WENT WRONG WRITING THIS FILE.
 * `route:list` PRINTS the resolved class (`Illuminate\Auth\Middleware\Authorize:view,form`), while
 * `gatherMiddleware()` RETURNS the declared alias (`can:view,form`) — and a check written against what the
 * command printed found ZERO gated routes and reported all fifty as ungated. Asking the router to resolve
 * the alias is what makes the check agree with whichever spelling a route actually used, and keeps it
 * correct if a route ever declares the FQCN directly.
 */
function carriesPolicyGate(RoutingRoute $route): bool
{
    $aliases = app('router')->getMiddleware();

    return array_any($route->gatherMiddleware(), static function (mixed $middleware) use ($aliases): bool {
        if (! is_string($middleware)) {
            return false; // a closure or an instance — never a `can:` gate
        }

        $name = explode(':', $middleware, 2)[0];

        return ($aliases[$name] ?? $name) === Authorize::class;
    });
}

/**
 * @return list<RoutingRoute>
 */
function groupBRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static function (RoutingRoute $route): bool {
            $name = (string) $route->getName();

            return str_starts_with($name, 'api.v1.')
                && ! str_starts_with($name, 'api.v1.public.')
                && ! str_starts_with($name, 'api.v1.tokens.');
        },
    ));
}

it('gives every Group-B route a policy gate, or an allowlisted reason it needs none', function (): void {
    $ungated = [];

    foreach (groupBRoutes() as $route) {
        if (! carriesPolicyGate($route) && ! array_key_exists((string) $route->getName(), GROUP_B_ROUTES_WITHOUT_A_POLICY_GATE)) {
            $ungated[] = $route->methods()[0].' '.$route->uri().' ('.$route->getName().')';
        }
    }

    expect($ungated)->toBe([], "Group-B routes with neither a `can:` gate nor an allowlisted reason:\n".implode("\n", $ungated));
});

/*
| Non-vacuity, in BOTH directions — the ClientUuidScopeTest rule. A guard that walks an empty set passes
| forever, and an allowlist that outlives its routes documents history rather than stopping anything.
*/
it('walks the whole Group-B surface rather than an empty set', function (): void {
    expect(count(groupBRoutes()))->toBeGreaterThan(50);
});

it('keeps no stale allowlist entry', function (): void {
    $names = array_map(static fn (RoutingRoute $route): string => (string) $route->getName(), groupBRoutes());

    foreach (array_keys(GROUP_B_ROUTES_WITHOUT_A_POLICY_GATE) as $allowlisted) {
        expect($names)->toContain($allowlisted);
    }
});

/*
| And the allowlist must be an allowlist rather than the whole table: if a future refactor stripped `can:`
| from every route, the first case above would still pass for anything that happened to be listed here.
| This pins that the overwhelming majority of the surface is gated by middleware, so the allowlist stays
| the exception it is described as.
*/
it('gates the great majority of the surface with middleware rather than by exception', function (): void {
    $gated = array_filter(groupBRoutes(), carriesPolicyGate(...));

    expect(count($gated))->toBeGreaterThan(count(GROUP_B_ROUTES_WITHOUT_A_POLICY_GATE) * 4);
});
