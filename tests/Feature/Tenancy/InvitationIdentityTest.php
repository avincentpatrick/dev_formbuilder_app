<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
            ->where('isUnusedPlaceholder', false));
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

/*
|--------------------------------------------------------------------------
| M9 — the third door, and the page that could not see the second condition
|
| `decline()` asked no identity question at all: it resolved the row by token hash and wrote `Declined`, on
| a route carrying no `auth` middleware. Whoever read the mailbox — or received a forwarded link — could
| destroy an established member's pending invitation, and the invited person then saw nothing rather than an
| explanation. It is DENIAL rather than takeover, which is why M8 left it and why it was filed `minor`; it
| is fixed here because it is the same door asking a weaker question than its neighbour.
|
| ⚠️ THE COMMITTED-INVERSE DISCIPLINE OF THIS FILE APPLIES UNCHANGED: every refusal below fails on the
| pre-M9 code, which `git stash push -- app/Http/Controllers/Tenant/InvitationController.php` demonstrates.
|--------------------------------------------------------------------------
*/

it('refuses to let a token holder decline an established member’s invitation', function (): void {
    // The established arm is handed off, not refused outright — the same asymmetry `accept()` records at
    // length. A legitimate invitee who clicks Decline from their mailbox while signed out is a real person
    // doing a reasonable thing, and `abort(403)` alone would be a dead end for them.
    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $tenant = m8InviteInto($victim, 'decline-handoff-token');

    $this->delete('http://acme.meridian.test/invitations/decline-handoff-token')
        ->assertRedirect('http://acme.meridian.test/login')
        ->assertSessionHas('url.intended', 'http://acme.meridian.test/invitations/decline-handoff-token');

    $this->assertGuest();

    // ⚠️ ASSERT WHAT THE SYSTEM ENDED UP DOING. The invitation is the thing being protected here, and the
    // TOKEN is half of it: `decline()` nulls it, so a successful refusal has to leave the invitee's own
    // link working, not merely leave the status alone.
    enterTenant($tenant->id);
    $membership = TenantUser::query()->where('user_id', $victim->id)->first();

    expect($membership->status)->toBe(TenantUserStatus::Invited)
        ->and($membership->invite_token)->toBe(hash('sha256', 'decline-handoff-token'));
});

it('refuses outright when somebody else is signed in, on the decline door too', function (): void {
    // Same reasoning as the accept door: `GET /login` carries `guest:web`, so there is nothing to hand off
    // to when the wrong person is already authenticated.
    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $tenant = m8InviteInto($victim, 'decline-wronguser-token');

    $intruder = User::factory()->create();
    enterTenant($tenant->id, $intruder->id);
    makeActiveMember($intruder, 'viewer');

    $this->actingAs($intruder)
        ->delete('http://acme.meridian.test/invitations/decline-wronguser-token')
        ->assertForbidden();

    enterTenant($tenant->id);
    expect(TenantUser::query()->where('user_id', $victim->id)->first()->status)
        ->toBe(TenantUserStatus::Invited);
});

it('lets an established identity decline once they are signed in as themselves', function (): void {
    // The permissive control for the established arm. A door that refused everybody would pass every
    // refusal above while breaking the act the door exists for.
    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $tenant = m8InviteInto($victim, 'decline-self-token');

    $this->actingAs($victim)
        ->delete('http://acme.meridian.test/invitations/decline-self-token')
        ->assertRedirect('http://acme.meridian.test');

    enterTenant($tenant->id);
    expect(TenantUser::query()->where('user_id', $victim->id)->first()->status)
        ->toBe(TenantUserStatus::Declined);
});

it('still lets a never-used placeholder decline with the token alone', function (): void {
    // ⚠️ THE UNGUARDED ARM IS DELIBERATE AND IS THE REASON THE PREDICATE IS ASKED RATHER THAN `Auth::check()`.
    // A placeholder's password is 48 random bytes nobody has ever held, so it CANNOT sign in — requiring
    // authentication to decline would make declining impossible for exactly the people an invitation
    // creates. Holding the token is the only proof they have, and declining costs them nothing they had.
    $stranger = m8Identity('Never Used');
    $tenant = m8InviteInto($stranger, 'decline-placeholder-token');

    $this->delete('http://acme.meridian.test/invitations/decline-placeholder-token')
        ->assertRedirect('http://acme.meridian.test');

    enterTenant($tenant->id);
    expect(TenantUser::query()->where('user_id', $stranger->id)->first()->status)
        ->toBe(TenantUserStatus::Declined);
});

