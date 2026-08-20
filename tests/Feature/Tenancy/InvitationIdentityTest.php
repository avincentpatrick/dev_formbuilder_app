<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M8 — WHO may accept an invitation, and what the accept arm may write.
|--------------------------------------------------------------------------
| `InvitationController` used to fork on `email_verified_at !== null`, reading a NULL as "this identity does
| not exist yet". It does not mean that, and the difference was a live account takeover: whoever held the
| emailed token could set a password on an EXISTING account and be logged straight in with no second factor
| — strictly weaker than password reset, which at least lands on the login form. The predicate now lives in
| `TenantMembershipService::identityIsEstablished()` and is asked at BOTH doors.
|
| ⚠️ THESE ARE COMMITTED-INVERSE CASES, NOT RATIFICATION. Every refusal below FAILS on the pre-M8 code:
| `git stash push -- app/` turns them red, which is what makes the number a property of the code rather
| than of the test. The two permissive cases (a placeholder still registers; a placeholder invited TWICE
| still registers) are the other half — a fix that refuses everybody would pass a file of refusals alone.
|
| ══════════════════════════════════════════════════════════════════════════════════════════════════════
| ⚠️ THE CROSS-TENANT CLAUSE CANNOT BE TESTED WITH IN-TRANSACTION ROWS, AND THE FAILURE IS SILENT.
| ══════════════════════════════════════════════════════════════════════════════════════════════════════
| `identityIsEstablished()` reads memberships on `pgsql_auth` — a SEPARATE SESSION — so a `TenantUser` row
| created inside RefreshDatabase's transaction is invisible to it and the clause answers false for
| everybody. A test written that way passes VACUOUSLY: the refusal case would fail loudly (good), but the
| PERMISSIVE case would pass while proving nothing at all. Both fixtures below are therefore committed on
| `pgsql_privileged`, and `SsoAcsWebTest` records the same hazard from the other side.
|
| Committed rows outlive the rollback, so they use the `platform-audit-` slug marker that
| `purgeCommittedPlatformAuditFixtures()` deletes by, and the `@platformaudittest.local` email marker for
| identities. That marker is a CLEANUP CONTRACT rather than a description of the fixture's subject — I11a
| shipped one committed tenant outside it and took twelve tests down across seven unrelated suites. The
| purge runs on the way IN and again after the rollback — markers, not ids, so a case that dies mid-way is
| cleaned by the next one regardless.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    purgeCommittedPlatformAuditFixtures();

    // ⛔ REGISTERED HERE, NEVER RUN FROM A TEARDOWN HOOK — AND THE FIRST DRAFT OF THIS FILE HUNG FOR TEN
    // MINUTES PROVING IT. Pest's teardown hook runs inside `tearDown()`, while RefreshDatabase rolls back
    // from a `beforeApplicationDestroyed` callback registered in `setUp()` — which runs LATER. So a
    // teardown-time purge fires with the test transaction still OPEN, and that transaction holds
    // `FOR KEY SHARE` on every committed `users` row its own `tenant_users` INSERT referenced. The
    // privileged DELETE then waits on a lock only the rollback can release, and the suite stops dead with
    // no output at all.
    //
    // Registering it here puts it AFTER RefreshDatabase's own callback (they run in registration order), so
    // the rollback releases the FK locks first. `MembershipRoutesTest` records the same hazard from the
    // other side, and `FeedbackRlsTest` uses exactly this idiom for exactly this reason.
    $this->beforeApplicationDestroyed(purgeCommittedPlatformAuditFixtures(...));
});

/**
 * A COMMITTED identity, shaped so every column signal the predicate reads is NULL unless asked for.
 *
 * Committed because the controller resolves the invitee on `pgsql_auth`, which cannot see the test's own
 * open transaction — the same reason `MembershipRoutesTest`'s end-to-end case commits its invitee.
 * Prefixed `m8` because Pest loads every test file into ONE process, so a generically-named top-level
 * function here is a `Cannot redeclare function` fatal waiting for the next suite to want the same word.
 */
function m8Identity(string $name, array $attributes = []): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate(array_merge([
        'name' => $name,
        'email' => Str::lower(Str::random(10)).'@platformaudittest.local',
        'password' => Hash::make('Their-Own-Password-9'),
        'email_verified_at' => null,
    ], $attributes));

    $user->setConnection((string) config('database.default'));

    return $user;
}

