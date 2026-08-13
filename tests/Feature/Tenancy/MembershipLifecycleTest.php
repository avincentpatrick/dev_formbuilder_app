<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B2b — tenant membership lifecycle (multi-tenancy-rbac-design.md §7).
|--------------------------------------------------------------------------
| Exercises the service against real PostgreSQL, through the same RLS + Spatie-team context a request
| establishes: invite reserves a role but grants nothing; accept materializes it; remove/transfer apply
| their side effects atomically; and an invite is only ever visible/actionable within its own tenant.
| The global role catalog is seeded on the privileged connection and (as in B2a) not cleaned up — a
| privileged DELETE of a parent `roles` row would deadlock the uncommitted child assignments.
| enterTenant/catalogRole/makeActiveMember are the shared helpers in tests/Pest.php.
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

it('invites with a hashed token, reserves the role, and grants nothing until acceptance', function (): void {
    Notification::fake();
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);

    $invite = app(TenantMembershipService::class)->invite($tenant, 'newbie@membertest.local', 'form_editor', $admin);

    expect($invite->status)->toBe(TenantUserStatus::Invited)
        ->and($invite->invited_role_id)->toBe(catalogRole('form_editor'))
        ->and($invite->invite_token)->toHaveLength(64)      // sha256 hex, never the plaintext
        ->and($invite->invite_expires_at->isFuture())->toBeTrue();

    // H3: the invitation is a QUEUED, on-demand mail notification carrying only scalars (the tenant
    // name + a pre-built accept URL) — never a Tenant model. Sent to the invited address, not a User.
    Notification::assertSentOnDemand(
        TenantInvitationNotification::class,
        fn (TenantInvitationNotification $n, array $channels, object $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'newbie@membertest.local'
            && $n->tenantName === 'Alpha'
            && str_contains($n->acceptUrl, '/invitations/')
    );

    // The reserved role is not yet a real grant (§7): the placeholder user has no role.
    enterTenant($tenant->id, $invite->user_id); // the placeholder is visible to itself
    expect(User::find($invite->user_id)->hasRole('form_editor'))->toBeFalse();
});

it('refuses to invite someone directly as Owner', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);

    expect(fn () => app(TenantMembershipService::class)->invite($tenant, 'x@membertest.local', 'owner', $admin))
        ->toThrow(MembershipException::class);
});

it('materializes the reserved role on acceptance', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    $invite = TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('admin'),
        'invite_expires_at' => now()->addDay(),
        'invite_token' => hash('sha256', 'tok'),
    ]);

    app(TenantMembershipService::class)->accept($invite, $user);

    expect($invite->fresh()->status)->toBe(TenantUserStatus::Active)
        ->and($invite->fresh()->joined_at)->not->toBeNull()
        ->and($invite->fresh()->invite_token)->toBeNull(); // consumed
    $user->unsetRelation('roles');
    expect($user->hasRole('admin'))->toBeTrue();
});

it('grants nothing when an invitation is declined', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    $invite = TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('admin'),
        'invite_expires_at' => now()->addDay(),
        'invite_token' => hash('sha256', 'tok'),
    ]);

    app(TenantMembershipService::class)->decline($invite);

    expect($invite->fresh()->status)->toBe(TenantUserStatus::Declined);
    $user->unsetRelation('roles');
    expect($user->hasRole('admin'))->toBeFalse();
});

it('refuses to accept an expired invitation', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    $invite = TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('admin'),
        'invite_expires_at' => now()->subDay(),
        'invite_token' => hash('sha256', 'tok'),
    ]);

    expect(fn () => app(TenantMembershipService::class)->accept($invite, $user))
        ->toThrow(MembershipException::class);
    expect($invite->fresh()->status)->toBe(TenantUserStatus::Invited);
});

it('removes a member atomically: status, role, and tenant tokens all gone', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'owner_user_id' => $owner->id]);
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($member, 'admin');
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => $member->getMorphClass(),
        'tokenable_id' => $member->id,
        'tenant_id' => $tenant->id,
        'name' => 'cli',
        'token' => hash('sha256', 'a-token'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(TenantMembershipService::class)->remove($tenant, $member, $owner);

    $membership = TenantUser::where('user_id', $member->id)->first();
    expect($membership->status)->toBe(TenantUserStatus::Removed)
        ->and($membership->removed_by)->toBe($owner->id);
    $member->unsetRelation('roles');
    expect($member->hasRole('admin'))->toBeFalse();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $member->id)->count())->toBe(0);
});

