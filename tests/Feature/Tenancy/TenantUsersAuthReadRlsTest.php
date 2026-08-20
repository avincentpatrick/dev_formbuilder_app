<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M8 — the pre-auth role's read carve-out on `tenant_users`.
|--------------------------------------------------------------------------
| `2026_08_17_000107` grants `meridian_auth` SELECT on `tenant_users` and layers the role-scoped permissive
| policy `tenant_users_auth_select ... USING (true)`. `InvitationController` needs it to answer one question
| before anybody is authenticated — "has this identity ever actually joined a workspace?" — whose only
| positive record lives, by definition, in another tenant's rows.
|
| FOUR things have to be true, and only the first is obvious:
|
|   1. The pre-auth role CAN read memberships across tenants.
|   2. **The GRANT and the POLICY are asserted SEPARATELY.** Table privileges are checked BEFORE RLS, so a
|      missing GRANT fails with "permission denied" before any policy is consulted — and a test that only
|      checked the functional read would pass for the wrong reason, or fail while blaming the wrong half.
|   3. **Nothing changed for anyone else.** The policy carries `TO meridian_auth`, so an ordinary tenant
|      connection is untouched and still cannot see another tenant's memberships. This is the assertion
|      that would catch a carve-out written without the `TO` clause — the mistake that turns an
|      authorization lookup into a cross-tenant directory of who works where.
|   4. **The absence of write policies is the write refusal.** SELECT was granted and nothing else, so the
|      pre-auth role cannot author or alter a membership. A later "for symmetry" INSERT fails here first.
|
| ⚠️ EVERY QUERY BELOW USES THE RAW BUILDER, NEVER `TenantUser`. `BelongsToTenant` adds its own
| `where tenant_id = <context ?? NO_TENANT_SENTINEL>`, which would make these assertions pass whether the
| database policies existed or not — the ORM would be doing the hiding and this file would be green over a
| carve-out that leaked. Dropping it is what puts the question to POSTGRES, the only layer ADR-0002 treats
| as the guarantee. `TenantTableClassificationDriftTest` and `CrossTenantIsolationTest` make the same move.
|
| Fixtures are committed on the privileged connection because `pgsql_auth` is a SEPARATE SESSION and cannot
| see RefreshDatabase's uncommitted rows — which is the same fact the carve-out exists to work with. They
| carry the `authread-` slug marker and the `@authreadrlstest.local` email marker, and are purged by both.
*/

function authReadTenant(string $suffix): Tenant
{
    $id = Uuid::uuid7()->toString();

    // The query builder, not `Tenant::on('pgsql_privileged')`: stancl's `CentralConnection` trait makes
    // `getConnectionName()` ignore the override, so the model version writes inside the test transaction
    // and the committed FK insert below then fails somewhere else entirely.
    DB::connection('pgsql_privileged')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Auth Read '.$suffix,
        'slug' => 'authread-'.$suffix,
        'status' => 'active',
        'default_locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->findOrFail($id);

    return $tenant;
}

function authReadUser(): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => 'Auth Read Member',
        'email' => Str::lower(Str::random(10)).'@authreadrlstest.local',
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
    ]);

    return $user;
}

