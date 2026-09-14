<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\TenantUserStatus;
use App\Exceptions\Tenancy\MembershipException;
use App\Models\Domain;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\PointAward;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Auth\OperatorAccounts;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\PlatformHost;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M95 — `tenants:create`, the first workspace on a fresh server.
|--------------------------------------------------------------------------
| ⛔ NO CASE HERE MAY COMMIT A TENANT. The command writes on the default connection, which RefreshDatabase
| wraps, so every tenant it creates rolls back. A committed one would survive into `DatabaseSeederSmokeTest`
| and `DemoSeederIdempotencyTest`, which pin the exact slug list, and into the sweep suites, which assume their
| own tenant is the only active one. Fixtures that must be committed are ACCOUNTS only.
|
| ⚠️ AN EXISTING OWNER MUST BE A COMMITTED IDENTITY. The command resolves the address on `pgsql_auth`, a
| separate session that cannot see RefreshDatabase's uncommitted rows, so a factory user would take the
| create arm and raise 23505. `committedTenantIdentity()` is given this file's marker domain, and
| `createTenantCommandPurge()` deletes those accounts through `beforeApplicationDestroyed` once the test
| transaction has rolled back and released its locks.
|
| ⚠️ `fakeHibp()` IS CALLED INSIDE EACH CASE THAT REACHES A PASSWORD PROMPT, never in a `beforeEach`: the first
| matching `Http` stub wins, so a catch-all registered earlier would answer every later lookup.
|
| Helpers are prefixed `createTenantCommand` because Pest loads every test file into one process.
*/

const CREATE_TENANT_COMMAND_PASSWORD = 'Pilot-Owner-Pass-2026!';

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->beforeApplicationDestroyed(fn () => createTenantCommandPurge());
});

afterEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function createTenantCommandEmail(string $local): string
{
    return $local.'-'.Str::lower(Str::random(10)).'@createtenantcommandtest.local';
}

/** A COMMITTED account on this file's marker domain, which the purge removes. */
function createTenantCommandIdentity(bool $verified = true): User
{
    return committedTenantIdentity('Pilot Owner', $verified, createTenantCommandEmail('owner'));
}

function createTenantCommandPurge(): void
{
    $connection = DB::connection('pgsql_privileged');
    $userIds = $connection->table('users')->where('email', 'like', '%@createtenantcommandtest.local')->pluck('id')->all();

    if ($userIds === []) {
        return;
    }

    $connection->table('audits')->whereIn('user_id', $userIds)->delete();
    $connection->table('users')->whereIn('id', $userIds)->delete();
}

/** The plan catalog, inside the test transaction (PlanSeeder writes on the default connection). */
function createTenantCommandSeedPlans(): void
{
    app(PlanSeeder::class)->run();
}

/** Provision `pilot` for an existing committed owner, asserting success. */
function createTenantCommandProvision(User $owner, string $slug = 'pilot', string $plan = 'starter'): Tenant
{
    test()->artisan('tenants:create', [
        'slug' => $slug,
        'name' => 'Pilot Workspace',
        'owner' => $owner->email,
        '--plan' => $plan,
    ])->assertExitCode(0);

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return Tenant::query()->where('slug', Str::lower($slug))->sole();
}

it('creates a workspace whose new owner can load the dashboard on its own subdomain', function (): void {
    $this->withoutVite();
    fakeHibp();
    createTenantCommandSeedPlans();
    $email = createTenantCommandEmail('founder');

    $this->artisan('tenants:create', [
        'slug' => 'pilot',
        'name' => 'Pilot Workspace',
        'owner' => $email,
        '--plan' => 'starter',
        '--owner-name' => 'Pilot Founder',
    ])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, CREATE_TENANT_COMMAND_PASSWORD)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, CREATE_TENANT_COMMAND_PASSWORD)
        ->expectsOutputToContain('pilot.meridian.test')
        ->expectsOutputToContain('new account')
        ->assertExitCode(0);

    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $tenant = Tenant::query()->where('slug', 'pilot')->sole();
    $ownerId = (string) $tenant->owner_user_id;

    expect($ownerId)->not->toBe('');

    enterTenant((string) $tenant->id, $ownerId);
    $owner = User::query()->findOrFail($ownerId);

    expect($owner->email)->toBe($email)
        ->and($owner->name)->toBe('Pilot Founder')
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and($owner->password_set_at)->not->toBeNull()
        ->and($owner->is_super_admin)->toBeFalse()
        ->and(Hash::check(CREATE_TENANT_COMMAND_PASSWORD, $owner->password))->toBeTrue();

    // Each missing write reddens a different assertion: no label row, no route; an unverified owner is bounced to
    // /email/verify; no membership row fails the tenant middleware; no subscription reads as Free.
    $this->actingAs($owner)
        ->get('http://pilot.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard', false)
            ->where('kpis.members', 1)
            ->where('entitlements.plan.code', 'starter'));
});

