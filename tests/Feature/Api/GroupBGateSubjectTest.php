<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M63 — a `can:` gate that names the WRONG SUBJECT, made visible.
|--------------------------------------------------------------------------
| `GroupBPolicyGateTest` asks whether a gate is PRESENT and, since M63, whether its payload is coherent.
| Neither question can see the defect this file exists for.
|
| ⛔ THE DEFECT, STATED EXACTLY. `routes/api.php` gates the analytics routes on
| `can:viewAny,SavedReportView::class`, whose policy reads the two dashboard keys, and rejects
| `can:viewAny,Submission::class`, whose policy reads `submissions.view`, in a prose comment duplicated in
| `SavedReportViewPolicy`'s docblock. Swap the subject and EVERY TEST IN THE REPOSITORY STAYED GREEN: the
| structural gate discarded the payload, and both policies refuse a role-less member identically, so no
| principal any test used could tell the two apart. The rejection was defended by a sentence and nothing
| executable.
|
| ⛔ AND A ROUTE-NAME → MIDDLEWARE-STRING MANIFEST WOULD NOT HAVE FIXED IT. That is the obvious shape and
| it is the weak one: it mirrors the routes file, so an author who edits both identically leaves it
| asserting nothing. What is declared below is the AUDIENCE — the set of permission keys that opens the
| route — and the actual set is COMPUTED from the live policy by granting one key at a time. The swap
| flips the computed set from the dashboard pair to `submissions.view`, so the declaration becomes false
| IN WORDS. It cannot be kept true by a matching edit; it has to be rewritten into a sentence a reviewer
| reads.
|
| It is also a whole-catalog statement in a way no behavioural arm is: a policy that starts admitting
| `tenant.settings.manage` reddens here, and pinning that behaviourally would cost 29 requests per route.
|
| ⚠️ WHAT THIS FILE COVERS, AND WHAT IT DELIBERATELY DOES NOT. Eligible gates are the ones whose answer is
| a function of the PERMISSION ALONE: a class-string subject (no instance) or a bare ability. Bound-model
| gates — `can:view,form` — are excluded on principle rather than for effort: their answer also depends on
| the ROW (ownership, `resource_grants`), so there is no honest single set to declare. Those keep DC2–DC6
| in the sibling file plus their own behavioural suites.
|
| ⚠️ HOW THIS MANIFEST WAS PRODUCED, BECAUSE THE METHOD IS LOAD-BEARING. The oracle was run in print mode
| and each computed set was read and decided against the route's intent ONE BY ONE. The values were never
| pasted in. A rubber-stamped manifest is worse than none, because it launders the very swap it exists to
| catch — 21 is a number a human can actually review, which is why the surface was scoped to Group B and
| `routes/tenant.php`'s 95 further gates were filed as their own row instead.
*/

/**
 * The permission keys that open each eligible Group-B gate — the audience, reviewed, not the middleware
 * string echoed back.
 *
 * @var array<string, list<string>>
 */