/**
 * A COMMITTED membership for $user in a workspace the invited tenant cannot see.
 *
 * `$joinedAt` is the whole point of the helper: pass a timestamp for a membership they ACTUALLY joined
 * (the fourth signal), or null for a second pending INVITATION (which must not count — see the two-invite
 * case below). The tenant is committed too, because `tenant_users.tenant_id` is a foreign key and an
 * uncommitted parent fails the insert on the elevated connection.
 */
function m8CommittedMembershipElsewhere(User $user, ?string $joinedAt): Tenant
{
    $tenant = committedPlatformTenant('platform-audit-m8-'.Str::lower(Str::random(8)), 'Elsewhere Inc');

    DB::connection('pgsql_privileged')->table('tenant_users')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'status' => $joinedAt === null ? TenantUserStatus::Invited->value : TenantUserStatus::Active->value,
        'joined_at' => $joinedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenant;
}

/** The inviting workspace plus a pending invite for $user, addressable at $token. */
function m8InviteInto(User $user, string $token): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    enterTenant($tenant->id);
    TenantUser::create([
        'user_id' => $user->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('form_editor'),
        'invite_expires_at' => now()->addDay(),
        'invite_token' => hash('sha256', $token),
    ]);

    return $tenant;
}

/** Everything the predicate protects, read back across the RLS boundary that hid it. */
function m8StoredUser(User $user): object
{
    return DB::connection('pgsql_privileged')->table('users')->where('id', $user->id)->first();
}

it('refuses to take over an enrolled-but-unverified account through an invitation link', function (): void {
    fakeHibp();

    // ⚠️ THE STATE IS ORDINARY, NOT EXOTIC, AND THAT IS THE SEVERITY ARGUMENT. A fully enrolled member who
    // corrects a typo in their own address lands here: `UpdateUserProfileInformation::updateVerifiedUser()`
    // force-fills `email_verified_at => null` on ANY email change, and no writer in `app/` ever clears
    // `two_factor_confirmed_at`. Before M8 this account got the placeholder arm.
    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $before = m8StoredUser($victim);

    $tenant = m8InviteInto($victim, 'enrolled-token');

    $this->from('http://acme.meridian.test/invitations/enrolled-token')
        ->post('http://acme.meridian.test/invitations/enrolled-token', [
            'name' => 'Whoever Holds The Token',
            'password' => 'Attacker-Chosen-Pass-9',
        ])
        ->assertRedirect('http://acme.meridian.test/login')
        ->assertSessionHas('url.intended', 'http://acme.meridian.test/invitations/enrolled-token');

    $this->assertGuest();

    // ⚠️ ASSERT WHAT THE SYSTEM ENDED UP DOING, NEVER THAT A METHOD WAS CALLED. The credential is the thing
    // being protected, so the credential is the thing asserted — byte-for-byte, across the boundary.
    $after = m8StoredUser($victim);
    expect($after->password)->toBe($before->password)
        ->and($after->name)->toBe('Enrolled Person')
        ->and($after->email_verified_at)->toBeNull();

    enterTenant($tenant->id);
    expect(TenantUser::query()->where('user_id', $victim->id)->first()->status)
        ->toBe(TenantUserStatus::Invited);
});

it('refuses when the only thing proving the identity is a membership in another workspace', function (): void {
    fakeHibp();

    // Every column signal reads NULL for this person — no verified address, no second factor, no Google.
    // The ONLY positive record is a membership the invited tenant's own RLS cannot see, which is the entire
    // reason migration 2026_08_17_000107 exists. Delete that migration and this case goes red.
    $victim = m8Identity('Joined Elsewhere');
    $before = m8StoredUser($victim);
    m8CommittedMembershipElsewhere($victim, (string) now());

    m8InviteInto($victim, 'elsewhere-token');

    $this->from('http://acme.meridian.test/invitations/elsewhere-token')
        ->post('http://acme.meridian.test/invitations/elsewhere-token', [
            'name' => 'Whoever Holds The Token',
            'password' => 'Attacker-Chosen-Pass-9',
        ])
        ->assertRedirect('http://acme.meridian.test/login');

    $this->assertGuest();
    expect(m8StoredUser($victim)->password)->toBe($before->password);
});

