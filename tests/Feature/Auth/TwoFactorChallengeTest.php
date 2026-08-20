<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The two-factor challenge — the page that no one could reach (Increment J3c1).
|--------------------------------------------------------------------------
| ⚠️ MEASURED ON THE RUNNING APP BEFORE A LINE WAS WRITTEN, because the defect is invisible from the code:
|     POST /login                → 302 → /two-factor-challenge   (correct: credentials pass)
|     GET  /two-factor-challenge → 302 → /login                  (the bug)
| Anyone who actually enrolled in 2FA was locked out at their next sign-in, with no self-service escape:
| `two-factor.disable` sits behind `auth` + `password.confirm`, and they could never complete `auth`.
|
| Fortify resolves the pending user with a STATIC `$model::find(session('login.id'))` — `getModel()` returns
| a class-string — which bypasses RlsAwareUserProvider and runs on the default connection, where the
| join-shape `users_users_visibility` policy hides the row because no guard has authenticated yet. See
| App\Http\Requests\Auth\RlsAwareTwoFactorLoginRequest for the whole argument.
|
| ── WHY EVERY IDENTITY HERE IS COMMITTED, AND WHY `withSession()` WOULD NOT HAVE HELPED ────────────────
| The obvious shortcut is to skip the login POST and seed `login.id` directly. It does not work, and the
| reason is worth stating because it looks like it should: after the fix the challenge resolves the user on
| `pgsql_auth`, a SEPARATE SESSION that cannot see RefreshDatabase's open transaction — so a factory user is
| invisible to the GET as well as to the credential lookup. `withSession()` avoids `retrieveByCredentials()`
| but not `retrieveById()`, so it buys nothing. Once the identity has to be committed anyway, driving the
| real `POST /login` is strictly better: it also pins `RedirectIfTwoFactorAuthenticatable` and the
| `login.id` / `login.remember` session keys, which are Fortify internals this file now depends on.
| `AuthScanFixturesTest`'s header records the same split-session trap from the other direction.
|
| ⚠️ THE ONE CASE THAT NEEDS NO FIXTURE IS THE ONLY ONE THAT MAY USE `withSession()` — an id naming nobody.
*/

beforeEach(function (): void {
    TenantContext::flush();

    // Put the database session into the state a real Fortify request arrives in: no user, no tenant.
    //
    // ⚠️ `applyLocal`, not `forget()`, and the difference decides whether these cases CAN fail.
    // `forget()` is session-scoped, and a session-scoped write cannot clear an in-transaction SET LOCAL
    // that is already in effect — it would look like it worked and change nothing.
    // FortifyRouteContextTest's `withoutUserContext()` is the same line for the same reason.
    //
    // ⚠️ THIS FILE HAS A STRONGER PROPERTY THAN THAT ONE, AND IT IS WORTH KNOWING RATHER THAN ASSUMING:
    // every identity below is deliberately a member of NO workspace, so neither arm of
    // `users_users_visibility` can match under a leaked context — a leaked `app.current_user_id` belongs to
    // somebody else, and a leaked `app.current_tenant_id` finds no membership row. The line stays anyway:
    // it is free, it is explicit, and the discipline is the point.
    TenantContext::applyLocal(null, null);

    purgeTwoFactorChallengeIdentities();
});

// Swept on BOTH edges. These rows are committed outside the test transaction, so an aborted run would
// otherwise leave one behind and the next run's INSERT would die on the unique email — a failure that
// reports itself as a product bug in a file about product bugs.
afterEach(fn () => purgeTwoFactorChallengeIdentities());

function purgeTwoFactorChallengeIdentities(): void
{
    DB::connection('pgsql_privileged')
        ->table('users')
        ->where('email', 'like', '%@2fachallenge.local')
        ->delete();
}

/**
 * A fully enrolled identity, committed so `pgsql_auth` can see it.
 *
 * ⚠️ NOT NAMED `committedUser` — that is already a global function in `AuthenticationTest.php`. Pest loads
 * every test file into ONE process, so a duplicate name passes every per-file run and fatals only on the
 * full suite, which is the most expensive place to find it. `tests/Pest.php` is deliberately not the home
 * for this either: its rule is to promote a helper on the FOURTH copy, and this is the first of a shape
 * (the three 2FA columns) that nothing else needs.
 *
 * ⚠️ ONE INSERT, NEVER A CREATE FOLLOWED BY A SAVE — the E2eSeeder lesson. And the secret and codes are
 * GENERATED rather than written as literals: a checked-in TOTP secret is the one thing in this file that
 * gitleaks would be right about.
 *
 * @return array{0: User, 1: string, 2: list<string>}
 */