it('writes the owner pointer, the label, the membership, one role row and the subscription as one workspace', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();

    $tenant = createTenantCommandProvision($owner);

    expect($tenant->owner_user_id)->toBe($owner->id)
        ->and($tenant->status)->toBe('active');

    $domains = Domain::unscopedQuery()->where('tenant_id', $tenant->id)->get();

    expect($domains)->toHaveCount(1)
        ->and($domains->first()?->domain)->toBe('pilot')
        ->and($domains->first()?->verification_token)->toBeNull()
        ->and($domains->first()?->is_primary)->toBeFalse();

    enterTenant((string) $tenant->id, $owner->id);

    $membership = TenantUser::query()->where('user_id', $owner->id)->sole();

    expect($membership->status)->toBe(TenantUserStatus::Active)
        ->and($membership->invited_by)->toBeNull()
        ->and($membership->joined_at)->not->toBeNull()
        ->and((string) $membership->invited_role_id)->toBe(catalogRole('owner'));

    $roleIds = DB::table('model_has_roles')
        ->where('tenant_id', $tenant->id)
        ->where('model_id', $owner->id)
        ->pluck('role_id')
        ->map(fn ($id): string => (string) $id)
        ->all();

    expect($roleIds)->toBe([catalogRole('owner')]);

    $subscription = Subscription::query()->active()->sole();

    expect($subscription->name)->toBe('default')
        ->and($subscription->plan->code)->toBe(PlanTier::Starter);

    // The pointer is what the Owner guard reads. A workspace written without it lets an Admin remove its Owner.
    $admin = apiMember('admin');

    expect(fn () => app(TenantMembershipService::class)->remove($tenant, $owner, $admin))
        ->toThrow(MembershipException::class, MembershipException::cannotRemoveOwner()->getMessage());
});

it('resolves the new workspace from its host the way a request does', function (): void {
    createTenantCommandSeedPlans();
    $tenant = createTenantCommandProvision(createTenantCommandIdentity());

    expect(PlatformHost::tenantFor('pilot.meridian.test')?->getKey())->toBe($tenant->getKey());
});

it('refuses a slug the runtime cannot route, writing nothing', function (string $slug): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $tenantsBefore = Tenant::query()->count();
    $domainsBefore = Domain::unscopedQuery()->count();

    $this->artisan('tenants:create', ['slug' => $slug, 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->assertExitCode(2);

    expect(Tenant::query()->count())->toBe($tenantsBefore)
        ->and(Domain::unscopedQuery()->count())->toBe($domainsBefore);
})->with([
    'a dot' => ['pilot.team'],
    'a leading hyphen' => ['-pilot'],
    'a trailing hyphen' => ['pilot-'],
    'an underscore' => ['pi_lot'],
    'a space' => ['pi lot'],
    'sixty-four characters' => [str_repeat('a', 64)],
    'the reserved www' => ['www'],
    'the reserved www in capitals' => ['WWW'],
]);

it('normalises case so the slug, the label and the owner address all agree', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();

    $this->artisan('tenants:create', ['slug' => 'Pilot', 'name' => 'Pilot', 'owner' => Str::upper($owner->email), '--plan' => 'starter'])
        ->assertExitCode(0);

    $tenant = Tenant::query()->where('slug', 'pilot')->sole();

    expect(Tenant::query()->where('slug', 'Pilot')->exists())->toBeFalse()
        ->and(Domain::unscopedQuery()->where('domain', 'pilot')->where('tenant_id', $tenant->id)->exists())->toBeTrue()
        ->and($tenant->owner_user_id)->toBe($owner->id);
});

