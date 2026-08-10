<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Search\Arms\MemberSearchArm;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The members arm returns this tenant's active roster and nothing else (Increment J1c).
|
| ⚠️ THE FIXTURE IS THE TEST, AND EVERY ROW KILLS A SPECIFIC MUTATION. All five people below are named
| "Maria", so a NAME assertion could not tell them apart — **every assertion here is on the EMAIL**, which
| is the only field that distinguishes them. That is not fussiness: a name-only assertion would pass while
| returning the wrong tenant's Maria, which is exactly the bug this file exists to catch.
|
|   maria@acme.test     tenant A, active    the only expected result
|   maria@globex.test   tenant B, active    same name, other tenant — deleting the tenant predicate shows it
|   maria@nowhere.test  NO tenant at all    ⭐ the sharpest row: invisible under the app policy AND under any
|                                           tenant_users join, so its appearance proves ONE thing only —
|                                           that the query ran on `pgsql_auth`
|   maria.pending@…     tenant A, invited   pins the "pending members are not searchable" decision
|   maria.gone@…        tenant A, removed   pins that only `active` is searchable
|
| ⚠️ A CROSS-TENANT-ONLY FIXTURE WOULD PROVE NOTHING. Tenant B's row exists inside the SAME
| `RefreshDatabase` transaction as tenant A's, so if the only invisible row were in another tenant, "the
| policy worked" would be indistinguishable from "transaction isolation worked" — a recorded lesson in this
| repo, and why the same-tenant `invited`/`removed` rows are here too.
|
| ── A MUTATION THAT SURVIVES, RECORDED RATHER THAN HIDDEN ────────────────────────────────────────────────
| Deleting the arm's `whereExists` leaves every case below GREEN, because the join-shape `users_visibility`
| policy holds the line underneath it. That predicate is belt-and-braces by design (without it the arm's own
| file would contain no boundary at all), and `SearchMemberConnectionTest`'s structural sweep is what
| compensates for the fact that no functional case can observe it. Distrust the survivor; do not delete it.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenantA = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenantA->domains()->create(['domain' => 'acme']);
    $this->tenantB = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $this->tenantB->domains()->create(['domain' => 'globex']);

    // The user who belongs to no tenant at all. Created before any context is entered, and never given a
    // membership — the only way it can surface is a query with no tenant boundary.
    User::factory()->create(['name' => 'Maria Nowhere', 'email' => 'maria@nowhere.test']);

    $this->ownerA = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@acme.test']);
    enterTenant($this->tenantA->id, $this->ownerA->id);
    makeActiveMember($this->ownerA, 'owner');

    $this->pending = User::factory()->create(['name' => 'Maria Pending', 'email' => 'maria.pending@acme.test']);
    TenantUser::create([
        'tenant_id' => $this->tenantA->id,
        'user_id' => $this->pending->id,
        'status' => TenantUserStatus::Invited->value,
        'invited_at' => now(),
    ]);

    $this->removed = User::factory()->create(['name' => 'Maria Gone', 'email' => 'maria.gone@acme.test']);
    TenantUser::create([
        'tenant_id' => $this->tenantA->id,
        'user_id' => $this->removed->id,
        'status' => TenantUserStatus::Removed->value,
        'joined_at' => now()->subYear(),
    ]);

    // Tenant B's same-named owner, created inside B's context so its membership lands on the right tenant.
    $this->ownerB = User::factory()->create(['name' => 'Maria Santos', 'email' => 'maria@globex.test']);
    enterTenant($this->tenantB->id, $this->ownerB->id);
    makeActiveMember($this->ownerB, 'owner');

    // Back to tenant A for the assertions, re-priming the permission registrar the way the middleware does.
    enterTenant($this->tenantA->id, $this->ownerA->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** @return list<string> the emails the members arm shows $user for $keyword */
function searchedMemberEmails(User $user, string $keyword = 'maria'): array
{
    $rows = app(MemberSearchArm::class)->search($user, SearchTerms::parse($keyword), 50)->rows;
    $emails = array_map(static fn (array $row): string => (string) $row['subtitle'], $rows);
    sort($emails);

    return $emails;
}

it('shows an owner only their own tenant’s active members', function (): void {
    expect(searchedMemberEmails($this->ownerA))->toBe(['maria@acme.test']);
});

it('never returns a same-named member of another tenant, in either direction', function (): void {
    expect(searchedMemberEmails($this->ownerA))->not->toContain('maria@globex.test');

    enterTenant($this->tenantB->id, $this->ownerB->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(searchedMemberEmails($this->ownerB))->toBe(['maria@globex.test']);
});

it('never returns a user who belongs to no tenant, which is the pgsql_auth tell', function (): void {
    // If this row ever appears, the arm is not running where it thinks it is.
    expect(searchedMemberEmails($this->ownerA))->not->toContain('maria@nowhere.test');
});

it('does not surface a pending invite, which is a decision and not an oversight', function (): void {
    // The join-shape policy admits only `status = 'active'`, and RLS applies at every reference to `users`
    // — so this row is unreachable on the app connection even through a `tenant_users` join. Verified
    // against the seeded corpus before the arm was designed; asserted here so the decision is executable.
    expect(searchedMemberEmails($this->ownerA))->not->toContain('maria.pending@acme.test');
});

it('does not surface a removed member', function (): void {
    expect(searchedMemberEmails($this->ownerA))->not->toContain('maria.gone@acme.test');
});

it('derives the member count from the same builder as the rows', function (): void {
    $arm = app(MemberSearchArm::class);
    $terms = SearchTerms::parse('maria');

    expect($arm->count($this->ownerA, $terms))->toBe(count($arm->search($this->ownerA, $terms, 50)->rows))
        ->and($arm->count($this->ownerA, $terms))->toBe(1);
});

it('narrows on both words of a two-word query rather than widening', function (): void {
    // The associativity guard in `KeywordFilter::applyLike()`. Flattened, `(a OR b) AND (c OR d)` becomes
    // `a OR b AND c OR d` and this returns every Maria in the tenant instead of none.
    expect(searchedMemberEmails($this->ownerA, 'maria nowhere'))->toBe([]);
    expect(searchedMemberEmails($this->ownerA, 'maria santos'))->toBe(['maria@acme.test']);
});

it('treats an underscore as a literal, not a wildcard', function (): void {
    // `SearchTerms` KEEPS underscore in its token class, so without `ESCAPE '!'` this would match
    // "maria@acme.test" via single-character wildcards. A real wrong-result bug, not a theoretical one.
    expect(searchedMemberEmails($this->ownerA, 'm_ria'))->toBe([]);
});

it('refuses the arm entirely to a member who cannot reach the roster', function (): void {
    $viewer = User::factory()->create(['name' => 'Maria Viewer', 'email' => 'maria.viewer@acme.test']);
    makeActiveMember($viewer, 'viewer');

    expect(app(MemberSearchArm::class)->allowed($viewer))->toBeFalse()
        ->and(app(MemberSearchArm::class)->allowed($this->ownerA))->toBeTrue();
});