function twoFactorIdentity(string $email, string $password, int $codes = 2): array
{
    $secret = app(Google2FA::class)->generateSecretKey();

    /** @var list<string> $plainCodes */
    $plainCodes = collect(range(1, $codes))->map(fn () => RecoveryCode::generate())->all();

    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => 'Challenge Fixture',
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($plainCodes)),
        'two_factor_confirmed_at' => now(),
    ]);

    return [$user, $secret, $plainCodes];
}

/** The code Google Authenticator would be showing right now. */
function currentOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Re-read the stored recovery codes on the PRIVILEGED connection.
 *
 * ⚠️ THE CONNECTION IS THE ASSERTION'S VALIDITY, NOT A DETAIL. The row is committed outside the test
 * transaction and the rotation lands on `pgsql_auth` (also outside), so READ COMMITTED means the default
 * connection would see the new value — except that `users_users_visibility` hides the row entirely from it
 * (no user GUC, no membership), so the read returns NOTHING and any assertion on it would be vacuous.
 * `pgsql_privileged` is the superuser role and bypasses RLS.
 *
 * @return list<string>
 */
function storedRecoveryCodes(User $user): array
{
    $encrypted = (string) DB::connection('pgsql_privileged')
        ->table('users')
        ->where('id', $user->getKey())
        ->value('two_factor_recovery_codes');

    /** @var list<string> $codes */
    $codes = json_decode(Fortify::currentEncrypter()->decrypt($encrypted), true);

    return $codes;
}

it('diverts a fully enrolled sign-in to the challenge, and the challenge renders', function (): void {
    // The two halves the lockout sat between. The divert has always worked; the render is what J3c1 fixes.
    twoFactorIdentity('renders@2fachallenge.local', 'challenge-password-2026');

    $this->post('http://acme.meridian.test/login', [
        'email' => 'renders@2fachallenge.local',
        'password' => 'challenge-password-2026',
    ])->assertRedirect('http://acme.meridian.test/two-factor-challenge');

    // Credentials passed but the login is NOT complete — that is the whole point of a second factor.
    $this->assertGuest();

    $this->withoutVite()
        ->get('http://acme.meridian.test/two-factor-challenge')
        ->assertOk()
        // ⚠️ The `false` is mandatory. Inertia's default page path is resource_path('js/pages') — LOWERCASE
        // — while this repo's directory is `resources/js/Pages`, and there is no config/inertia.php to
        // correct it. Windows resolves that case-insensitively, so the existence check passes locally and
        // CANNOT pass on Linux CI. All nine pre-existing call sites pass `false` for this reason.
        ->assertInertia(fn ($page) => $page->component('auth/TwoFactorChallenge', false));
});

it('completes the sign-in with a valid authentication code', function (): void {
    [$user, $secret] = twoFactorIdentity('totp@2fachallenge.local', 'challenge-password-2026');

    $this->post('http://acme.meridian.test/login', [
        'email' => 'totp@2fachallenge.local',
        'password' => 'challenge-password-2026',
    ]);

    $this->post('http://acme.meridian.test/two-factor-challenge', ['code' => currentOtp($secret)])
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);

    // ⚠️ NO ELEVATED CONNECTION MAY LEAK INTO REQUEST CODE. The fix resolves the pending user on
    // `pgsql_auth`, and RlsAwareUserProvider::retrieveById() resets the model to the default connection
    // before returning it. That reset is what this asserts: `meridian_auth` holds grants on `users` and (since
    // M8) a SELECT on `tenant_users`, and nothing else — so an elevated model reaching Auth::user() would still
    // fail on the first relation the request touched.
    expect(auth()->user()?->getConnectionName())->toBe(config('database.default'));
});

