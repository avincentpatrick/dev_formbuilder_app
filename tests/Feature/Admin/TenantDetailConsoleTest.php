<?php

declare(strict_types=1);

use App\Enums\BillingInterval;
use App\Enums\PlanTier;
use App\Models\Audit;
use App\Models\Form;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /admin/tenants/{tenant} and the plan-assign form (Increment I7b).
|
| ── FIXTURES ARE ORDINARY FACTORIES, WHICH IS THE OPPOSITE OF I7a ──────────────────────────────────────
| The committed-on-`pgsql_privileged` apparatus exists for ONE reason: `SuperAdminService::elevated()`
| reads on a separate connection that cannot see RefreshDatabase's open transaction. **Nothing on this page
| elevates.** Every read here — the tenant row, the plan catalog, the subscription, the owner, all eight
| entitlement gauges and the domains — runs on the DEFAULT connection under an ADOPTED tenant context, i.e.
| the same connection the factories wrote to, inside the same transaction. Committed fixtures would be pure
| cost, and would re-introduce the "committed tenants are global state" failure that turned nine unrelated
| tests red in CI. In particular this file must NOT register `purgeCommittedFeedbackFixtures()`: its markers
| are `slug LIKE 'console-%'`, which would reach across suites.
|
| ⚠️ Reset context with `TenantContext::applyLocal(null)`, NEVER `TenantContext::forget()`. The latter is
| `is_local = false`, i.e. a SESSION-scoped GUC that survives RefreshDatabase's rollback and bleeds into the
| next test on that connection.
|
| ⚠️ Spatie's permissions team must be reset too: `enterTenant()` sets it, and HandleInertiaRequests::share()
| evaluates a page of `$user->can()` calls against whatever team is current.
*/

$adminPath = fn (string $path): string => 'http://'.config('tenancy.central_domain')."/admin{$path}";

/** Leave the request in the state a real central-host visit has: no tenant, no team. */
function leaveTenant(): void
{
    TenantContext::applyLocal(null);
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

function consoleAdmin(): User
{
    return User::factory()->superAdmin()->confirmedTwoFactor()->create();
}

beforeEach(function (): void {
    TenantContext::flush();

    // I8a — the console carries `step-up`; without a fresh confirmation every request here 302s to
    // /user/confirm-password instead of rendering. See tests/Pest.php.
    confirmPasswordNow();
});

/* ── Gates ───────────────────────────────────────────────────────────────────────────────────────────── */

it('lets a super-admin with confirmed 2FA open a workspace', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-ok');
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/TenantDetail', false));
});

it('redirects a super-admin without confirmed 2FA to enrollment', function () use ($adminPath): void {
    $tenant = inboxTenant('detail-mfa');
    leaveTenant();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get($adminPath("/tenants/{$tenant->id}"))
        ->assertRedirect(route('admin.mfa.setup'));
});

it('404s a workspace page for an authenticated non-super-admin', function () use ($adminPath): void {
    $tenant = inboxTenant('detail-403');
    leaveTenant();

    $this->actingAs(User::factory()->create())->get($adminPath("/tenants/{$tenant->id}"))->assertNotFound();
});

it('redirects a guest to login', function () use ($adminPath): void {
    $tenant = inboxTenant('detail-guest');
    leaveTenant();

    $this->get($adminPath("/tenants/{$tenant->id}"))->assertRedirect();
});

it('404s an unknown workspace id', function () use ($adminPath): void {
    leaveTenant();

    $this->actingAs(consoleAdmin())
        ->get($adminPath('/tenants/0192e2e0-0000-7000-8000-000000000000'))
        ->assertNotFound();
});

it('404s a malformed workspace id instead of 500ing on the uuid column', function () use ($adminPath): void {
    // `tenants.id` is a uuid column, so without `->whereUuid('tenant')` route-model binding emits
    // `where id = 'not-a-uuid'` and Postgres raises SQLSTATE 22P02 — a 500, not a 404. Latent since H5a
    // only because the POST routes had no UI; a GET route in the address bar changes that.
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath('/tenants/not-a-uuid'))->assertNotFound();
});

