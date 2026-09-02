<?php

declare(strict_types=1);

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Reflector;

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
 * Does this route carry a policy gate at all?
 *
 * ⚠️ THE PARSE MOVED TO `tests/Pest.php` IN M63, AND THE REASON IS THE DEFECT THIS FILE USED TO CARRY.
 * The body here was `explode(':', $middleware, 2)[0]` — element [1], the entire `<ability>,<Subject>`
 * payload, was computed and thrown away. That made every assertion below a statement about the PRESENCE
 * of a gate and none of them a statement about what it NAMES, so a gate pointed at the wrong model
 * satisfied all four. `policyGates()` keeps the payload; this stays a bool because the four cases below
 * genuinely only ask "is one there".
 */
function carriesPolicyGate(RoutingRoute $route): bool
{
    return policyGates($route) !== [];
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

/*
|--------------------------------------------------------------------------
| M63 — the gate's PAYLOAD, not merely its presence
|--------------------------------------------------------------------------
| Everything above asks "is a gate there". Everything below asks "does the gate
| it found say something coherent". The two are genuinely different questions,
| and the gap between them is where a real defect lived: `can:viewAny,X` and
| `can:viewAny,Y` are indistinguishable to the four cases above.
|
| ⚠️ THE HONEST SCOPE, AND IT IS THE WHOLE REASON `GroupBGateSubjectTest`
| EXISTS ALONGSIDE THIS FILE. None of DC1–DC6 can see the defect M63 was
| opened for. Swapping `viewAny,SavedReportView` for `viewAny,Submission`
| passes every one of them IDENTICALLY — both classes exist, both policies are
| registered, both implement `viewAny`, both are arity-1. These checks catch a
| gate that is MALFORMED or IMPOSSIBLE. They cannot catch one that is
| well-formed and aimed at the wrong audience; that needs the permission sets
| in the sibling file, which is why neither is sufficient alone.
|
| All six are derived from the live router and the live container. There is no
| declaration to keep in sync, and nothing here touches the database.
*/

/** @return list<array{route: string, gate: array{ability: string, subjects: list<string>, declared: string}}> */
function groupBGatePairs(): array
{
    $pairs = [];

    foreach (groupBRoutes() as $route) {
        foreach (policyGates($route) as $gate) {
            $pairs[] = ['route' => (string) $route->getName(), 'gate' => $gate, 'object' => $route];
        }
    }

    return $pairs;
}

/** A subject is a class string exactly when `Authorize::isClassName()` says so — `str_contains($v, '\\')`. */
function subjectIsClassName(string $subject): bool
{
    return str_contains($subject, '\\');
}

it('DC1 — parses every Group-B gate into a non-empty ability and no empty subject', function (): void {
    $broken = [];

    foreach (groupBGatePairs() as $pair) {
        if ($pair['gate']['ability'] === '') {
            $broken[] = $pair['route'].' — no ability in `'.$pair['gate']['declared'].'`';
        }

        foreach ($pair['gate']['subjects'] as $subject) {
            if (trim($subject) === '') {
                $broken[] = $pair['route'].' — empty subject in `'.$pair['gate']['declared'].'`';
            }
        }
    }

    expect($broken)->toBe([], "malformed `can:` payloads:\n".implode("\n", $broken));
});

it('DC2 — resolves every class-string subject to a registered policy that implements the ability', function (): void {
    $broken = [];

    foreach (groupBGatePairs() as $pair) {
        foreach ($pair['gate']['subjects'] as $subject) {
            if (! subjectIsClassName($subject)) {
                continue;
            }

            if (! class_exists($subject)) {
                // ⚠️ THE FAILURE MODE THIS CATCHES IS SILENT AND TOTAL: `Gate::getPolicyFor()` returns null
                // for a class that does not exist, so the route 403s EVERY principal. An unimported
                // `Foo::class` inside `routes/api.php` resolves to the GLOBAL `\Foo` and lands here.
                $broken[] = $pair['route'].' — no such class `'.$subject.'`';

                continue;
            }

            $policy = Gate::getPolicyFor($subject);

            if ($policy === null) {
                $broken[] = $pair['route'].' — no policy registered for `'.$subject.'`';

                continue;
            }

            if (! is_callable([$policy, $pair['gate']['ability']])) {
                $broken[] = $pair['route'].' — '.$policy::class.' has no `'.$pair['gate']['ability'].'()`';
            }
        }
    }

    expect($broken)->toBe([], "class-string subjects that cannot resolve:\n".implode("\n", $broken));
});

/*
| DC3 is the sharpest tooth in this file, and it is a fact about the INSTALLED middleware rather than a
| style rule. `Authorize::getModel()` returns `$request->route($model, null) ?? null` for any subject
| without a backslash, so a subject that is not a declared route parameter authorizes against `[null]` —
| `Gate::resolveAuthCallback()` finds no policy for null, falls through to an empty callback, and the route
| refuses EVERY principal, forever, with no error anywhere. A renamed route parameter, or a `can:` line
| copy-pasted onto a route that does not bind that model, produces exactly this.
*/
it('DC3 — binds every non-class subject to a route parameter the URI actually declares', function (): void {
    $broken = [];

    foreach (groupBGatePairs() as $pair) {
        $declared = $pair['object']->parameterNames();

        foreach ($pair['gate']['subjects'] as $subject) {
            if (subjectIsClassName($subject) || in_array($subject, $declared, true)) {
                continue;
            }

            $broken[] = $pair['route'].' — `'.$subject.'` is not a parameter of `'.$pair['object']->uri()
                .'` (declares: '.($declared === [] ? 'none' : implode(', ', $declared)).')';
        }
    }

    expect($broken)->toBe([], "subjects that resolve to null and would refuse everyone:\n".implode("\n", $broken));
});

/*
| DC4 asks the same policy question as DC2 for BOUND subjects, resolving the model the way production does
| — `signatureParameters(['subClass' => UrlRoutable::class])`, which is what `ImplicitRouteBinding` uses.
|
| ⚠️ IT SKIPS RATHER THAN FAILS when a controller action type-hints nothing for that parameter, and saying
| so is the point: some routes bind by string and resolve inside the controller, and treating that as a
| defect would make the check useless rather than careful. A gate on such a route is covered by DC3 only.
*/
it('DC4 — resolves every BOUND subject to a registered policy that implements the ability', function (): void {
    $broken = [];

    foreach (groupBGatePairs() as $pair) {
        $hints = [];

        foreach ($pair['object']->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $class = Reflector::getParameterClassName($parameter);

            if ($class !== null) {
                $hints[$parameter->getName()] = $class;
            }
        }

        foreach ($pair['gate']['subjects'] as $subject) {
            if (subjectIsClassName($subject) || ! isset($hints[$subject])) {
                continue; // a class subject is DC2's; an un-type-hinted parameter is deliberately skipped
            }

            $policy = Gate::getPolicyFor($hints[$subject]);

            if ($policy === null) {
                $broken[] = $pair['route'].' — no policy registered for bound `'.$hints[$subject].'`';

                continue;
            }

            if (! is_callable([$policy, $pair['gate']['ability']])) {
                $broken[] = $pair['route'].' — '.$policy::class.' has no `'.$pair['gate']['ability'].'()`'
                    .' (bound subject `'.$subject.'`)';
            }
        }
    }

    expect($broken)->toBe([], "bound subjects whose policy cannot answer the ability:\n".implode("\n", $broken));
});

/*
| DC5 covers the third payload shape: `can:<ability>` with NO subject at all, which the five `domains`
| routes use. Spatie registers a `Gate::before` that resolves the string as a permission key; a typo makes
| `PermissionDoesNotExist` bubble into a `false`, and the route refuses everyone — DC3's failure mode
| reached by a different road.
*/
it('DC5 — resolves every bare ability to a real permission key or a defined Gate ability', function (): void {
    $broken = [];

    foreach (groupBGatePairs() as $pair) {
        if ($pair['gate']['subjects'] !== []) {
            continue;
        }

        $ability = $pair['gate']['ability'];

        if (in_array($ability, RolePermissionSeeder::PERMISSIONS, true) || Gate::has($ability)) {
            continue;
        }

        $broken[] = $pair['route'].' — `'.$ability.'` is neither a seeded permission nor a defined ability';
    }

    expect($broken)->toBe([], "bare abilities that nothing can grant:\n".implode("\n", $broken));
});

/*
| DC6 pins arity, because getting it wrong is a 500 rather than a refusal. `Gate::callPolicyMethod()`
| shifts a leading CLASS-STRING argument off before calling, so a class subject contributes no parameter
| while a bound one does. Only the `<=` direction is checkable: PHP ignores surplus arguments silently.
*/
it('DC6 — passes every policy method at least the arguments it requires', function (): void {
    $broken = [];

    foreach (groupBGatePairs() as $pair) {
        $subjects = $pair['gate']['subjects'];

        if ($subjects === []) {
            continue; // a bare ability is DC5's
        }

        $first = $subjects[0];
        $class = subjectIsClassName($first) ? $first : null;

        if ($class === null || ! class_exists($class)) {
            continue; // bound subjects resolve at runtime; DC4 has already asked the policy question
        }

        $policy = Gate::getPolicyFor($class);

        if ($policy === null || ! is_callable([$policy, $pair['gate']['ability']])) {
            continue; // DC2 owns that failure and names it better
        }

        // The user is always argument one; a leading class string is shifted off and contributes nothing.
        $supplied = 1 + (count($subjects) - 1);
        $required = (new ReflectionMethod($policy, $pair['gate']['ability']))->getNumberOfRequiredParameters();

        if ($required > $supplied) {
            $broken[] = $pair['route'].' — '.$policy::class.'::'.$pair['gate']['ability']
                .'() requires '.$required.' argument(s), the gate supplies '.$supplied;
        }
    }

    expect($broken)->toBe([], "gates that would fatal on arity:\n".implode("\n", $broken));
});

/*
| Non-vacuity for all six, in the ClientUuidScopeTest shape. Every case above passes trivially on an empty
| set, and `groupBGatePairs()` is one filter away from returning one.
*/
it('walks a real set of parsed gates rather than an empty one', function (): void {
    $pairs = groupBGatePairs();

    expect(count($pairs))->toBeGreaterThan(40);

    // And the parse actually produced payloads rather than a list of empty shells.
    $withSubjects = array_filter($pairs, static fn (array $p): bool => $p['gate']['subjects'] !== []);
    expect(count($withSubjects))->toBeGreaterThan(35);

    $bare = array_filter($pairs, static fn (array $p): bool => $p['gate']['subjects'] === []);
    expect(count($bare))->toBeGreaterThan(4); // the `domains` group, which is the only bare-ability shape
});