const GROUP_B_GATE_GRANTS = [
    // ── Analytics. The dashboard PAIR, disjunctively, and this is the entry the whole file was written
    // for. `SavedReportViewPolicy::viewAny()` is `dashboard.org.view || dashboard.form.view`, which is
    // byte-for-byte the audience `ApiAbilities::READ_ANALYTICS` maps to — the "token scope and permission
    // check agree by construction" claim `routes/api.php` makes in prose, now asserted.
    // ⛔ `submissions.view` MUST NOT APPEAR HERE. Its presence is precisely the rejected widening.
    'api.v1.analytics.report' => ['dashboard.form.view', 'dashboard.org.view'],
    'api.v1.analytics.report.export' => ['dashboard.form.view', 'dashboard.org.view'],
    'api.v1.analytics.questions.index' => ['dashboard.form.view', 'dashboard.org.view'],
    'api.v1.analytics.questions.show' => ['dashboard.form.view', 'dashboard.org.view'],
    'api.v1.analytics.views.index' => ['dashboard.form.view', 'dashboard.org.view'],
    // `create()` delegates to `viewAny()` deliberately: saving a view is not a wider act than reading one.
    'api.v1.analytics.views.store' => ['dashboard.form.view', 'dashboard.org.view'],

    // ── The audit log is Owner/Admin only, and the single key says so without reference to any other read.
    'api.v1.audits.index' => ['audit_log.view'],

    // ── Third-party workspace credentials. `integrations.manage` rather than `webhooks.manage`, and the
    // seeder's own docblock records why: reusing the webhook key would have handed every already-minted
    // `manage:webhooks` token authority whose blast radius reaches outside this platform.
    'api.v1.connections.index' => ['integrations.manage'],

    // ── Custom domains are a tenant setting; the five routes are one bare Gate ability, no model at all.
    'api.v1.domains.index' => ['tenant.settings.manage'],
    'api.v1.domains.store' => ['tenant.settings.manage'],
    'api.v1.domains.verify' => ['tenant.settings.manage'],
    'api.v1.domains.primary' => ['tenant.settings.manage'],
    'api.v1.domains.destroy' => ['tenant.settings.manage'],

    // ── ⚠️ THE AUTHORING SURFACE, AND THE ONE ENTRY WORTH ARGUING WITH RATHER THAN SKIMMING.
    // `FormPolicy::viewAny()` is create-OR-edit, so the form LIST answers to authors only: a Viewer and a
    // Reviewer both hold `submissions.view` and neither dashboard key buys them this route. That reads
    // odd beside the analytics entries above until you name the surface — this is the builder's index, not
    // a submissions filter, and the read-only roles reach forms through the inbox instead. Reviewed and
    // intended; recorded here rather than silently accepted, because widening it is a product decision and
    // this file may not make one.
    'api.v1.forms.index' => ['forms.create', 'forms.edit.any', 'forms.edit.own'],

    // ── Everyone's standing is an ORG-level read, unlike `gamification.me`, which needs no permission at
    // all and carries no gate (ADR-0020 §D7). `dashboard.form.view` deliberately does NOT open it: a
    // per-form reader has no business ranking the whole workspace.
    'api.v1.gamification.leaderboard' => ['dashboard.org.view'],

    // ── Grants are the G10a successor to `form_collaborators`, and they answer to the same key.
    'api.v1.resource-grants.index' => ['forms.collaborators.manage'],
    'api.v1.resource-grants.store' => ['forms.collaborators.manage'],

    // ── The tenant-defined hierarchy, on its own dedicated key (the 28th permission, added by G10a).
    'api.v1.scopes.index' => ['scopes.manage'],
    'api.v1.scopes.store' => ['scopes.manage'],

    // ── Webhook endpoints, on the key that exists for them.
    'api.v1.webhooks.index' => ['webhooks.manage'],
    'api.v1.webhooks.store' => ['webhooks.manage'],
];

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->probe = User::factory()->create();
    enterTenant($this->tenant->id, $this->probe->id);
    makeActiveMember($this->probe, 'viewer');
    $this->probe->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * Every Group-B gate whose answer depends on the permission alone.
 *
 * @return array<string, array{ability: string, subject: string|null}>
 */
function eligibleGroupBGates(): array
{
    $eligible = [];

    foreach (groupBRoutes() as $route) {
        foreach (policyGates($route) as $gate) {
            $subjects = $gate['subjects'];

            if ($subjects === []) {
                $eligible[(string) $route->getName()] = ['ability' => $gate['ability'], 'subject' => null];

                continue;
            }

            if (count($subjects) === 1 && str_contains($subjects[0], '\\')) {
                $eligible[(string) $route->getName()] = ['ability' => $gate['ability'], 'subject' => $subjects[0]];
            }
        }
    }

    ksort($eligible);

    return $eligible;
}

