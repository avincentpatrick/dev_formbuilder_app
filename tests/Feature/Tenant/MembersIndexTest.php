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
        // J3a — the authenticated tenant group carries `verified`; an unstamped identity handed to
        // `actingAs()` is bounced to /email/verify and the page assertions below read as product failures.
        'email_verified_at' => now(),
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
            // false: skip the page-file-exists check — the tests job doesn't build the frontend
            // (repo convention, cf. AppearancePreferenceTest's component('Dashboard', false)).
            ->component('members/Index', false)
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

/*
|--------------------------------------------------------------------------
| Increment M103 — R-17238a20, and the row's HEADLINE IS REFUTED rather than confirmed.
|
| The row said the roster 500s because `listMembers()` builds its identity map from
| `User::query()->whereIn('id', ...)`, and named a soft-deleted user or RLS as the two ways in. Neither
| is reachable: the read is `User::on('pgsql_auth')->withTrashed()`, whose `users_auth_select` policy is
| `USING (true)`, and `tenant_users.user_id` is `constrained('users')->cascadeOnDelete()` so a hard
| delete takes the membership with it. All three legs of the map's totality hold in production.
|
| ⛔ WHAT `M99` ACTUALLY REPRODUCED WAS ITS OWN FIXTURE, AND tests/Pest.php SAYS SO IN ADVANCE: a
| `User::factory()` member is invisible to the separate `pgsql_auth` session under `RefreshDatabase`,
| "and the page 500s on an undefined array key" — warned about in terms, four times, including in the
| very file M99 was writing when it filed this.
|
| ⚠️ SO THIS TEST DRIVES THE DEFECT THROUGH THAT SAME FIXTURE PATH ON PURPOSE, AND THE LIMIT IS STATED
| RATHER THAN PAPERED OVER: it is not a production-reachable state today. The guard is kept because an
| unguarded index into a cross-source map is one schema change from fatal, and because this call site
| was the only one in `app/` written that way — `SuperAdminService` and `AttachmentReferenceValidator`
| both guard the identical lookup. A degraded row is the right answer to "I cannot resolve this
| identity"; a page that will not load is not.
*/

it('renders an identity it cannot resolve rather than failing the whole roster', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $owner = committedMemberUser('Roster Owner');
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    // Deliberately NOT committed, so the `pgsql_auth` session cannot see it — the one construction that
    // produces a membership whose identity the roster's own read cannot return.
    $ghost = User::factory()->create(['name' => 'Invisible Member']);
    makeActiveMember($ghost, 'form_editor');

    $response = $this->actingAs($owner)->withoutVite()
        ->get('http://acme.meridian.test/members')
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('members/Index', false)
        ->has('members', 2)
        // The membership is still SHOWN — an operator deciding whether to remove it needs to see that it
        // exists. Skipping it silently would have been the row's other suggestion, and it would make a
        // roster of only-unresolvable members render "No members yet".
        ->where('members.1.user_id', (string) $ghost->id)
        ->where('members.1.name', 'Unknown user')
        ->where('members.1.email', '')
        // The membership's own facts still resolve: they come from `tenant_users`, not from the identity.
        ->where('members.1.status', TenantUserStatus::Active->value)
        ->where('members.1.role', 'Form Editor')
    );
});

it('keeps the keyword filter honest about a row it cannot resolve', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $owner = committedMemberUser('Nadia Owner');
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $ghost = User::factory()->create(['name' => 'Invisible Member']);
    makeActiveMember($ghost, 'form_editor');

    // ⚠️ The filter matches the strings the roster RENDERS, which for an unresolved row are the
    // placeholders — not the name nobody can read. Pinned because the alternative (filtering on an
    // identity the page cannot show) would silently hide the row from every search including its own.
    $this->actingAs($owner)->withoutVite()
        ->get('http://acme.meridian.test/members?q=Invisible')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('members', 0));

    $this->actingAs($owner)->withoutVite()
        ->get('http://acme.meridian.test/members?q=Unknown')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('members', 1)
            ->where('members.0.name', 'Unknown user')
        );
});