it('tells the page which account is holding it, so it stops offering an act that 403s', function (): void {
    // ⚠️ `isUnusedPlaceholder` ANSWERS "HAS THIS IDENTITY BEEN USED", NEVER "ARE YOU THE INVITEE" — and
    // `accept()` enforces both. The page could see only the first, so a signed-in wrong visitor was offered
    // an Accept button whose POST always 403s. `signedInAs` is the second question, published so the page
    // can name the account to use instead of failing after the click.
    $this->withoutVite();

    $victim = m8Identity('Enrolled Person', ['two_factor_confirmed_at' => now()]);
    $tenant = m8InviteInto($victim, 'signedinas-token');

    $intruder = User::factory()->create();
    enterTenant($tenant->id, $intruder->id);
    makeActiveMember($intruder, 'viewer');

    $this->actingAs($intruder)
        ->get('http://acme.meridian.test/invitations/signedinas-token')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitations/Show', false)
            ->where('isUnusedPlaceholder', false)
            ->where('signedInAs', $intruder->email)
            ->where('email', $victim->email));
});

it('publishes no holder for a guest, which is the ordinary invitation page', function (): void {
    // The control for the prop itself. `signedInAs` must be null for the commonest visitor of all, or the
    // page's wrong-account branch would swallow the door it exists to protect.
    $this->withoutVite();

    $stranger = m8Identity('Never Used');
    m8InviteInto($stranger, 'guest-holder-token');

    $this->get('http://acme.meridian.test/invitations/guest-holder-token')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitations/Show', false)
            ->where('isUnusedPlaceholder', true)
            ->where('signedInAs', null));
});

/*
|--------------------------------------------------------------------------
| Increment M76 — residual 30: `password_set_at`
|--------------------------------------------------------------------------
|
| The cases above pin the five arms M8 shipped. Every one of them reads FALSE for an account created by
| central-host self-registration and never used, so a token holder still got the password-setting arm —
| which is the residual M8 filed and this increment closes with a positive column.
|
| ⛔ THE FIRST CASE BELOW IS THE ONE THAT MATTERS, AND IT IS NOT THE ONE ABOUT THE PREDICATE. `password_set_at`
| is deliberately absent from `User`'s `#[Fillable]` attribute, so a `User::create([... 'password_set_at' =>
| now()])` writes NOTHING and throws NOTHING. The backlog row that prescribed this fix prescribed exactly
| that spelling. If a later author "tidies" `CreateNewUser` back to `User::create()`, the column silently
| stops being written, this whole feature reverts to a no-op, and every other test in this file still
| passes. That case is the only thing standing between here and there.
*/