it('will not remove the tenant Owner (transfer first)', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'owner_user_id' => $owner->id]);
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    expect(fn () => app(TenantMembershipService::class)->remove($tenant, $owner, $owner))
        ->toThrow(MembershipException::class);
    expect(TenantUser::where('user_id', $owner->id)->first()->status)->toBe(TenantUserStatus::Active);
});

it('transfers ownership atomically: new Owner promoted, old Owner demoted to Admin', function (): void {
    $owner = User::factory()->create();
    $successor = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'owner_user_id' => $owner->id]);
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    makeActiveMember($successor, 'admin');

    app(TenantMembershipService::class)->transferOwnership($tenant, $successor, $owner);

    expect($tenant->fresh()->owner_user_id)->toBe($successor->id);
    $successor->unsetRelation('roles');
    $owner->unsetRelation('roles');
    expect($successor->hasRole('owner'))->toBeTrue()
        ->and($successor->hasRole('admin'))->toBeFalse()
        ->and($owner->hasRole('admin'))->toBeTrue()   // never left roleless
        ->and($owner->hasRole('owner'))->toBeFalse();
});

it('keeps invitations isolated to their own tenant', function (): void {
    $tenantA = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $tenantB = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    $user = User::factory()->create();

    enterTenant($tenantA->id, $user->id);
    $invite = TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('admin'),
        'invite_expires_at' => now()->addDay(),
        'invite_token' => hash('sha256', 'shared-token'),
    ]);

    // Under B's context the very same token resolves to nothing (strict RLS on tenant_users).
    enterTenant($tenantB->id, $user->id);
    expect(TenantUser::where('invite_token', hash('sha256', 'shared-token'))->exists())->toBeFalse();

    // …but under A's it is right there.
    enterTenant($tenantA->id, $user->id);
    expect(TenantUser::where('invite_token', hash('sha256', 'shared-token'))->first()->id)->toBe($invite->id);
});

/*
|--------------------------------------------------------------------------
| The shared attach path (P1c) — reactivation is right for three statuses and wrong for one
|--------------------------------------------------------------------------
|
| `joinOpenTenant()` and `joinViaSso()` share `attachMember()` entirely, and that body REACTIVATES whatever
| non-Active row it finds. Correct for `Declined` and `Removed`, which both mean "not currently a member" —
| exactly what both doors are for. Wrong for `Suspended`, which is an administrative sanction: a door that
| quietly reversed one would make the sanction unenforceable in the workspaces most likely to rely on it.
|
| ⚠️ THIS IS A LATENT HOLE, NOT A LIVE ONE, AND THE DISTINCTION IS ASSERTED RATHER THAN ASSUMED. Nothing in
| the application writes `Suspended` today — the enum case has no producer — and `CreateNewUser` validates
| `Rule::unique('pgsql_auth.users','email')`, so a suspended member cannot re-register under their own
| address to reach the open-registration door anyway. The service is driven directly here for that reason:
| there is no HTTP path that can reach this state, which is precisely why the guard needs a test rather
| than a comment.
*/

it('refuses to reactivate a suspended membership through the open-registration door', function (): void {
    $tenant = Tenant::create(['name' => 'Sanctioned', 'slug' => 'sanctioned']);
    $user = User::factory()->create();

    enterTenant($tenant->id, $user->id);
    $membership = TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Suspended,
        'invited_role_id' => catalogRole('viewer'),
        'joined_at' => now()->subMonth(),
    ]);

    // Null is the same answer a full workspace gives, and `JoinTenantOnRegistration` already handles it:
    // the person keeps their account and gains no workspace.
    expect(app(TenantMembershipService::class)->joinOpenTenant($tenant, $user))->toBeNull();

    enterTenant($tenant->id, $user->id);
    expect($membership->fresh()->status)->toBe(TenantUserStatus::Suspended);
    // And no role was materialized on the way past — the half that would survive a status rollback.
    expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(0);
});

it('still reactivates a declined or removed membership, which is what the door is for', function (string $status): void {
    $tenant = Tenant::create(['name' => 'Reopened', 'slug' => 'reopened'.substr($status, 0, 3)]);
    $user = User::factory()->create();

    enterTenant($tenant->id, $user->id);
    TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::from($status),
        'invited_role_id' => catalogRole('viewer'),
    ]);

    expect(app(TenantMembershipService::class)->joinOpenTenant($tenant, $user))->not->toBeNull();

    enterTenant($tenant->id, $user->id);
    expect(TenantUser::query()->where('user_id', $user->id)->value('status'))->toBe(TenantUserStatus::Active);
})->with(['declined', 'removed']);