it('refuses when the only thing proving the identity is a linked Google account', function (): void {
    fakeHibp();

    // Google's own provisioning stamps `email_verified_at` too, so this fixture is deliberately narrower
    // than production: it isolates the `google_id` clause so removing it cannot pass unnoticed.
    $victim = m8Identity('Google Person', ['google_id' => 'google-sub-'.Str::random(12)]);
    $before = m8StoredUser($victim);

    m8InviteInto($victim, 'google-token');

    $this->post('http://acme.meridian.test/invitations/google-token', [
        'name' => 'Whoever Holds The Token',
        'password' => 'Attacker-Chosen-Pass-9',
    ])->assertRedirect('http://acme.meridian.test/login');

    $this->assertGuest();
    expect(m8StoredUser($victim)->password)->toBe($before->password);
});

it('refuses outright, rather than handing off, when somebody else is signed in', function (): void {
    fakeHibp();

    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $tenant = m8InviteInto($victim, 'wronguser-token');

    // ⚠️ NOT A REDIRECT. Fortify's `GET /login` carries `guest:web`, so sending an authenticated visitor
    // there bounces them to the dashboard with a stale destination and no explanation. There is nothing to
    // hand off to when the wrong person is already signed in, so the refusal stays a refusal.
    $intruder = User::factory()->create();
    enterTenant($tenant->id, $intruder->id);
    makeActiveMember($intruder, 'viewer');

    $this->actingAs($intruder)
        ->post('http://acme.meridian.test/invitations/wronguser-token', [
            'name' => 'Whoever Holds The Token',
            'password' => 'Attacker-Chosen-Pass-9',
        ])
        ->assertForbidden();
});

it('renders the accept-only page for an established identity, so the form is never offered', function (): void {
    // The OTHER half of the fix. `:43` and `:99` asked the same wrong question, consistently — so an
    // enrolled member's invite page rendered "Choose a password" for an account that already had one, and
    // fixing only the controller would leave the page inviting a credential the server then refuses.
    $this->withoutVite();

    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    m8InviteInto($victim, 'render-token');

    $this->get('http://acme.meridian.test/invitations/render-token')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitations/Show', false)
            ->where('needsRegistration', false));
});