it('stamps password_set_at when a person registers, which mass assignment would have dropped in silence', function (): void {
    fakeHibp([]);

    $password = 'Correct-Horse-Battery-9';

    $this->post('/register', [
        'name' => 'Self Registered',
        'email' => 'm76-selfreg@platformaudittest.local',
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $this->assertAuthenticated();

    // ⚠️ READ ON THE APP CONNECTION, NOT VIA `m8StoredUser()` LIKE EVERY OTHER CASE IN THIS FILE.
    // `pgsql_privileged` is a SEPARATE connection whose writes commit outside the test transaction, which
    // is exactly why the helpers use it to SEED. Registration writes on the DEFAULT connection inside the
    // transaction, so a privileged read of that row cannot see it at all.
    // ⚠️ AND THE GUC HAS TO BE SET BY HAND. `users_users_visibility` admits a row only for
    // `app.current_user_id` or an active co-tenant, and that setting is applied by middleware PER REQUEST
    // — it is not still in force in the test process afterwards, so a bare `fresh()` returns null. There
    // is no tenant here: central-host registration deliberately creates no membership, which is the whole
    // reason this account is the vulnerable shape.
    // ⛔ IT MUST BE A RE-READ RATHER THAN THE IN-MEMORY MODEL. `forceFill()` sets the attribute in memory
    // whether or not it reaches the database, so asserting on `Auth::user()` directly would pass under the
    // very mass-assignment regression this case exists to catch.
    $id = Auth::id();
    expect($id)->not->toBeNull();

    TenantContext::applyLocal(null, $id);
    $stored = User::query()->find($id);

    expect($stored)->not->toBeNull()
        ->and($stored->password_set_at)->not->toBeNull()
        // ⚠️ AND THE PROXY IS STILL NULL, WHICH IS WHAT MAKES THIS ACCOUNT THE VULNERABLE SHAPE. If a
        // future Fortify config starts stamping `email_verified_at` at registration, this expectation
        // fails and whoever changed it learns that the case below no longer proves what it claims.
        ->and($stored->email_verified_at)->toBeNull();
});

it('refuses to take over a self-registered account that has never been verified', function (): void {
    fakeHibp();

    // ⚠️ THE EXACT POPULATION RESIDUAL 30 DESCRIBED, BUILT FROM ITS OWN DESCRIPTION: no verified address,
    // no second factor, no Google link, no membership anywhere. Before M76 every arm read false and the
    // token holder was handed `registerInvitedPlaceholder()`.
    $victim = m8Identity('Self Registered Person', ['password_set_at' => now()]);
    $before = m8StoredUser($victim);

    $tenant = m8InviteInto($victim, 'selfreg-token');

    $this->from('http://acme.meridian.test/invitations/selfreg-token')
        ->post('http://acme.meridian.test/invitations/selfreg-token', [
            'name' => 'Whoever Holds The Token',
            'password' => 'Attacker-Chosen-Pass-9',
        ])
        ->assertRedirect('http://acme.meridian.test/login');

    $this->assertGuest();

    $after = m8StoredUser($victim);
    expect($after->password)->toBe($before->password)
        ->and($after->name)->toBe('Self Registered Person')
        ->and($after->email_verified_at)->toBeNull();

    enterTenant($tenant->id);
    expect(TenantUser::query()->where('user_id', $victim->id)->first()->status)
        ->toBe(TenantUserStatus::Invited);
});

it('does not offer the password form to a self-registered account, so the page cannot promise a refusal', function (): void {
    // ⛔ `withoutVite()` IS LOAD-BEARING AND ITS ABSENCE PASSES LOCALLY. Rendering the Inertia root view
    // resolves the Vite manifest, which exists on a developer machine that has built the front end and does
    // NOT exist in the Pest CI job — so without this the request 500s there and `assertInertia` reports
    // *"Not a valid Inertia response"*, a message that says nothing about the cause. This case was written
    // without it, passed locally, and reddened CI; every other Inertia case in this file already carries it.
    $this->withoutVite();

    $victim = m8Identity('Self Registered Person', ['password_set_at' => now()]);
    m8InviteInto($victim, 'selfreg-page-token');

    // `assertOk()` before `assertInertia()`, on the sibling case's pattern: a non-200 is the far more
    // likely failure and it names itself, where the Inertia assertion does not.
    $this->get('http://acme.meridian.test/invitations/selfreg-page-token')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('invitations/Show', false)
            ->where('isUnusedPlaceholder', false));
});

it('stamps password_set_at when a placeholder accepts, so a SECOND token meets an established identity', function (): void {
    fakeHibp();

    // The genuinely-new person the invitation arm exists for: nothing set, so they get the form.
    $newcomer = m8Identity('Genuine Newcomer');
    $tenant = m8InviteInto($newcomer, 'newcomer-token');

    $this->withoutVite()->post('http://acme.meridian.test/invitations/newcomer-token', [
        'name' => 'Genuine Newcomer',
        'password' => 'Their-Own-Choice-9',
    ])->assertRedirect('/dashboard');

    // Read on the APP connection, not `pgsql_privileged`: the accept writes inside the test transaction
    // and the privileged connection commits outside it, so it would return the pre-accept row. Entering
    // the tenant as this user is what satisfies the join-shape RLS policy on `users`.
    enterTenant($tenant->id, $newcomer->id);
    $stored = User::query()->find($newcomer->id);

    // ⚠️ NOT REDUNDANT BESIDE `email_verified_at`, WHICH THE SAME `forceFill` ALSO STAMPS. The point is
    // that the DIRECT fact is recorded rather than inferred from a proxy — making the proxy load-bearing
    // is how the original defect came to exist.
    expect($stored->password_set_at)->not->toBeNull()
        ->and($stored->email_verified_at)->not->toBeNull();
});

it('stamps password_set_at on a forgotten-password reset, which is how the pre-migration population drains', function (): void {
    fakeHibp();

    // ⚠️ THE ACCOUNT PRE-DATES THE COLUMN — `password_set_at` NULL and nothing else set. This is the
    // residual the migration deliberately does NOT backfill, and this case is the mechanism by which such
    // an account leaves it. Asserting the null first is what stops this passing vacuously.
    $legacy = m8Identity('Legacy Person');
    expect(m8StoredUser($legacy)->password_set_at)->toBeNull();

    // The action is driven directly rather than over HTTP, because the reset LINK is Fortify's and is
    // covered elsewhere — what is unproved is whether this action stamps the column. Kept on the
    // privileged connection so the write commits where `m8StoredUser()` can read it back; the default
    // connection would write inside the test transaction, which that helper cannot see.
    $legacy->setConnection('pgsql_privileged');

    // `password_confirmation` is required: `passwordRules()` carries `confirmed`, which the invitation
    // surface is the one documented exception to.
    app(ResetUserPassword::class)->reset($legacy, [
        'password' => 'A-Brand-New-Choice-9',
        'password_confirmation' => 'A-Brand-New-Choice-9',
    ]);

    expect(m8StoredUser($legacy)->password_set_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Increment M76 — the accept arm's write actually reaches the database
|--------------------------------------------------------------------------
|
| ⛔ NOT THE SAME QUESTION AS "DOES ACCEPT WORK", AND THAT DISTINCTION IS THE WHOLE DEFECT.
| `MembershipRoutesTest`'s end-to-end case asserts the membership status, `joined_at`, the granted role and
| that the session is authenticated — all four of which were TRUE while `registerInvitedPlaceholder()`'s
| `save()` was updating ZERO rows and throwing nothing. The invitee's chosen password, their name, the
| verification stamp and both consent timestamps were discarded on every successful accept, and the account
| was left unverified: a `verified`-middleware lockout with no error to trace.
|
| ⚠️ EVERY REFUSAL CASE ABOVE ASSERTS THE PASSWORD IS UNCHANGED — AND UNCHANGED IS ALSO WHAT A DROPPED
| WRITE LOOKS LIKE. A file full of them can therefore be entirely green against a controller whose write
| arm is inert, which is why the permissive direction needs its own credential assertion rather than
| another status check.
*/

it('actually persists the password an invitee chooses, rather than updating zero rows in silence', function (): void {
    fakeHibp();
    $this->withoutVite();

    $newcomer = m8Identity('Genuine Newcomer');
    $before = m8StoredUser($newcomer);

    $tenant = m8InviteInto($newcomer, 'persist-token');

    $this->post('http://acme.meridian.test/invitations/persist-token', [
        'name' => 'Their Chosen Name',
        'password' => 'Their-Own-Choice-9',
    ])->assertRedirect('/dashboard');

    // ⚠️ READ ON `pgsql_privileged`, WHICH IS WHAT MAKES THIS CASE MEAN ANYTHING. The row is committed
    // outside the test transaction and the fix writes on `pgsql_auth`, which is also outside it — so this
    // read sees the real, durable state rather than the test's own uncommitted view.
    $after = m8StoredUser($newcomer);

    // The credential itself, byte-for-byte against the placeholder hash it had to replace.
    expect($after->password)->not->toBe($before->password)
        ->and(Hash::check('Their-Own-Choice-9', $after->password))->toBeTrue()
        // `email_verified_at` is the tell that exposed the defect: the array sets it unconditionally, so a
        // NULL here can only mean the statement matched no row.
        ->and($after->email_verified_at)->not->toBeNull()
        ->and($after->password_set_at)->not->toBeNull()
        // The consents are a compliance record, and they were being dropped with everything else.
        ->and($after->tos_accepted_at)->not->toBeNull()
        ->and($after->privacy_policy_accepted_at)->not->toBeNull()
        ->and($after->name)->toBe('Their Chosen Name');

    // And the membership still lands, so this is not a fix that traded one arm for the other.
    enterTenant($tenant->id, $newcomer->id);
    expect(TenantUser::query()->where('user_id', $newcomer->id)->first()->status)
        ->toBe(TenantUserStatus::Active);
});
