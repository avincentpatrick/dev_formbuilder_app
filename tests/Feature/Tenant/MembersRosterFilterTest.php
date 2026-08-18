<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Search\Arms\MemberSearchArm;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The `/members` roster keyword filter (Increment J1e).
|
| ══════════════════════════════════════════════════════════════════════════════════════════════════════
| ⚠️ THIS FILE GUARDS J1c'S MEASURED LEAK, ONE INCREMENT LATER.
| ══════════════════════════════════════════════════════════════════════════════════════════════════════
| `listMembers()` resolves identities on `pgsql_auth`, where `users_auth_select ... USING (true)` means there
| is NO tenant boundary of any kind — that policy exists so the pre-auth login path can resolve an identity
| before any tenant context is established. The hop is safe today for exactly one reason: the id set comes
| from an RLS-bounded `tenant_users` read BEFORE it, and the method adds no predicate of its own.
|
| A keyword IS a predicate. J1c measured what pushing one down would cost, on the seeded corpus:
| `email ILIKE '%o%'` returns 8 rows on `pgsql_auth` INCLUDING another tenant's `owner@northwind.test`,
| against 6 on the app connection.
|
| So the filter runs in PHP over already-bounded rows, and the case below asserts that STRUCTURALLY rather
| than by outcome: it listens on the `pgsql_auth` connection and fails if the user's text ever appears in a
| binding. An outcome-only test would pass just as happily against an implementation that fetched every
| tenant's matching users and filtered the leak out in PHP afterwards.
|
| ⚠️ EVERY IDENTITY HERE IS SEEDED **COMMITTED** ON `pgsql_privileged` (the B1 pattern `MembersIndexTest`
| documents), AND FOR THIS FILE THAT IS LOAD-BEARING RATHER THAN PLUMBING. `pgsql_auth` is a separate
| session and cannot see `RefreshDatabase`'s uncommitted rows — so a factory-created "belongs to no tenant"
| row would be invisible to a leaking query too, and the sharpest assertion in the file would pass while
| proving nothing. Emails are RANDOM because these rows outlive the transaction and are cleaned up only by
| `migrate:fresh`; a fixed address would collide on the second run and pollute every later file. Never
| DELETE them in an `afterEach` — that deadlocks against the open locks.
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

    // Belongs to nobody, and COMMITTED so a leaking query could genuinely see it. Only a read with no
    // tenant boundary can ever return this row — which is what makes its absence meaningful.
    $this->nowhere = committedIdentity('Maria Nowhere');

    $this->ownerA = committedIdentity('Maria Santos');
    enterTenant($this->tenantA->id, $this->ownerA->id);
    makeActiveMember($this->ownerA, 'owner');
    $this->tenantA->forceFill(['owner_user_id' => $this->ownerA->id])->save();

    $this->editor = committedIdentity('Bruno Reyes');
    makeActiveMember($this->editor, 'form_editor');

    // A pending invite: rendered on THIS page and structurally invisible to `MemberSearchArm`.
    $this->pending = committedIdentity('Maria Pending');
    TenantUser::create([
        'user_id' => $this->pending->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('viewer'),
        'invited_at' => now(),
        'invite_expires_at' => now()->addDays(7),
        'invite_token' => hash('sha256', Str::random(48)),
    ]);

    // Tenant B's same-NAMED owner, created inside B's context so its membership lands on the right tenant.
    $this->ownerB = committedIdentity('Maria Santos');
    enterTenant($this->tenantB->id, $this->ownerB->id);
    makeActiveMember($this->ownerB, 'owner');

    enterTenant($this->tenantA->id, $this->ownerA->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A COMMITTED identity, visible to the separate `pgsql_auth` session. See the ⚠️ block above. */
function committedIdentity(string $name): User
{
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => $name,
        'email' => Str::lower(Str::random(12)).'@identity.test',
        'password' => Hash::make('secret-password-123'),
        // J3a — the authenticated tenant group carries `verified`; an unstamped identity handed to
        // `actingAs()` is bounced to /email/verify and the page assertions below read as product failures.
        'email_verified_at' => now(),
    ]);
    $user->setConnection((string) config('database.default'));

    return $user;
}

/** @return list<string> the emails the roster shows for $keyword, sorted */
function rosterEmails(?string $keyword): array
{
    $rows = app(TenantMembershipService::class)->listMembers(
        Tenant::findOrFail(TenantContext::currentTenantId()),
        $keyword === null ? null : SearchTerms::parse($keyword),
    );

    $emails = array_map(static fn (array $row): string => $row['email'], $rows);
    sort($emails);

    return $emails;
}

it('narrows the roster on name and on email', function (): void {
    expect(rosterEmails('bruno'))->toBe([$this->editor->email])
        ->and(rosterEmails('reyes'))->toBe([$this->editor->email])
        // The email branch, matched on a fragment of the address rather than the name.
        ->and(rosterEmails(explode('@', (string) $this->editor->email)[0]))->toBe([$this->editor->email]);
});