it('still lets a never-used placeholder set a password and join', function (): void {
    // The permissive control. A file of refusals would pass just as well against a predicate that refused
    // everybody, which would break the product's commonest door rather than fixing anything.
    fakeHibp();
    $this->withoutVite();

    $newcomer = m8Identity('Pending Person');
    $tenant = m8InviteInto($newcomer, 'newcomer-token');

    $this->post('http://acme.meridian.test/invitations/newcomer-token', [
        'name' => 'Pending Person',
        'password' => 'Correct-Horse-Battery-9',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    enterTenant($tenant->id, $newcomer->id);
    expect(TenantUser::query()->where('user_id', $newcomer->id)->first()->status)
        ->toBe(TenantUserStatus::Active);
});

it('still lets a placeholder invited to two workspaces register, because a pending invite is not a history', function (): void {
    // ⚠️ THE `joined_at IS NOT NULL` CONJUNCT IS A CORRECTNESS REQUIREMENT, NOT A NARROWING, AND THIS IS THE
    // CASE THAT PROVES IT. `resolveOrCreateUser()` creates exactly ONE placeholder per email address, so a
    // genuinely-new person invited to two workspaces holds TWO `Invited` rows. A predicate written as "any
    // other membership row exists" would mark them established and lock them out of BOTH password-setting
    // arms — a dead end manufactured by the fix. Drop the conjunct and this case goes red.
    //
    // The second invite is COMMITTED on purpose: an in-transaction row is invisible to the `pgsql_auth`
    // read, so an uncommitted version of this fixture would pass no matter what the predicate said.
    fakeHibp();

    $newcomer = m8Identity('Twice Invited');
    m8CommittedMembershipElsewhere($newcomer, null);

    $tenant = m8InviteInto($newcomer, 'twice-token');

    $this->post('http://acme.meridian.test/invitations/twice-token', [
        'name' => 'Twice Invited',
        'password' => 'Correct-Horse-Battery-9',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    enterTenant($tenant->id, $newcomer->id);
    expect(TenantUser::query()->where('user_id', $newcomer->id)->first()->status)
        ->toBe(TenantUserStatus::Active);
});

it('refuses a re-invited former member, because removing them does not unmake the history', function (): void {
    // \u26d4 THE HOLE M8's OWN ADVERSARIAL PASS FOUND IN M8's OWN PREDICATE, AND THE ONLY CASE THAT COVERS IT.
    // `unique(tenant_id, user_id)` means a re-invited former member has NO second row: `invite()` REUSES
    // the one they already had, and it used to force-fill `joined_at => null` on the way through. The
    // membership query excludes the invite row by primary key, so every arm read false and a real account
    // \u2014 someone who had demonstrably been a member \u2014 was handed back to the password-overwrite arm.
    // Every other case in this file puts the joined row in a DIFFERENT tenant, which is exactly why none of
    // them caught it. Restore `'joined_at' => null` in `invite()`, or delete the `$excludingInvite` check in
    // `identityIsEstablished()`, and this case goes red on its own.
    fakeHibp();

    $victim = m8Identity('Former Member');
    $before = m8StoredUser($victim);

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    enterTenant($tenant->id);

    // The row as `invite()` leaves it when it reuses a Removed membership: status back to Invited, a fresh
    // token \u2014 and the join date still standing, which is the whole fix.
    TenantUser::create([
        'user_id' => $victim->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('form_editor'),
        'invite_expires_at' => now()->addDay(),
        'invite_token' => hash('sha256', 'reinvite-token'),
        'joined_at' => now()->subMonth(),
    ]);

    $this->post('http://acme.meridian.test/invitations/reinvite-token', [
        'name' => 'Whoever Holds The Token',
        'password' => 'Attacker-Chosen-Pass-9',
    ])->assertRedirect('http://acme.meridian.test/login');

    $this->assertGuest();
    expect(m8StoredUser($victim)->password)->toBe($before->password);
});

it('lets an established identity accept once they are signed in as themselves', function (): void {
    // \u26a0\ufe0f THE ARM EVERY OTHER CASE IN THIS FILE AND IN `MembershipRoutesTest` LEAVES UNTESTED. Both
    // permissive cases above run the PLACEHOLDER arm, and so does the only accept case in the older file \u2014
    // so a fix that refused every ESTABLISHED person would pass all of them. Invert the `abort_unless`, or
    // break the hand-off any other way, and the entire sign-in-then-accept path this increment builds is
    // dead code with the suite still green. It also documents that this arm takes NO `name` or `password`
    // in the body, which is otherwise only inferable from the absence of a `validate()` call.
    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $before = m8StoredUser($victim);

    $tenant = m8InviteInto($victim, 'signedin-token');

    enterTenant($tenant->id, $victim->id);

    $this->actingAs($victim)
        ->post('http://acme.meridian.test/invitations/signedin-token')
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($victim);

    enterTenant($tenant->id, $victim->id);
    $membership = TenantUser::query()->where('user_id', $victim->id)->first();
    expect($membership->status)->toBe(TenantUserStatus::Active)
        ->and($membership->joined_at)->not->toBeNull();

    // \u26d4 AND THE CREDENTIAL IS UNTOUCHED, WHICH IS THE POINT OF THE WHOLE INCREMENT: accepting an
    // invitation must never be a way to set somebody's password, not even their own.
    expect(m8StoredUser($victim)->password)->toBe($before->password);
});

it('keeps a re-invite from erasing the join date the predicate depends on', function (): void {
    // The interaction, asserted at the service level so it cannot be satisfied by the controller alone.
    // `invite()` reuses a Removed row; if it nulls `joined_at`, the predicate below flips to false and the
    // route case above becomes reachable again.
    Notification::fake();

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    $member = m8Identity('Former Member');

    enterTenant($tenant->id, $admin->id);
    TenantUser::create([
        'user_id' => $member->id,
        'status' => TenantUserStatus::Removed,
        'invited_role_id' => catalogRole('viewer'),
        'joined_at' => now()->subMonth(),
        'removed_at' => now()->subDay(),
    ]);

    $invite = app(TenantMembershipService::class)->invite($tenant, $member->email, 'viewer', $admin);

    expect($invite->status)->toBe(TenantUserStatus::Invited)
        ->and($invite->joined_at)->not->toBeNull();

    expect(app(TenantMembershipService::class)->identityIsEstablished($member, $invite))->toBeTrue();
});
