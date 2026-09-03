<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B2c — the super-admin console HTTP surface (central domain).
|--------------------------------------------------------------------------
| Proves the gates: `superadmin` (404 for non-staff, non-disclosure) and `superadmin.mfa` (mandatory
| confirmed 2FA, security §8), plus the tenant suspend/reactivate actions and their authorization. The
| console is constrained to the central host (config/tenancy.php → meridian.test), so requests target it
| explicitly. GETs render the Inertia root view, so withoutVite() (the CI tests job doesn't build assets).
|
| Users are created in-transaction (factory): the middleware reads in-memory attributes via actingAs(),
| so no committed rows are needed here (unlike the DB-level SuperAdminBypassTest).
*/

// I8a — every console page now carries `step-up` as well, so a live session is not enough: the request
// 302s to /user/confirm-password unless this session confirmed its password in the last 15 minutes. The
// `superadmin.mfa` cases below still assert their own redirect, because superadmin.mfa runs FIRST.
beforeEach(fn () => confirmPasswordNow());

$adminUrl = fn (string $path): string => "http://meridian.test/admin{$path}";

it('lets a super-admin with confirmed 2FA into the console', function () use ($adminUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get($adminUrl('/tenants'))->assertOk();
});

it('redirects a super-admin without confirmed 2FA to enrollment (mandatory MFA)', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->create(); // two_factor_confirmed_at is null

    $this->actingAs($admin)
        ->get($adminUrl('/tenants'))
        ->assertRedirect(route('admin.mfa.setup'));
});

it('404s the console for an authenticated non-super-admin (non-disclosure)', function () use ($adminUrl): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get($adminUrl('/tenants'))->assertNotFound();
});

it('redirects a guest to login', function () use ($adminUrl): void {
    $this->get($adminUrl('/tenants'))->assertRedirect();
});

it('lets the enrollment page load without confirmed 2FA (no redirect loop)', function () use ($adminUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get($adminUrl('/two-factor'))->assertOk();
});

it('suspends and reactivates a tenant through the console', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);

    $this->actingAs($admin)->post($adminUrl("/tenants/{$tenant->id}/suspend"))->assertRedirect();
    expect($tenant->fresh()->status)->toBe('suspended');

    $this->actingAs($admin)->post($adminUrl("/tenants/{$tenant->id}/reactivate"))->assertRedirect();
    expect($tenant->fresh()->status)->toBe('active');
});

it('404s a malformed workspace id on every tenant POST instead of 500ing on the uuid column', function () use ($adminUrl): void {
    // Increment M66. `tenants.id` is a native Postgres `uuid`, so without `->whereUuid('tenant')` implicit
    // binding emits `where "id" = 'not-a-uuid'` and Postgres raises SQLSTATE 22P02 — a QueryException, and
    // therefore a 500, BEFORE `firstOrFail()` ever gets to turn a miss into a 404.
    //
    // ⛔ THE SIBLING GET ROUTE HAS HAD THIS CASE SINCE I7b AND IT PROVED NOTHING ABOUT THESE THREE.
    // `TenantDetailConsoleTest` pins `admin.tenants.show`, which already carried the constraint; the three
    // POSTs beside it did not, and a per-route test cannot fail for a route nobody wrote it for. All three
    // are asserted here together, and `AdminConsoleGateTest` carries the arm that discovers a fourth.
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    foreach (['suspend', 'reactivate', 'plan'] as $action) {
        $this->actingAs($admin)
            ->post($adminUrl("/tenants/not-a-uuid/{$action}"))
            ->assertNotFound();
    }
});

it('surfaces a business-rule violation as a redirect-back error, not a 500', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'suspended']);

    // Suspending an already-suspended tenant → SuperAdminException → redirect back with an `admin` error.
    $this->actingAs($admin)
        ->from($adminUrl('/tenants'))
        ->post($adminUrl("/tenants/{$tenant->id}/suspend"))
        ->assertRedirect($adminUrl('/tenants'))
        ->assertSessionHasErrors('admin');
});

it('forbids a non-super-admin from suspending a tenant', function () use ($adminUrl): void {
    $user = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);

    $this->actingAs($user)->post($adminUrl("/tenants/{$tenant->id}/suspend"))->assertNotFound();
    expect($tenant->fresh()->status)->toBe('active');
});

