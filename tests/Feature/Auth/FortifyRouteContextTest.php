<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fortify's authenticated routes write to `users` — and until J3b they wrote NOTHING (Increment J3b).
|
| ⚠️ THE DEFECT THESE CASES EXIST FOR AFFECTED SIX ENDPOINTS AND FAILED SILENTLY ON ALL OF THEM.
| `config/fortify.php` mounts every Fortify route with no tenancy middleware, so `app.current_user_id` was
| unset for the whole request. `users_app_update` is permissive — but PostgreSQL applies SELECT policies to
| an UPDATE whose WHERE reads a column, and `users_users_visibility` needs the acting user or an ACTIVE
| co-tenant. The row was invisible to its own update: ZERO rows affected, no exception, no log line, and
| Eloquent's save() does not inspect the affected-row count, so every caller was told it worked.
|
| The one that makes this a lockout rather than an annoyance is `GET /email/verify/{id}/{hash}`, which is on
| the same group: `markEmailAsVerified()` wrote nothing, `save()` still returned true, `Verified` still
| fired — and J3a mounted the `verified` gate. A new registrant clicked their link, was told it worked, and
| was bounced back to the notice forever. The only other way out is the correction form on
| `auth/VerifyEmail.vue`, which posts to `PUT /user/profile-information` — the first broken endpoint. Both
| doors were the same door.
|
| ⚠️⚠️ THE HARD PART IS NOT THE FIX, IT IS WRITING A CASE THAT CAN FAIL. A NAIVE VERSION OF EVERY TEST
| BELOW PASSES AGAINST THE BROKEN CODE, and that is why the defect survived a suite of 3,767 cases.
| `enterTenant()` issues `set_config(..., is_local => true)`; under RefreshDatabase the whole test is ONE
| open transaction, so that SET LOCAL stays live straight through the simulated request — and because the
| route carried no `EstablishTenantDatabaseContext`, its terminate() never fired to clear it either. The
| harness was supplying exactly the GUC that production does not.
| `tests/Feature/Auth/TwoFactorEnrollmentTest.php` is the worked example: it asserts a complete 2FA
| enrol → confirm → recovery-codes round trip that in production wrote nothing at all.
|
| So `withoutUserContext()` below is the load-bearing line in this file. Measured against PostgreSQL
| before it was written, because the scoping rules decide whether these cases can fail AND whether the
| fixed code can pass:
|   - set_config(name, NULL, true) resets the GUC (to ''), which the policies' NULLIF(...,'') absorbs; and
|   - a SESSION-scoped set_config(..., false) issued AFTER a SET LOCAL DOES take effect immediately.
| The second is what lets the middleware's own `TenantContext::apply()` win from inside the transaction —
| without it the fix would be correct and this file would still be red.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** An ACTIVE member of the ambient tenant. Named apart from EmailVerificationGateTest's `memberOfTenant`. */
function fortifyMember(Tenant $tenant, bool $verified = true): User
{
    $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    return $user;
}

/**
 * Put the database session into the state a real Fortify request arrives in: no user, no tenant.
 *
 * ⚠️ REMOVING THIS LINE MAKES EVERY CASE IN THIS FILE PASS AGAINST THE BROKEN CODE. It is not setup
 * hygiene — it is the whole reproduction. `applyLocal` rather than `forget()` deliberately: forget() is
 * session-scoped, and a session-scoped write cannot clear an in-transaction SET LOCAL that is already in
 * effect, so it would look like it worked and change nothing.
 */
function withoutUserContext(): void
{
    TenantContext::applyLocal(null, null);
}

/**
 * Re-read the row from the database through a restored context.
 *
 * NOT on `pgsql_auth`, though its `users_auth_select` policy is `USING (true)` and would see everything:
 * that is a SEPARATE session which cannot see RefreshDatabase's open transaction, so every assertion
 * would be made against a row that is not there and `firstOrFail()` would be the only thing this file
 * ever reported. Restoring the GUC and reading on the default connection is immune to the bug under test
 * for a simpler reason — the bug is an UPDATE that matches no row, and this is a SELECT that does.
 */
function rereadUser(User $user, Tenant $tenant): User
{
    TenantContext::applyLocal($tenant->id, $user->id);

    return User::query()->whereKey($user->id)->firstOrFail();
}

it('lands a name change from PUT /user/profile-information', function (): void {
    $user = fortifyMember($this->tenant);
    withoutUserContext();

    $this->actingAs($user)
        ->put('http://acme.meridian.test/user/profile-information', [
            'name' => 'Renamed Person',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors();

    expect(rereadUser($user, $this->tenant)->name)->toBe('Renamed Person');
});

it('lands an email change and re-arms verification with it', function (): void {
    $user = fortifyMember($this->tenant);
    withoutUserContext();

    $this->actingAs($user)
        ->put('http://acme.meridian.test/user/profile-information', [
            'name' => $user->name,
            'email' => 'corrected@meridian.test',
        ])
        ->assertSessionHasNoErrors();

    $fresh = rereadUser($user, $this->tenant);

    // Both halves, because the broken version got the SECOND one right by doing nothing: the stamp was
    // already null-or-not and nothing touched it, while `sendEmailVerificationNotification()` fired
    // regardless — a verification link to an address the database had never heard of.
    expect($fresh->email)->toBe('corrected@meridian.test')
        ->and($fresh->email_verified_at)->toBeNull();
});

it('lands a password change from PUT /user/password', function (): void {
    fakeHibp();
    $user = fortifyMember($this->tenant);
    withoutUserContext();

    $this->actingAs($user)
        ->put('http://acme.meridian.test/user/password', [
            'current_password' => 'password',
            'password' => 'Corrected-Passphrase-9!',
            'password_confirmation' => 'Corrected-Passphrase-9!',
        ])
        ->assertSessionHasNoErrors();

    // The hash, not a boolean: `save()` returned true in the broken version too.
    expect(Hash::check('Corrected-Passphrase-9!', rereadUser($user, $this->tenant)->password))->toBeTrue();
});

it('actually stamps email_verified_at when the verification link is followed', function (): void {
    // ⚠️ THE P0. Every other case here is a silently-discarded edit; this one is a permanent lockout,
    // because J3a mounted `verified` on the authenticated tenant group and this endpoint is how a person
    // gets past it. It is asserted from the DATABASE rather than from the redirect, because the broken
    // version answered with exactly the same 302 to `?verified=1`.
    $user = fortifyMember($this->tenant, verified: false);
    withoutUserContext();

    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)->get($link)->assertRedirect();

    expect(rereadUser($user, $this->tenant)->email_verified_at)->not->toBeNull();
});

it('lands a two-factor secret from POST /user/two-factor-authentication', function (): void {
    // The fifth endpoint on the same stack, here so the fix is pinned as a property of the ROUTE GROUP
    // rather than of the two endpoints the backlog entry happened to name. `password.confirm` guards this
    // one (config/fortify.php sets `confirmPassword => true`), hence the session stamp.
    $user = fortifyMember($this->tenant);
    confirmPasswordNow();
    withoutUserContext();

    $this->actingAs($user)
        ->post('http://acme.meridian.test/user/two-factor-authentication')
        ->assertSessionHasNoErrors();

    expect(rereadUser($user, $this->tenant)->two_factor_secret)->not->toBeNull();
});
