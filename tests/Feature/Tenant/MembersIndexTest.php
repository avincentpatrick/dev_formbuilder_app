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
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment C3 — Members roster page.
|--------------------------------------------------------------------------
| Proves the page is gated to Owner/Admin (the closed catalog has no members.view, so it rides the
| manage ability), and that listMembers resolves BOTH active members and pending invites — including a
| pending placeholder user's identity, which the join-shape users RLS hides on the app connection and
| listMembers therefore resolves on pgsql_auth. Because pgsql_auth is a separate session it cannot see
| RefreshDatabase's uncommitted rows, so member users are seeded COMMITTED on pgsql_privileged (the B1
| pattern) and left for migrate:fresh — never DELETEd in afterEach (that deadlocks against open locks).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A committed identity (visible to the separate pgsql_auth session), reset to the app connection. */
function committedMemberUser(string $name): User
{
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => $name,
        'email' => Str::lower(Str::random(10)).'@acme.test',
        'password' => Hash::make('secret-password-123'),
    ]);
    $user->setConnection((string) config('database.default'));

    return $user;
}

it('forbids a member without the manage permission', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)->withoutVite()
        ->get('http://acme.meridian.test/members')
        ->assertForbidden();
});

it('lists active members and pending invites with resolved identities', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $owner = committedMemberUser('Owner One');
    $pending = committedMemberUser('Pending Pat');

    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    TenantUser::create([
        'user_id' => $pending->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('viewer'),
        'invited_at' => now(),
        'invite_expires_at' => now()->addDays(7),
        'invite_token' => hash('sha256', Str::random(48)),
    ]);

    $this->actingAs($owner)->withoutVite()
        ->get('http://acme.meridian.test/members')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('members/Index')
            ->has('members', 2)
            // Owner sorts first (is_owner, then active before invited).
            ->where('members.0.is_owner', true)
            ->where('members.0.status', 'active')
            ->where('members.0.role', 'Owner')
            ->where('members.0.email', $owner->email)
            // The pending invite's identity is resolved via pgsql_auth despite the users RLS hiding it.
            ->where('members.1.status', 'invited')
            ->where('members.1.role', 'Viewer')
            ->where('members.1.email', $pending->email)
            ->where('assignableRoles.0.value', 'admin'));
});