it('refuses when the plan catalog is not seeded, naming the seeders by class', function (): void {
    // PlanSeeder deliberately not run. The ROLE catalog cannot be made absent here: RolePermissionSeeder commits
    // on pgsql_privileged and is shared by every file in the process, so that arm is not faked.
    expect(Plan::query()->count())->toBe(0, 'precondition: no plan catalog is visible to this test');

    $owner = createTenantCommandIdentity();
    $tenantsBefore = Tenant::query()->count();

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('--class=RolePermissionSeeder')
        ->expectsOutputToContain('--class=PlanSeeder')
        ->assertExitCode(1);

    expect(Tenant::query()->count())->toBe($tenantsBefore);
});

it('reuses an existing verified account without touching its password', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $hashBefore = DB::connection('pgsql_privileged')->table('users')->where('id', $owner->id)->value('password');

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('existing account')
        ->assertExitCode(0);

    expect(Tenant::query()->where('slug', 'pilot')->sole()->owner_user_id)->toBe($owner->id)
        ->and(DB::connection('pgsql_privileged')->table('users')->where('id', $owner->id)->value('password'))->toBe($hashBefore)
        ->and(DB::connection('pgsql_privileged')->table('users')->where('email', $owner->email)->count())->toBe(1);
});

it('refuses an existing account whose address was never verified', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity(verified: false);

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('never verified')
        ->assertExitCode(1);

    expect(Tenant::query()->where('slug', 'pilot')->exists())->toBeFalse();
});

it('refuses a missing plan and an unknown plan as bad input', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email])
        ->expectsOutputToContain('--plan is required')
        ->assertExitCode(2);

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'gold'])
        ->expectsOutputToContain("Unknown plan 'gold'")
        ->assertExitCode(2);

    expect(Tenant::query()->where('slug', 'pilot')->exists())->toBeFalse();
});

it('writes nothing on an identical second run', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $tenant = createTenantCommandProvision($owner);

    $counts = function () use ($tenant, $owner): array {
        enterTenant((string) $tenant->id, $owner->id);

        $snapshot = [
            'tenants' => Tenant::query()->count(),
            'domains' => Domain::unscopedQuery()->count(),
            'tenant_users' => TenantUser::query()->count(),
            'model_has_roles' => DB::table('model_has_roles')->where('tenant_id', $tenant->id)->count(),
            'subscriptions' => Subscription::query()->count(),
            'point_awards' => PointAward::query()->count(),
            'notifications' => Notification::query()->count(),
            'owner_accounts' => DB::connection('pgsql_privileged')->table('users')->where('email', $owner->email)->count(),
            'tenant_updated_at' => (string) DB::table('tenants')->where('id', $tenant->id)->value('updated_at'),
        ];

        TenantContext::flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $snapshot;
    };

    $before = $counts();

    // The idempotency reads must run under the tenant's own context. Read bare, the three strict tables return no
    // rows and this re-run would be refused as half-provisioned.
    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot Workspace', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('already provisioned')
        ->assertExitCode(0);

    expect($counts())->toBe($before);
});

it('refuses a slug that belongs to another owner, changing nothing', function (): void {
    createTenantCommandSeedPlans();
    $first = createTenantCommandIdentity();
    $second = createTenantCommandIdentity();
    $tenant = createTenantCommandProvision($first);

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot Workspace', 'owner' => $second->email, '--plan' => 'starter'])
        ->expectsOutputToContain('different account')
        ->assertExitCode(1);

    expect($tenant->refresh()->owner_user_id)->toBe($first->id);

    enterTenant((string) $tenant->id, $first->id);

    expect(TenantUser::query()->count())->toBe(1);
});