/* ── Identity + owner ────────────────────────────────────────────────────────────────────────────────── */

it('renders the workspace identity', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-identity');
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenant.name', 'Detail-identity')
            ->where('tenant.slug', 'detail-identity')
            ->where('tenant.status', 'active')
            ->where('tenant.status_label', 'Active')
            ->where('tenant.is_active', true)
            ->has('tenant.app_host')
            ->has('tenant.public_host')
        );
});

it('resolves the owner, which is only possible under the adopted context', function () use ($adminPath): void {
    // ⭐ `users` carries the join-shape visibility policy, whose second disjunct matches active members of
    // the CURRENT tenant. Read the owner outside the adopt-context transaction and this is null — silently,
    // forever. Nothing else on the page would notice.
    $this->withoutVite();
    // The role catalog is a SEEDER, not a migration, so a suite that needs a real membership must run it
    // explicitly rather than inherit it from whichever file happened to run first.
    (new RolePermissionSeeder)->run();

    $tenant = inboxTenant('detail-owner');
    $owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@detailtest.local']);

    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenant.owner.name', 'Ada Owner')
            ->where('tenant.owner.email', 'ada@detailtest.local')
        );
});

it('leaks no tenant context into the rest of the central-host request', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-noleak');
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))->assertOk();

    expect(TenantContext::currentTenantId())->toBeNull();
});

/* ── Plan card ───────────────────────────────────────────────────────────────────────────────────────── */

it('offers every plan including the held-from-sale tiers', function () use ($adminPath): void {
    // The HTTP twin of SuperAdminAssignPlanTest's "can assign a held-from-sale plan". Business and
    // Enterprise are seeded `is_active = false` and stay ASSIGNABLE (ADR-0008 §D6) — filtering them out of
    // the catalog would make this form unable to do what the service test proves it can.
    $this->withoutVite();
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-catalog');
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<array<string, mixed>> $catalog */
            $catalog = $page->toArray()['props']['plan']['catalog'];

            $codes = array_column($catalog, 'code');
            expect($codes)->toContain('business');

            // Ordered by sort_order: Free 0 → Enterprise 4.
            expect($codes[0])->toBe('free');

            $business = collect($catalog)->firstWhere('code', 'business');
            expect($business['is_active'])->toBeFalse();
        });
});

it('names the governing subscription and its interval', function () use ($adminPath): void {
    $this->withoutVite();
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-plan');
    $pro = Plan::query()->where('code', PlanTier::Professional->value)->firstOrFail();

    enterTenant($tenant->id);
    Subscription::factory()->forPlan($pro)->create(['billing_interval' => BillingInterval::Yearly]);
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.current.code', 'professional')
            ->where('plan.current.interval', 'yearly')
            ->where('plan.current.interval_label', 'Yearly')
            ->where('plan.current.subscription_name', 'default')
            ->where('plan.effective.code', 'professional')
        );
});

it('distinguishes "no subscription" from "no catalog"', function () use ($adminPath): void {
    $this->withoutVite();
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-nosub');
    leaveTenant();

    // Catalog seeded, no subscription → the free FALLBACK is in force. Not the same thing as no plan at all.
    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.current', null)
            ->where('plan.effective.code', 'free')
            ->where('usage.available', true)
        );
});

it('stays up with no plan catalog at all', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-nocatalog');
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.current', null)
            ->where('plan.effective', null)
            ->where('usage.available', false)
            ->where('features', [])
        );
});

