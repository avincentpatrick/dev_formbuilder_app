<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B2b — membership HTTP wiring through the real subdomain pipeline.
|--------------------------------------------------------------------------
| Proves the pieces the service-level MembershipLifecycleTest can't: the `can:` permission gate on the
| member-admin routes, and the NO-AUTH invitation group establishing tenant context so an invitee who
| is not yet a member can accept and have their role materialized end-to-end. Tenants resolve on a
| subdomain of a central domain (config/tenancy.php → meridian.test). Helpers enterTenant/catalogRole/
| makeActiveMember are the shared ones defined in MembershipLifecycleTest (global in Pest).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    // Reset only the in-process Spatie team id. The committed invitee (seeded on the privileged
    // connection so the pre-auth resolve can see it) is intentionally NOT deleted here: the accept
    // test UPDATEs that row inside its own not-yet-rolled-back transaction, so a privileged DELETE of
    // it would deadlock (the B2a lesson). Each test uses a distinct email; migrate:fresh wipes the rest.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('forbids a member without the permission from inviting', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // viewer lacks tenant.members.invite

    $this->actingAs($viewer)
        ->post('http://acme.meridian.test/members/invitations', [
            'email' => 'someone@membertest.local',
            'role' => 'admin',
        ])
        ->assertForbidden();
});

it('lets an Admin invite a member through the gated route', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $this->actingAs($admin)
        ->post('http://acme.meridian.test/members/invitations', [
            'email' => 'invitee@membertest.local',
            'role' => 'form_editor',
        ])
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id);
    expect(TenantUser::where('status', TenantUserStatus::Invited)->count())->toBe(1);
});

it('accepts an invitation end-to-end through the no-auth invitation route', function (): void {
    // The GET below renders the Inertia root view; skip Vite so it doesn't need built assets (the CI
    // tests job doesn't run `npm build`), mirroring the B1 SmokeTest.
    $this->withoutVite();

    // ⚠️ THE POST BELOW VALIDATES A NEW PASSWORD, SO IT REACHED api.pwnedpasswords.com ON EVERY CI RUN —
    // and stayed green either way, because `NotPwnedVerifier` catches a failed lookup and FAILS OPEN to
    // "uncompromised". Found and closed in J3a; see `fakeHibp()`'s docblock in tests/Pest.php.
    fakeHibp();
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    // The invitee must be committed so the pre-auth (pgsql_auth) resolve in the controller sees them.
    $invitee = User::on('pgsql_privileged')->forceCreate([
        'name' => 'Pending Person',
        'email' => 'pending@membertest.local',
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => null, // placeholder ⇒ isUnusedPlaceholder
    ]);

    enterTenant($tenant->id);
    TenantUser::create([
        'user_id' => $invitee->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('form_editor'),
        'invite_expires_at' => now()->addDay(),
        'invite_token' => hash('sha256', 'plain-token'),
    ]);

    $this->get('http://acme.meridian.test/invitations/plain-token')->assertOk();

    // ⚠️ THE INVITE PATH ENFORCES THE SAME PASSWORD POLICY AS REGISTRATION, ASSERTED END-TO-END THROUGH THE
    // ROUTE — because this is the one password surface that has historically declared its own rules, and a
    // trait-level assertion cannot see a controller that stops using the trait. A long, un-breached,
    // lowercase-only passphrase is exactly what J3a's four character classes must reject here.
    $this->from('http://acme.meridian.test/invitations/plain-token')
        ->post('http://acme.meridian.test/invitations/plain-token', [
            'name' => 'Pending Person',
            'password' => 'correct-horse-battery-staple',
        ])->assertSessionHasErrors('password');

    $this->assertGuest();

    $this->post('http://acme.meridian.test/invitations/plain-token', [
        'name' => 'Pending Person',
        'password' => 'Correct-Horse-Battery-9',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    enterTenant($tenant->id, $invitee->id);
    $membership = TenantUser::where('user_id', $invitee->id)->first();
    expect($membership->status)->toBe(TenantUserStatus::Active)
        ->and($membership->joined_at)->not->toBeNull();
    expect(User::find($invitee->id)->hasRole('form_editor'))->toBeTrue();
});