it('pins the exact permission set that opens every eligible Group-B gate', function (): void {
    $eligible = eligibleGroupBGates();
    $computed = [];
    $uncallable = [];

    // One key at a time, REPLACING rather than accumulating — an additive loop would compute the union of
    // everything granted so far and every route would come back holding the whole catalog.
    foreach (RolePermissionSeeder::PERMISSIONS as $key) {
        $this->probe->syncPermissions([$key]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($eligible as $name => $gate) {
            // ⚠️ THE GUARD IS NOT DEFENSIVE, IT WAS MEASURED. Under M63's own MU8 — `can:create` narrowed to
            // `can:view` on a route with no bound instance — this loop raised ArgumentCountError and the
            // whole file died with a FATAL rather than a report. DC6 names that defect precisely and is
            // where it belongs; catching it here turns "the oracle exploded" into a line that says so and
            // points at the check that explains it, so one arity slip cannot hide every other route's
            // audience.
            try {
                $allowed = $gate['subject'] === null
                    ? Gate::forUser($this->probe)->allows($gate['ability'])
                    : Gate::forUser($this->probe)->allows($gate['ability'], $gate['subject']);
            } catch (Throwable $e) {
                $uncallable[$name] = $name.' — the gate could not be evaluated at all ('.$e::class
                    .'); DC6 in GroupBPolicyGateTest names this';

                continue;
            }

            if ($allowed) {
                $computed[$name][] = $key;
            }
        }
    }

    $mismatches = array_values($uncallable);

    foreach ($eligible as $name => $gate) {
        if (isset($uncallable[$name])) {
            continue; // already reported, and its computed set is meaningless
        }

        $actual = $computed[$name] ?? [];
        sort($actual);
        $declared = GROUP_B_GATE_GRANTS[$name] ?? null;

        if ($declared === null) {
            $mismatches[] = $name.' — no declared grants; computed ['.implode(', ', $actual).']';

            continue;
        }

        $expected = $declared;
        sort($expected);

        if ($actual !== $expected) {
            $mismatches[] = $name.' — declared ['.implode(', ', $expected).'] but the policy opens to ['
                .implode(', ', $actual).']';
        }
    }

    expect($mismatches)->toBe([], "gates whose audience is not what this file declares:\n".implode("\n", $mismatches));
});

/*
| The manifest must cover exactly the eligible surface. A new analytics route arrives RED with "no declared
| grants" rather than slipping in uncovered, and an entry that outlives its route documents history instead
| of stopping anything.
*/
it('declares grants for exactly the eligible gates, no more and no fewer', function (): void {
    $eligible = array_keys(eligibleGroupBGates());
    $declared = array_keys(GROUP_B_GATE_GRANTS);
    sort($eligible);
    sort($declared);

    expect($declared)->toBe($eligible);
});

/*
| Non-vacuity, in the ClientUuidScopeTest shape — and here it is not ceremony. The oracle computes its
| answer from a live probe, so the two ways it can silently produce nonsense are an empty set for every
| route (a missing cache flush, an unseeded catalog, a probe with no team) and the whole catalog for every
| route (a leaking Gate::before). Both would let the equality case above pass against a declaration that
| had been quietly rewritten to match.
*/
it('declares a real audience for every gate rather than nothing or everything', function (): void {
    expect(count(GROUP_B_GATE_GRANTS))->toBeGreaterThan(19);

    $catalog = count(RolePermissionSeeder::PERMISSIONS);

    foreach (GROUP_B_GATE_GRANTS as $name => $keys) {
        expect($keys)->not->toBe([], "{$name} declares no permission at all");
        expect(count($keys))->toBeLessThan($catalog, "{$name} declares the whole catalog");

        foreach ($keys as $key) {
            expect(RolePermissionSeeder::PERMISSIONS)->toContain($key);
        }
    }
});

/*
| And the entry the file exists for, asserted directly rather than only as one row of a set comparison.
| A future edit that widened the analytics audience to `submissions.view` would redden the case above with
| a diff a reader has to interpret; this one names the defect.
*/
it('keeps submissions.view out of the analytics audience, which is the widening the routes file rejects', function (): void {
    foreach (['api.v1.analytics.report', 'api.v1.analytics.report.export'] as $name) {
        expect(GROUP_B_GATE_GRANTS[$name])->not->toContain('submissions.view');
        expect(GROUP_B_GATE_GRANTS[$name])->toContain('dashboard.org.view');
        expect(GROUP_B_GATE_GRANTS[$name])->toContain('dashboard.form.view');
    }
});