it('refuses the same workspace on a different plan and points at the audited console path', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $tenant = createTenantCommandProvision($owner, plan: 'starter');

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot Workspace', 'owner' => $owner->email, '--plan' => 'business'])
        ->expectsOutputToContain('/admin/tenants')
        ->assertExitCode(1);

    enterTenant((string) $tenant->id, $owner->id);

    expect(Subscription::query()->active()->sole()->plan->code)->toBe(PlanTier::Starter);
});

it('refuses a half-provisioned workspace instead of adopting it', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $tenant = inboxTenant('pilot');

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('no Owner')
        ->expectsOutputToContain('never adopts')
        ->assertExitCode(1);

    expect($tenant->refresh()->owner_user_id)->toBeNull();

    enterTenant((string) $tenant->id);

    expect(TenantUser::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);
});

it('refuses a label already held by another workspace', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'pilot']);

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('already routes')
        ->assertExitCode(1);

    expect(Tenant::query()->where('slug', 'pilot')->exists())->toBeFalse();
});

it('refuses a new owner password that misses a character class', function (): void {
    fakeHibp();
    createTenantCommandSeedPlans();
    $tenantsBefore = Tenant::query()->count();

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => createTenantCommandEmail('weak'), '--plan' => 'starter'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, 'correct-horse-battery-staple')
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, 'correct-horse-battery-staple')
        ->expectsOutputToContain('uppercase')
        ->assertExitCode(2);

    expect(Tenant::query()->count())->toBe($tenantsBefore);

    Http::assertSentCount(0);
});

it('refuses a new owner password whose confirmation does not match', function (): void {
    fakeHibp();
    createTenantCommandSeedPlans();
    $tenantsBefore = Tenant::query()->count();

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => createTenantCommandEmail('mismatch'), '--plan' => 'starter'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, CREATE_TENANT_COMMAND_PASSWORD)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, CREATE_TENANT_COMMAND_PASSWORD.'x')
        ->expectsOutputToContain('do not match')
        ->assertExitCode(2);

    expect(Tenant::query()->count())->toBe($tenantsBefore);

    Http::assertNothingSent();
});

it('refuses to create a new owner from a non-interactive run', function (): void {
    fakeHibp();
    createTenantCommandSeedPlans();
    $tenantsBefore = Tenant::query()->count();

    $this->artisan('tenants:create', [
        'slug' => 'pilot',
        'name' => 'Pilot',
        'owner' => createTenantCommandEmail('scripted'),
        '--plan' => 'starter',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('interactive console session')
        ->assertExitCode(2);

    expect(Tenant::query()->count())->toBe($tenantsBefore);
});

it('refuses a soft-deleted account as owner', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    DB::connection('pgsql_privileged')->table('users')->where('id', $owner->id)->update(['deleted_at' => now()]);

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $owner->email, '--plan' => 'starter'])
        ->expectsOutputToContain('is deleted')
        ->assertExitCode(1);

    expect(Tenant::query()->where('slug', 'pilot')->exists())->toBeFalse();
});

it('refuses a platform super-admin as owner', function (): void {
    createTenantCommandSeedPlans();
    $operator = committedSuperAdmin(createTenantCommandEmail('operator'));

    $this->artisan('tenants:create', ['slug' => 'pilot', 'name' => 'Pilot', 'owner' => $operator->email, '--plan' => 'starter'])
        ->expectsOutputToContain('platform super-admin')
        ->assertExitCode(1);

    expect(Tenant::query()->where('slug', 'pilot')->exists())->toBeFalse();
});

it('awards the founding owner the welcome points and sends no member-joined notice', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $tenant = createTenantCommandProvision($owner);

    enterTenant((string) $tenant->id, $owner->id);

    expect(PointAward::query()->where('user_id', $owner->id)->where('rule', PointRule::MemberJoined->value)->count())->toBe(1)
        ->and(Notification::query()->where('type', NotificationType::MemberJoined->value)->count())->toBe(0);
});

it('writes no audit row, the deliberate gap its docblock records', function (): void {
    createTenantCommandSeedPlans();
    $owner = createTenantCommandIdentity();
    $tenant = createTenantCommandProvision($owner);

    enterTenant((string) $tenant->id, $owner->id);

    expect(DB::table('audits')->where('tenant_id', $tenant->id)->count())->toBe(0);
});
