<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Settings\PlatformSettings;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Self-registration into an OPEN workspace (Increment I5, PRD Feature #10's first criterion).
|
| ⚠️ THE TRAP THIS FILE EXISTS TO CATCH. Fortify's routes carry `['web', RequirePlatformHost,
| AppSecurityHeaders, GateRegistration]` and NO tenancy middleware — so `/register` has no tenant GUC, and
| under `settings`'s nullable_global SELECT policy a tenant's own rows are INVISIBLE there, not absent. A
| naive read returns nothing, falls back to the invite-only default, and makes the "open" position
| unreachable FOREVER. It fails CLOSED, so nothing errors and nothing logs: the only way to see it is a
| test that opens a workspace and then actually registers into it. TenantSettingRegistry::forTenant()
| borrows the tenant's context inside a transaction to answer honestly — and `applyLocal()` is `SET LOCAL`,
| so without that transaction it would be a silent no-op and we would be back here.
|
| The tenant GUC dies with every HTTP request, so every post-request assertion re-`enterTenant()`s first:
| an RLS-invisible membership row reads as ABSENT, which would turn "the join failed" and "the join worked"
| into the same green.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PlatformSettings::class)->forget();
    app(TenantSettingRegistry::class)->forget();

    $this->tenant = inboxTenant();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function openTheWorkspace(string $tenantId): void
{
    enterTenant($tenantId);
    DB::table('settings')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'key' => SettingKey::RegistrationInviteOnly->value,
        'value' => json_encode(false),
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    TenantContext::flush();
    app(TenantSettingRegistry::class)->forget();
}

function closePlatformSignup(): void
{
    DB::connection('pgsql_privileged')->table('settings')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => null,
        'key' => SettingKey::RegistrationOpenSignup->value,
        'value' => json_encode(false),
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(PlatformSettings::class)->forget();
}

it('404s /register on a tenant subdomain by default — invitation only', function (): void {
    // Both verbs. Hiding the form while leaving the handler reachable would be a UI change dressed up as
    // a control. 404 rather than 403 is the non-disclosure posture: a 403 would confirm the workspace
    // exists and is invitation-only, which is the pair of facts a subdomain-prober is after.
    $this->get('http://acme.meridian.test/register')->assertNotFound();

    $this->post('http://acme.meridian.test/register', [
        'name' => 'Nobody',
        'email' => 'nobody@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertNotFound();

    // ⚠️ ASSERTED THROUGH THE SESSION, NOT THROUGH `users`. Two RLS/harness facts make a direct row read
    // useless here: `users` carries the join-shape policy, so a member-less user is INVISIBLE from the
    // ordinary connection (`->exists()` answers false whether or not the row exists — an assertion that
    // proves nothing), and the pre-auth/privileged connections are SEPARATE, so they cannot see anything
    // written inside RefreshDatabase's uncommitted transaction either. Fortify logs a new registrant in
    // immediately, so "still a guest" is the honest, RLS-independent statement of "no account was created".
    $this->assertGuest();
});

it('joins the workspace as a Viewer when it is open, and audits the door it came through', function (): void {
    openTheWorkspace($this->tenant->id);
    $this->withoutVite();

    $this->get('http://acme.meridian.test/register')->assertOk();

    $this->post('http://acme.meridian.test/register', [
        'name' => 'New Joiner',
        'email' => 'joiner@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    $this->assertAuthenticated();

    // Re-enter: the GUC died with the request. `tenant_users` is strict-RLS on the TENANT alone, so it is
    // reachable with no user context — which is why the membership row, not `users`, is the way in here
    // (the join-shape policy on `users` needs an id we do not have yet).
    enterTenant($this->tenant->id);

    $membership = TenantUser::query()->firstOrFail();
    expect($membership->status)->toBe(TenantUserStatus::Active);

    // Now that the id is known, the user is visible under their own context (the policy's self clause).
    enterTenant($this->tenant->id, $membership->user_id);
    $user = User::query()->findOrFail($membership->user_id);
    expect($user->getRoleNames()->all())->toBe(['viewer']);

    $audit = Audit::query()->where('auditable_type', 'tenant_users')->latest('id')->firstOrFail();
    // `via` is the only thing that distinguishes this from an accepted invitation — same membership, same
    // role, different door.
    expect($audit->new_values['via'])->toBe('self_registration');
    expect($audit->new_values['role'])->toBe('viewer');
});

it('leaves central-host registration exactly as it was — an account with no workspace', function (): void {
    $this->withoutVite();

    $this->get('http://meridian.test/register')->assertOk();

    $this->post('http://meridian.test/register', [
        'name' => 'Central Person',
        'email' => 'central@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    $this->assertAuthenticated();

    // The account exists (they are signed in) and belongs to no workspace — the pre-I5 shape, unchanged.
    enterTenant($this->tenant->id);
    expect(TenantUser::query()->exists())->toBeFalse();
});

it('404s /register everywhere once the platform closes signup', function (): void {
    openTheWorkspace($this->tenant->id);
    closePlatformSignup();

    // The platform toggle wins over an open workspace — it is the operator's switch, not a tenant's.
    $this->get('http://acme.meridian.test/register')->assertNotFound();
    $this->get('http://meridian.test/register')->assertNotFound();
});

it('never touches /login — the guard checks the path before it checks anything else', function (): void {
    // GateRegistration sits on config/fortify.php's middleware array, which applies to EVERY Fortify
    // route. Without its `$request->is('register')` short-circuit, closing signup would lock every
    // existing user out of the product.
    closePlatformSignup();
    $this->withoutVite();

    $this->get('http://meridian.test/login')->assertOk();
    $this->get('http://meridian.test/forgot-password')->assertOk();
});

it('shows the create-an-account link only when registration is actually reachable', function (): void {
    $this->withoutVite();

    // Default: the central host is open (the platform toggle defaults true), so the link shows.
    $this->get('http://meridian.test/login')->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login', false)->where('canRegister', true));

    // A tenant subdomain that is invitation-only: same page, no link — and, critically, the SAME gate
    // answers both, so a visible link can never point at a 404.
    $this->get('http://acme.meridian.test/login')->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login', false)->where('canRegister', false));
});
