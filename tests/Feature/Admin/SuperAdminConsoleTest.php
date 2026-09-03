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