it('returns the whole roster when no keyword is given, which is what keeps the parameter optional', function (): void {
    // `MembersIndexTest` calls `listMembers($tenant)` with one argument and passes UNEDITED — the J1b proof
    // that a signature change moved nothing. This case states that contract where it can fail loudly.
    expect(rosterEmails(null))->toHaveCount(3)
        ->toContain($this->ownerA->email)
        ->toContain($this->editor->email)
        ->toContain($this->pending->email);
});

it('never lets a keyword reach the pgsql_auth connection, which is the whole security design', function (): void {
    $authBindings = [];

    DB::connection('pgsql_auth')->listen(function ($query) use (&$authBindings): void {
        foreach ($query->bindings as $binding) {
            if (is_string($binding)) {
                $authBindings[] = $binding;
            }
        }
    });

    rosterEmails('maria');

    // The hop still HAPPENS — it is how a pending invite's identity is resolved at all — so "issued no
    // queries" would be the wrong assertion and would go green if someone deleted the feature. What must
    // never appear on that connection is the user's text, in any form a predicate could take.
    expect($authBindings)->not->toBeEmpty()
        ->and(implode('|', $authBindings))->not->toContain('maria')
        ->and(implode('|', $authBindings))->not->toContain('%');
});

it('never returns another tenant’s same-named member, or the user who belongs to no tenant', function (): void {
    expect(rosterEmails('maria'))
        ->not->toContain($this->ownerB->email)
        ->not->toContain($this->nowhere->email);
});

it('finds a PENDING INVITE that global search structurally cannot — the documented asymmetry', function (): void {
    // Both directions in one case, so neither half can be "fixed" without the other going red.
    //
    // The roster CAN: the page has already fetched that identity and renders it, so filtering the list it
    // is showing discloses nothing new. The arm CANNOT: `users_visibility` admits only `tu.status =
    // 'active'` and RLS applies at EVERY reference to `users`, so a `tenant_users`-first join does not
    // rescue it either (measured in J1c). If that ever needs to change, the fix is a policy decision about
    // `users_visibility` — NEVER a connection hop.
    expect(rosterEmails('pending'))->toBe([$this->pending->email]);

    expect(app(MemberSearchArm::class)->search($this->ownerA, SearchTerms::parse('pending'), 50)->rows)->toBe([]);
});

it('ANDs the tokens of a two-word query rather than widening', function (): void {
    // `matchesAny()`'s twin of the associativity guard `KeywordFilter::applyLike()` carries in SQL: every
    // token must hit at least one field. Flattened to an OR this would return both Marias plus Bruno.
    expect(rosterEmails('maria nowhere'))->toBe([])
        ->and(rosterEmails('maria santos'))->toBe([$this->ownerA->email]);
});

it('treats an underscore literally, where the SQL twin needs an ESCAPE clause to', function (): void {
    // The asymmetry worth pinning: `SearchTerms` KEEPS `_` in its token class, so `applyLike()` must escape
    // it with `ESCAPE '!'`. `str_contains` needs nothing — and this case is what stops a later reader
    // "restoring symmetry" by adding an escape here, which would make a literal underscore unfindable.
    expect(rosterEmails('m_ria'))->toBe([]);
});

it('serves the page with the keyword echoed back and an empty_reason a filtered list can use', function (): void {
    $this->actingAs($this->ownerA)->withoutVite()
        ->get('http://acme.meridian.test/members?q=bruno')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('members/Index', false)
            ->where('filters.applied.q', 'bruno')
            ->where('empty_reason', null)
            ->has('members', 1));

    $this->actingAs($this->ownerA)->withoutVite()
        ->get('http://acme.meridian.test/members?q=zzzznobody')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('empty_reason', 'no_matches')
            ->has('members', 0));
});

it('clamps rather than refuses a hostile or oversized query on a GET', function (): void {
    // A 422 on an Inertia GET redirects "back", which on a cold visit is nowhere at all — so every bound in
    // this feature lives in the sanitiser. The ampersand is the one that raises 42601 in Postgres if it
    // reaches `to_tsquery`; it cannot reach one on this page, and the roster must survive it regardless.
    foreach (['&', "'; drop--", '%%%', '   ', str_repeat('a', 300)] as $hostile) {
        $this->actingAs($this->ownerA)->withoutVite()
            ->get('http://acme.meridian.test/members?q='.urlencode($hostile))
            ->assertOk();
    }

    // `?q[]=x` reaches `mb_substr()` as an ARRAY without the `is_string()` guard in `ReadsKeywordFilter`.
    $this->actingAs($this->ownerA)->withoutVite()
        ->get('http://acme.meridian.test/members?q[]=x')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.applied.q', null));
});