it('never reports an unprovisioned arrangement as being in effect', function () use ($adminPath): void {
    // ⭐ THE ROW THAT WAS LYING, PINNED (P2a / ADR-0017 §D5).
    //
    // The feature table is GENERATED by iterating every key in `plans.feature_flags`, so `dedicated_db`
    // appeared on it the day the catalog was seeded — with `plan_grants: true`, `effective: true`,
    // `reason: null` and the `ucfirst` fallback label "Dedicated db". For every Enterprise tenant this page
    // told an operator the workspace had a dedicated database. Nothing in the product delivers one, and
    // `grep dedicated_db` finds only a seeder, a docblock and a negative unit test — never this surface.
    //
    // A plan flag says what the CONTRACT includes; it cannot say whether infrastructure exists. These three
    // keys are the ones where those differ.
    $this->withoutVite();
    $tenant = inboxTenant('detail-notprov');
    leaveTenant();

    enterTenant($tenant->id);
    assignPlanTier(PlanTier::Enterprise);
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<array<string, mixed>> $features */
            $features = $page->toArray()['props']['features'];
            $byKey = collect($features)->keyBy('key')->all();

            // Labels pinned exactly rather than "differs from the fallback" — because two of the three
            // COINCIDE with the fallback and only `dedicated_db` ever rendered badly ("Dedicated db"). The
            // map entry exists for all three so renaming a key cannot silently rewrite user-facing copy.
            $expectedLabels = [
                'dedicated_db' => 'Dedicated database',
                'data_residency' => 'Data residency',
                'embedded_payments' => 'Embedded payments',
            ];

            foreach ($expectedLabels as $key => $label) {
                // array_key_exists, not toHaveKey(): Pest's second argument to toHaveKey is the expected
                // VALUE, not a failure message, so a message there silently becomes an assertion.
                expect(array_key_exists($key, $byKey))
                    ->toBeTrue("The feature table no longer lists `{$key}`.");
                expect($byKey[$key]['effective'])->toBeFalse("`{$key}` is reported as in effect.");
                expect($byKey[$key]['reason'])->toBe('not_provisioned');
                expect($byKey[$key]['label'])->toBe($label);
            }

            // The control: a capability that IS built must still read as in effect on the same plan, or
            // this correction would have been a blanket "report nothing" rather than a distinction.
            expect($byKey['sso_saml']['effective'])->toBeTrue();
            expect($byKey['sso_saml']['reason'])->toBeNull();
        });
});

/* ── Usage ───────────────────────────────────────────────────────────────────────────────────────────── */

it('reports usage for the workspace being viewed, not for none', function () use ($adminPath): void {
    // ⭐ THE LOAD-BEARING USAGE CASE. Every gauge is an RLS-scoped COUNT. Move `snapshot()` out of the
    // adopt-context transaction and `Form::query()->count()` returns 0 — a plausible, silent, wrong answer
    // that no other assertion on this page would catch.
    $this->withoutVite();
    app(PlanSeeder::class)->run();

    $tenantA = inboxTenant('detail-usage-a');
    $owner = User::factory()->create();
    enterTenant($tenantA->id, $owner->id);
    Form::factory()->count(2)->create();

    $tenantB = inboxTenant('detail-usage-b');
    enterTenant($tenantB->id, $owner->id);
    Form::factory()->count(5)->create();

    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenantA->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<array<string, mixed>> $gauges */
            $gauges = $page->toArray()['props']['usage']['gauges'];
            $forms = collect($gauges)->firstWhere('metric', 'forms_count');

            expect($forms['used'])->toBe(2);
        });
});

it('treats a null limit as unlimited and a zero limit as a real ceiling', function () use ($adminPath): void {
    // ⚠️ The Free tier seeds a literal `0` for api_requests / webhook_deliveries / webhook_endpoints_count.
    // Any `$limit ? … : 'Unlimited'` ternary would report three hard-blocked quotas as unrestricted.
    $this->withoutVite();
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-limits');
    $enterprise = Plan::query()->where('code', PlanTier::Enterprise->value)->firstOrFail();

    enterTenant($tenant->id);
    Subscription::factory()->forPlan($enterprise)->create();
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $gauges = collect($page->toArray()['props']['usage']['gauges']);
            $forms = $gauges->firstWhere('metric', 'forms_count');

            expect($forms['unlimited'])->toBeTrue()
                ->and($forms['limit'])->toBeNull()
                ->and($forms['display'])->not->toContain('/');
        });

    // And a Free tenant's zero-limit metric is NOT unlimited.
    $free = inboxTenant('detail-limits-free');
    enterTenant($free->id);
    Subscription::factory()->forPlan(Plan::query()->where('code', PlanTier::Free->value)->firstOrFail())->create();
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$free->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $gauges = collect($page->toArray()['props']['usage']['gauges']);
            $webhooks = $gauges->firstWhere('metric', 'webhook_endpoints_count');

            expect($webhooks['limit'])->toBe(0)->and($webhooks['unlimited'])->toBeFalse();
        });
});