function authReadMembership(Tenant $tenant, User $user): string
{
    $id = Uuid::uuid7()->toString();

    DB::connection('pgsql_privileged')->table('tenant_users')->insert([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'status' => TenantUserStatus::Active->value,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function purgeCommittedAuthReadFixtures(): void
{
    $connection = DB::connection('pgsql_privileged');

    // Markers, not ids, so a case that dies mid-way is cleaned up by the next one. Deleting the tenant
    // cascades its `tenant_users` rows (`constrained()->cascadeOnDelete()`), so memberships need no
    // separate sweep — but the users do, because a membership's user outlives its workspace.
    $connection->table('tenants')->where('slug', 'like', 'authread-%')->delete();
    $connection->table('users')->where('email', 'like', '%@authreadrlstest.local')->delete();
}

beforeEach(function (): void {
    TenantContext::flush();
    purgeCommittedAuthReadFixtures();
    $this->beforeApplicationDestroyed(purgeCommittedAuthReadFixtures(...));
});

it('grants the pre-auth role SELECT on tenant_users, which RLS never gets to answer without', function (): void {
    // Half one of two, asserted on its own: privileges are checked BEFORE policies, so without this the
    // query dies as "permission denied for table tenant_users" and the policy is never consulted at all.
    $granted = DB::selectOne(
        "select has_table_privilege('meridian_auth', 'tenant_users', 'SELECT')::int as ok"
    );

    expect((int) $granted->ok)->toBe(1);
});

it('scopes the tenant_users read policy TO the pre-auth role and nothing wider', function (): void {
    // Half two. The `TO` clause is the entire difference between an authorization lookup and a
    // cross-tenant directory, and `roles` is where Postgres records it — so it is asserted directly rather
    // than inferred from the functional read below.
    $policy = DB::selectOne(
        "select roles::text as roles, qual, cmd from pg_policies
         where tablename = 'tenant_users' and policyname = 'tenant_users_auth_select'"
    );

    expect($policy)->not->toBeNull()
        ->and($policy->cmd)->toBe('SELECT')
        ->and($policy->roles)->toBe('{meridian_auth}')
        ->and($policy->qual)->toBe('true');
});

it('lets the pre-auth role read memberships from every tenant', function (): void {
    $userA = authReadUser();
    $userB = authReadUser();
    $idA = authReadMembership(authReadTenant(Str::lower(Str::random(6))), $userA);
    $idB = authReadMembership(authReadTenant(Str::lower(Str::random(6))), $userB);

    $seen = DB::connection('pgsql_auth')
        ->table('tenant_users')
        ->whereIn('id', [$idA, $idB])
        ->pluck('id')
        ->all();

    expect($seen)->toHaveCount(2);
});

it('leaves the ordinary tenant connection exactly as it was', function (): void {
    // ⚠️ THE ASSERTION THAT WOULD CATCH A CARVE-OUT WRITTEN WITHOUT ITS `TO` CLAUSE. A permissive policy
    // with no role scope OR-combines into the strict shape for EVERY role, silently repealing tenant
    // isolation on the membership table — and every other test in the suite would stay green.
    $mine = authReadTenant(Str::lower(Str::random(6)));
    $theirs = authReadTenant(Str::lower(Str::random(6)));
    $theirMembershipId = authReadMembership($theirs, authReadUser());

    // Inside the transaction: applyLocal() is `SET LOCAL` and is discarded outside one.
    TenantContext::applyLocal($mine->id, null);

    $visible = DB::table('tenant_users')->where('id', $theirMembershipId)->count();

    expect($visible)->toBe(0);
});

it('refuses a write through the pre-auth role, because SELECT is all it was granted', function (): void {
    // The deliberate absence, pinned. Under FORCE RLS a command with no matching policy is denied anyway,
    // and the GRANT stops it one layer earlier still — so a future "add INSERT for symmetry" has to come
    // past this case and explain itself.
    $user = authReadUser();
    $tenant = authReadTenant(Str::lower(Str::random(6)));

    expect(fn (): mixed => DB::connection('pgsql_auth')->table('tenant_users')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'status' => TenantUserStatus::Active->value,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('pins the COMPLETE set of privileges the pre-auth role holds, so the next widening is loud', function (): void {
    // \u26a0\ufe0f M8 CORRECTED SIX PROSE SITES THAT SAID `meridian_auth` REACHES "`users` AND NOTHING ELSE", AND
    // NOT ONE OF THEM WAS A TEST \u2014 which is why the sentence stayed false-able. Every other assertion in
    // this file is POSITIVE (this grant exists, this policy exists), and a positive assertion cannot notice
    // a third grant arriving. `TenantTableClassificationDriftTest` cannot either: it filters policies on
    // `qual LIKE '%current_tenant_id%'`, and a role carve-out is `USING (true)`, so it is structurally
    // invisible there. `scripts/migration-lint.php` has no GRANT awareness at all.
    //
    // So this is the manifest, in the house style of `PlatformAuditRlsTest`'s "and nothing more": the exact
    // set, not a subset. A future `GRANT SELECT ON forms TO meridian_auth` reddens HERE, at the migration
    // that adds it, instead of quietly re-falsifying six comments and RBAC \u00a79 for a second time.
    $rows = DB::select(
        "select table_name, privilege_type from information_schema.role_table_grants
         where grantee = 'meridian_auth' and table_schema = 'public'
         order by table_name, privilege_type"
    );

    $granted = array_map(
        static fn (object $r): string => $r->table_name.':'.$r->privilege_type,
        $rows
    );

    expect($granted)->toBe([
        'tenant_users:SELECT',  // M8 \u2014 2026_08_17_000107, the invitation identity check. READ ONLY.
        'users:SELECT',         // Phase 0 \u2014 apply_users_rls.php, the pre-auth resolve.
        'users:UPDATE',         // Phase 0 \u2014 the password-reset save.
    ]);
});