it('lists all users for a confirmed super-admin', function () use ($adminUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // assertOk only — the elevated connection won't see in-transaction factory users, and asserting
    // specific rows belongs in the DB-level SuperAdminBypassTest.
    $this->actingAs($admin)->get($adminUrl('/users'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Increment M67 — the behavioural denials the console's four gates were missing.
|--------------------------------------------------------------------------
| ⛔ WHY THIS IS NOT REDUNDANT WITH `AdminConsoleGateTest`, WHICH IS THE POINT (M43). That file is ENTIRELY
| STRUCTURAL: every case sweeps the route table and asserts which middleware each console route is DECLARED
| with. A structural gate cannot see a middleware that stops refusing — empty the body of `superadmin` and
| all of it stays green, because the entry is still on the route. Until now `GET /admin/users` had no
| behavioural half at all: exactly one test, and it was a 200, on the widest cross-tenant read in the
| deployment.
|
| ⚠️ AND THE ROW WAS RIGHT THAT IT IS NOT ALONE — `admin.tenants.reactivate`, `admin.tenants.assign-plan`
| and `admin.feedback.update` had positive requests and no denials of their own either. Driven from a
| dataset over all four, so a sweep reddens for a member it never names.
|
| ⛔⛔ THE PARAMETERISED ROUTES NEED A REAL TENANT, AND AN ARBITRARY UUID MADE TWO OF THESE CASES PASS FOR
| THE WRONG REASON. `SubstituteBindings` is NAMED in bootstrap/app.php's priority array; `superadmin`,
| `superadmin.mfa` and `step-up` are not — and SortedMiddleware hoists the listed classes past the unlisted
| ones. So route-model binding runs BEFORE all three console gates, and a non-existent `{tenant}` 404s from
| the binding rather than from the gate under test. That 404 is indistinguishable from `superadmin`'s, so
| the non-disclosure case would have been green on two routes without ever reaching the middleware it names
| — measured, by printing the status and Location per route, not reasoned. The tenant is therefore real and
| every refusal below is the gate's own.
|
| `admin.feedback.update` takes a RAW uuid by design (its route comment says why), so it needs no row.
*/

/** The four console routes with no behavioural denial of their own, as verb + path-builder. */
dataset('undenied console routes', [
    'the cross-tenant user list' => ['get', fn (): string => '/users'],
    'tenant reactivate' => ['post', fn (): string => '/tenants/'.consoleTenant()->id.'/reactivate'],
    'tenant plan assignment' => ['post', fn (): string => '/tenants/'.consoleTenant()->id.'/plan'],
    'feedback triage update' => ['patch', fn (): string => '/feedback/0f6f8b5e-1a2b-4c3d-8e9f-0a1b2c3d4e5f'],
]);

/** A committed tenant for the two model-bound routes — see the block comment for why it cannot be a literal. */
function consoleTenant(): Tenant
{
    return Tenant::firstOrCreate(['slug' => 'console-fixture'], ['name' => 'Console Fixture', 'status' => 'active']);
}

it('redirects a guest away from every console route', function (string $verb, Closure $path) use ($adminUrl): void {
    $this->{$verb}($adminUrl($path()))->assertRedirect();
})->with('undenied console routes');

it('404s an authenticated non-super-admin on every console route (non-disclosure)', function (string $verb, Closure $path) use ($adminUrl): void {
    // 404 and not 403, deliberately: a 403 would confirm the route exists.
    $this->actingAs(User::factory()->create())
        ->{$verb}($adminUrl($path()))
        ->assertNotFound();
})->with('undenied console routes');

it('redirects a super-admin without confirmed 2FA to enrollment on every console route', function (string $verb, Closure $path) use ($adminUrl): void {
    // `superadmin.mfa` runs ahead of `step-up`, so this is the redirect even though the beforeEach above
    // has already confirmed a password — mandatory MFA (security §8) is the outer of the two.
    $this->actingAs(User::factory()->superAdmin()->create())
        ->{$verb}($adminUrl($path()))
        ->assertRedirect(route('admin.mfa.setup'));
})->with('undenied console routes');

it('sends a super-admin whose password confirmation has lapsed to the step-up on every console route', function (string $verb, Closure $path) use ($adminUrl): void {
    // ⚠️ THE ONE CASE THAT MUST UNDO THE `beforeEach`. Every other test here calls confirmPasswordNow();
    // the whole point of this one is that the window has closed, so the confirmation is pushed back past
    // it. Without this line the request sails through `step-up` and 200s.
    confirmPasswordNow(secondsAgo: 4 * 3600);

    $this->actingAs(User::factory()->superAdmin()->confirmedTwoFactor()->create())
        ->{$verb}($adminUrl($path()))
        ->assertRedirect(route('password.confirm'));
})->with('undenied console routes');