it('splits gauges from per-period flows', function () use ($adminPath): void {
    $this->withoutVite();
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-split');
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $usage = $page->toArray()['props']['usage'];

            expect(array_column($usage['gauges'], 'metric'))
                ->toBe(['storage_bytes', 'active_seats', 'forms_count', 'webhook_endpoints_count']);
            expect(array_column($usage['flows'], 'metric'))
                ->toBe(['submissions_count', 'api_requests', 'webhook_deliveries', 'exports_count']);
        });
});

/* ── Domains ─────────────────────────────────────────────────────────────────────────────────────────── */

it('lists pending and live custom domains but never the subdomain label', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-domains');
    customDomain($tenant, 'live.example.test');
    customDomain($tenant, 'pending.example.test', verified: false, activated: false);

    $other = inboxTenant('detail-domains-other');
    customDomain($other, 'elsewhere.example.test');

    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            /** @var list<array<string, mixed>> $rows */
            $rows = $page->toArray()['props']['domains']['rows'];
            $hosts = array_column($rows, 'domain');

            expect($hosts)->toContain('live.example.test')
                // Proves `Domain::unscopedQuery()` — the `resolvable` scope hides non-activated rows.
                ->toContain('pending.example.test')
                // Proves the `position('.' in domain) > 0` filter: the bare subdomain label is not a
                // custom domain and must not appear.
                ->not->toContain('detail-domains')
                // Proves the explicit `where('tenant_id')`, which IS the isolation (`domains` is RLS-exempt).
                ->not->toContain('elsewhere.example.test');

            // The DNS challenge is the tenant's own recovery affordance; the console has no use for it.
            expect($rows[0])->not->toHaveKey('verification');
        });
});

/* ── Plan assignment over HTTP (no coverage existed before I7b) ──────────────────────────────────────── */