it('completes the sign-in with a recovery code AND rotates the code that was spent', function (): void {
    // ⚠️ THE ROTATION IS A SECOND DEFECT, NOT A DETAIL OF THE FIRST, AND IT FAILS OPEN.
    // `store()` calls `$user->replaceRecoveryCode($code)` BEFORE Auth::login(), so the write ran with no
    // user GUC: `users_users_visibility` hid the row from its own UPDATE, zero rows were affected, nothing
    // threw, save() returned true and RecoveryCodeReplaced fired. The sign-in SUCCEEDED either way — which
    // is why only the third assertion below can see it. A spent code stayed valid forever.
    [$user, , $codes] = twoFactorIdentity('recovery@2fachallenge.local', 'challenge-password-2026');
    $spent = $codes[0];

    $this->post('http://acme.meridian.test/login', [
        'email' => 'recovery@2fachallenge.local',
        'password' => 'challenge-password-2026',
    ]);

    $this->post('http://acme.meridian.test/two-factor-challenge', ['recovery_code' => $spent])
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);

    // ⚠️ THIS IS THE ONLY CASE THAT CAN SEE THE `finally` IN User::replaceRecoveryCode(), AND IT BELONGS
    // HERE RATHER THAN ON THE TOTP CASE. That method is the one piece of this increment that deliberately
    // elevates the model to `pgsql_auth`, and it is never called on the TOTP path — so asserting the
    // connection there (as the first draft did) leaves the restore itself untested: deleting the whole
    // `finally` block keeps every other assertion in this file green. `meridian_auth` holds grants on `users`
    // and (since M8) a SELECT on `tenant_users`, and nothing else — so a model left elevated at Auth::user()
    // would still fail on the first relation the next request code touched, and nothing else in the
    // repository would notice.
    expect(auth()->user()?->getConnectionName())->toBe(config('database.default'));

    $after = storedRecoveryCodes($user);

    expect($after)->not->toContain($spent)      // the spent one is gone …
        ->and($after)->toContain($codes[1])     // … the untouched one is not collateral …
        ->and($after)->toHaveCount(count($codes)); // … and it was REPLACED, not merely removed.
});

it('refuses a wrong authentication code', function (): void {
    [$secretOwner, $secret] = twoFactorIdentity('wrongcode@2fachallenge.local', 'challenge-password-2026');

    $this->post('http://acme.meridian.test/login', [
        'email' => 'wrongcode@2fachallenge.local',
        'password' => 'challenge-password-2026',
    ]);

    // ⚠️ DERIVED FROM THE LIVE CODE, NEVER A LITERAL LIKE '000000'. A fixed string is a live OTP once in a
    // million runs, and that flake would arrive as a security test passing for the wrong reason. Adding one
    // to the DIGITS is not a step in TIME, so Fortify's default one-window tolerance cannot rescue it.
    $wrong = str_pad((string) ((((int) currentOtp($secret)) + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

    $this->post('http://acme.meridian.test/two-factor-challenge', ['code' => $wrong])
        ->assertRedirect('http://acme.meridian.test/two-factor-challenge')
        ->assertSessionHasErrors('code');

    $this->assertGuest();

    // The challenge is still live afterwards — a wrong code must not consume it. `hasValidCode()` forgets
    // `login.id` only on success, so this is the assertion that says so.
    expect(session()->has('login.id'))->toBeTrue();
});

it('turns a soft-deleted pending identity away at both verbs', function (): void {
    // Vendor parity, asserted rather than assumed: `retrieveById()` builds from newModelQuery(), which
    // applies this model's SoftDeletingScope — so a trashed row resolves to null exactly as the vendor's
    // `$model::find()` did. Without this case, adding `->withTrashed()` to the lookup would go unnoticed.
    [$user, $secret] = twoFactorIdentity('trashed@2fachallenge.local', 'challenge-password-2026');

    // ⚠️ THE DIVERT IS ASSERTED, NOT ASSUMED, AND WITHOUT THIS LINE THE CASE IS VACUOUS. Every assertion
    // below is also satisfied by a login that never happened at all: with no `login.id` in the session the
    // GET redirects to `login` and the POST bounces, so a broken fixture, a rejected credential or a
    // throttled request would leave this case green while proving nothing about soft deletion. It would
    // then be a silent duplicate of the "naming nobody" case underneath it.
    $this->post('http://acme.meridian.test/login', [
        'email' => 'trashed@2fachallenge.local',
        'password' => 'challenge-password-2026',
    ])->assertRedirect('http://acme.meridian.test/two-factor-challenge');

    expect(session()->has('login.id'))->toBeTrue();

    // ⚠️ ON THE PRIVILEGED CONNECTION. The row is committed outside the test transaction, so a default
    // connection UPDATE would affect ZERO rows, throw nothing, and leave this case passing against a live
    // user — the identical fail-open shape this whole increment is about.
    $affected = DB::connection('pgsql_privileged')
        ->table('users')
        ->where('id', $user->getKey())
        ->update(['deleted_at' => now()]);

    expect($affected)->toBe(1);

    $this->get('http://acme.meridian.test/two-factor-challenge')
        ->assertRedirect(route('login'));

    $this->post('http://acme.meridian.test/two-factor-challenge', ['code' => currentOtp($secret)]);

    $this->assertGuest();
});

it('turns away a challenge session naming nobody', function (): void {
    // The only case that needs no fixture, and therefore the only one that may seed `login.id` directly.
    $this->withSession(['login.id' => (string) Str::uuid7(), 'login.remember' => false])
        ->get('http://acme.meridian.test/two-factor-challenge')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