it('assigns a plan and records it in the workspace own ledger', function () use ($adminPath): void {
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-assign');
    $pro = Plan::query()->where('code', PlanTier::Professional->value)->firstOrFail();
    leaveTenant();

    $admin = consoleAdmin();

    // ⚠️ `assertSessionHasNoErrors()` BEFORE `assertRedirect()` and not merely beside it: SuperAdminException
    // renders as `back()->withErrors(['admin' => …])`, so a REFUSAL is also a redirect-back and would pass
    // the redirect assertion on its own.
    $this->actingAs($admin)
        ->from($adminPath("/tenants/{$tenant->id}"))
        ->post($adminPath("/tenants/{$tenant->id}/plan"), [
            'plan_id' => $pro->id,
            'billing_interval' => 'yearly',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect($adminPath("/tenants/{$tenant->id}"));

    enterTenant($tenant->id);

    $subscription = Subscription::query()->where('name', 'default')->firstOrFail();
    expect($subscription->plan_id)->toBe($pro->id)
        ->and($subscription->billing_interval)->toBe(BillingInterval::Yearly);

    // RBAC §9 transparency: the operator's action lands in the AFFECTED workspace's log, not the platform's.
    $audit = Audit::query()->where('auditable_type', 'subscription')->firstOrFail();
    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->is_system_action)->toBeFalse();
});

it('defaults the billing interval to monthly when it is omitted', function () use ($adminPath): void {
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-assign-default');
    $starter = Plan::query()->where('code', PlanTier::Starter->value)->firstOrFail();
    leaveTenant();

    $this->actingAs(consoleAdmin())
        ->post($adminPath("/tenants/{$tenant->id}/plan"), ['plan_id' => $starter->id])
        ->assertSessionHasNoErrors();

    enterTenant($tenant->id);
    expect(Subscription::query()->firstOrFail()->billing_interval)->toBe(BillingInterval::Monthly);
});

it('rejects a malformed plan id instead of 500ing on the uuid column', function () use ($adminPath): void {
    // ⚠️ `exists:plans,id` on a non-uuid string reaches Postgres as `where id = 'garbage'` → SQLSTATE 22P02,
    // a 500. `uuid` ALONE does not fix it — Laravel only short-circuits after an IMPLICIT rule fails — so
    // the rule set is `['required','bail','uuid','exists:plans,id']`. This case is what pins the `bail`.
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-assign-garbage');
    leaveTenant();

    $this->actingAs(consoleAdmin())
        ->post($adminPath("/tenants/{$tenant->id}/plan"), ['plan_id' => 'garbage'])
        ->assertSessionHasErrors('plan_id');
});

it('rejects an unknown plan and an unknown interval', function () use ($adminPath): void {
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-assign-invalid');
    $free = Plan::query()->where('code', PlanTier::Free->value)->firstOrFail();
    leaveTenant();

    $this->actingAs(consoleAdmin())
        ->post($adminPath("/tenants/{$tenant->id}/plan"), ['plan_id' => '0192e2e0-0000-7000-8000-000000000000'])
        ->assertSessionHasErrors('plan_id');

    $this->actingAs(consoleAdmin())
        ->post($adminPath("/tenants/{$tenant->id}/plan"), [
            'plan_id' => $free->id,
            'billing_interval' => 'fortnightly',
        ])
        ->assertSessionHasErrors('billing_interval');
});

it('gates the plan-assign route the same way as the page', function () use ($adminPath): void {
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-assign-gate');
    $free = Plan::query()->where('code', PlanTier::Free->value)->firstOrFail();
    leaveTenant();

    $this->actingAs(User::factory()->create())
        ->post($adminPath("/tenants/{$tenant->id}/plan"), ['plan_id' => $free->id])
        ->assertNotFound();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->post($adminPath("/tenants/{$tenant->id}/plan"), ['plan_id' => $free->id])
        ->assertRedirect(route('admin.mfa.setup'));
});

it('shows the newly assigned plan on a re-read in the same request cycle', function () use ($adminPath): void {
    // Meaningful precisely because `EntitlementService` is bound `scoped()` and only the queue worker calls
    // `forgetScopedInstances()` — so ONE instance survives every call in a Pest process. This pins both
    // assignPlan()'s trailing forget() and tenantSnapshot()'s leading one; without them the page would
    // re-render the plan it memoized before the assignment.
    $this->withoutVite();
    app(PlanSeeder::class)->run();
    $tenant = inboxTenant('detail-reread');
    $pro = Plan::query()->where('code', PlanTier::Professional->value)->firstOrFail();
    leaveTenant();

    $admin = consoleAdmin();

    $this->actingAs($admin)->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('plan.effective.code', 'free'));

    leaveTenant();
    $this->actingAs($admin)
        ->post($adminPath("/tenants/{$tenant->id}/plan"), ['plan_id' => $pro->id])
        ->assertSessionHasNoErrors();

    leaveTenant();
    $this->actingAs($admin)->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plan.current.code', 'professional')
            ->where('plan.effective.code', 'professional')
        );
});

/* ── The list links to the detail page ───────────────────────────────────────────────────────────────── */

it('gives the tenant list rows something to link to', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-link');
    leaveTenant();

    // Before I7b `admin/Tenants.vue` linked nowhere. The route name is what the page's href is built from.
    expect(route('admin.tenants.show', $tenant))->toEndWith("/admin/tenants/{$tenant->id}");

    $this->actingAs(consoleAdmin())->get($adminPath('/tenants'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Tenants', false));
});

it('keeps a suspended workspace readable and offers reactivation', function () use ($adminPath): void {
    $this->withoutVite();
    $tenant = inboxTenant('detail-suspended');
    Tenant::query()->whereKey($tenant->id)->update(['status' => 'suspended']);
    leaveTenant();

    $this->actingAs(consoleAdmin())->get($adminPath("/tenants/{$tenant->id}"))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenant.status', 'suspended')
            ->where('tenant.status_label', 'Suspended')
            // `isActive()` goes through TenantStatus::tryFrom(); a direct `!== TenantStatus::Active`
            // comparison against the uncast ?string column would always be true and always report active.
            ->where('tenant.is_active', false)
        );
});
