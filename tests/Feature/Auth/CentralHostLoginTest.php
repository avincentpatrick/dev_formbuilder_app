<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A REAL `POST /login` on the CENTRAL host (Increment I10e — diagnostic).
|--------------------------------------------------------------------------
| Written to localise a specific, evidenced e2e failure in ONE run rather than ~2 minutes of CI per
| guess. `tests/e2e/global-setup.ts` signs a seeded super-admin into the central-domain console and
| the session does not survive the next request. From CI run 31278022831:
|
|     POST 302 http://meridian.test:8000/login
|     GET  302 http://meridian.test:8000/admin/settings
|     GET  200 http://meridian.test:8000/login
|     Cookies: XSRF-TOKEN@meridian.test, meridian-session@meridian.test
|
| What that already rules out: CSRF (a 419 is not a 302, and both cookies round-tripped), and — because
| there is NO `GET /login` between the POST and the `/admin/settings` navigation — a rejected credential,
| which would have redirected `back()` and produced one. The `/admin/settings` 302 is `Authenticate`
| (`redirect()->guest(route('login'))`): step-up goes to /user/confirm-password, `superadmin` aborts 404,
| and `superadmin.mfa` goes to /admin/two-factor. So the web guard sees a GUEST on the second request.
|
| ⚠️ TWO BLIND SPOTS. A green run here does NOT clear everything, and reading it that way is the mistake
| this file exists to prevent:
|   1. `phpunit.xml:63` pins SESSION_DRIVER=array. CI's e2e job runs SESSION_DRIVER=file. Nothing here
|      can see a fault in the file/database session STORE.
|   2. The browser posts this form as an INERTIA XHR (`resources/js/pages/auth/Login.vue` → `form.post`),
|      so axios follows the 302 at the network layer. Nothing here exercises that redirect-following,
|      which is where a cross-origin hop to `config('app.url')` would die silently.
| What it DOES cover is the whole server side: Fortify's pipeline, the guard, the RLS-aware provider and
| every central-host middleware.
|
| ⚠️ THE HOST MUST BE SPELLED OUT. `phpunit.xml` forces CENTRAL_DOMAIN=meridian.test but sets no APP_URL,
| so a relative `post('/login')` resolves against `http://localhost:8080` — also a central domain, but
| NOT the one `routes/admin.php`'s `Route::domain(config('tenancy.central_domain'))` is bound to, so
| /admin/* would not route there at all and the result would be a 404 that means nothing.
|
| ⚠️ THE USER MUST BE COMMITTED OUTSIDE THE TRANSACTION. RefreshDatabase wraps only the DEFAULT
| connection; RlsAwareUserProvider resolves credentials on the separate `pgsql_auth` session, which
| cannot see uncommitted rows. This is the same trap AuthenticationTest's header records, and it is why
| `User::factory()->create()` + `actingAs()` works everywhere else — that path never calls
| retrieveByCredentials.
*/

const CONSOLE_LOGIN_PASSWORD = 'meridian-console-2026';

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('users')->where('email', 'like', '%@consoletest.local')->delete();
});

/**
 * The e2e fixture's exact shape, committed so `pgsql_auth` can see it.
 *
 * `two_factor_confirmed_at` SET with a NULL `two_factor_secret` is deliberate and is the whole reason
 * I10e needs no TOTP: Fortify's `hasEnabledTwoFactorAuthentication()` requires BOTH columns when
 * `fortify.features.two-factor.confirm` is true, so no challenge is issued, while `EnsureSuperAdminMfa`
 * reads ONLY the timestamp and lets the console through. ⚠️ NOT `UserFactory::confirmedTwoFactor()`,
 * which writes a placeholder secret and would lock the account out permanently.
 */
/**
 * Model the boundary between two real HTTP requests, which `$this->post()` → `$this->get()` does NOT.
 *
 * ⚠️ WITHOUT THIS, THESE TESTS EXERCISE A STATE PRODUCTION NEVER HAS, and it took the full suite to show
 * it. `RlsAwareUserProvider::retrieveByCredentials()` deliberately leaves the authenticated model on the
 * `pgsql_auth` connection (its docblock argues why — the password-reset save needs that connection's
 * permissive UPDATE policy). Only `retrieveById()` resets it to the default, and in production that runs on
 * EVERY subsequent request. The test harness keeps one container alive, so the guard hands the next request
 * the same `pgsql_auth`-bound instance — and `meridian_auth` is granted `users` and nothing else, so the
 * first Spatie permission check on that model dies with `SQLSTATE[42501] permission denied for table
 * permissions`.
 *
 * ⚠️ AND IT IS INVISIBLE IN A SINGLE-FILE RUN. With an empty catalog Spatie throws `PermissionDoesNotExist`
 * and `can()` swallows it before the relation is ever loaded; the query only happens once some EARLIER file
 * has seeded the catalog into the shared permission cache. Green alone, red in the suite — so do not
 * "simplify" these three calls away after checking one file.
 *
 * Forgetting the guards makes the next request re-resolve from the session through `retrieveById()`, which
 * is both the faithful thing and a free mutation check: delete that method's `setConnection()` and the
 * third case below goes red.
 */
function endOfRequest(): void
{
    app('auth')->forgetGuards();
}

function committedConsoleOperator(string $email = 'operator@consoletest.local'): User
{
    return User::on('pgsql_privileged')->forceCreate([
        'name' => 'Console Operator',
        'email' => $email,
        'password' => CONSOLE_LOGIN_PASSWORD, // the model's 'hashed' cast hashes it
        'email_verified_at' => now(),
        'is_super_admin' => true,
        'two_factor_confirmed_at' => now(),
        'two_factor_secret' => null,
    ]);
}

it('authenticates a super-admin through a real POST /login on the central host', function (): void {
    committedConsoleOperator();

    $response = $this->post('http://meridian.test/login', [
        'email' => 'operator@consoletest.local',
        'password' => CONSOLE_LOGIN_PASSWORD,
    ]);

    // Ordered so the first failure names the cause. (1) kills "the credentials were rejected"…
    $response->assertSessionHasNoErrors();
    // …(2) is the assertion the e2e never made, and the one that separates "login failed" from
    // "login succeeded and the SESSION was lost afterwards".
    $this->assertAuthenticated();
    // (3) documents the hazard rather than asserting a good outcome: `fortify.home` is /dashboard, a
    // TENANT route. On the central host it cannot resolve a tenant, so Fortify's success redirect points
    // somewhere meaningless here. That is a real defect in the flow even when authentication works.
    $response->assertRedirect('http://meridian.test/dashboard');
});

it('carries that session into the console, stopping only at the step-up challenge', function (): void {
    committedConsoleOperator();

    $this->post('http://meridian.test/login', [
        'email' => 'operator@consoletest.local',
        'password' => CONSOLE_LOGIN_PASSWORD,
    ]);
    endOfRequest();

    // Fortify's login does NOT set `auth.password_confirmed_at`, so a genuinely-logged-in operator is
    // SUPPOSED to be bounced here. Asserting the step-up target rather than fighting it is the point:
    // reaching /user/confirm-password proves `auth`, `superadmin` and `superadmin.mfa` all passed, which
    // is exactly what the e2e failure says did not happen.
    $this->get('http://meridian.test/admin/settings')
        ->assertRedirect('http://meridian.test/user/confirm-password');
});

it('renders the console once the step-up is confirmed', function (): void {
    $this->withoutVite();
    committedConsoleOperator();

    $this->post('http://meridian.test/login', [
        'email' => 'operator@consoletest.local',
        'password' => CONSOLE_LOGIN_PASSWORD,
    ]);

    // AFTER the login: PrepareAuthenticatedSession regenerates the session, so a confirmation planted
    // before the POST would be discarded and this would fail for a reason that has nothing to do with
    // the console.
    confirmPasswordNow();
    endOfRequest();

    $this->get('http://meridian.test/admin/settings')->assertOk();
});

/*
|--------------------------------------------------------------------------
| The SECOND defect, pinned separately.
|--------------------------------------------------------------------------
| `E2eSeeder::seedSuperAdmin()` (and `DemoSeeder::ensureSuperAdmin()`) promote the operator with
| `$user->forceFill([...])->save()` on the DEFAULT `meridian_app` connection with no RLS context set.
| `users` carries ENABLE **and FORCE** ROW LEVEL SECURITY — TenantIsolation::enableAndForce() applies it
| even to the table owner, and CI creates the database OWNER meridian_app, so the owner exemption does
| not save it. The SELECT policy is join-shaped and fails closed with no context; the permissive
| carve-out is `TO meridian_auth` only. PostgreSQL applies SELECT policies to an UPDATE that reads
| columns, and `WHERE id = ?` does.
|
| A console operator has NO tenant membership by design, so it is invisible from any context and the
| promotion can affect ZERO rows, silently, with no error. The PR body states that invisibility as its
| reason for not writing a Pest case, without noticing it applies to the seeder's own write.
|
| ⚠️ This produces a 404 (`EnsureSuperAdmin`) or an /admin/two-factor redirect (`EnsureSuperAdminMfa`),
| NOT the /login redirect the e2e reports. It is a separate bug; do not let a red here be mistaken for
| the answer to the one above.
|
| Both arms are pinned. Arm 1 keeps the hazard itself under test, so a later "simplification" back to the
| default connection reddens instead of silently un-promoting the operator; arm 2 proves the fix. The
| seeder methods are private and their identities are committed on `pgsql_auth`, which RefreshDatabase's
| transaction cannot see — so the mechanism is tested here, which is where the bug actually lived.
*/
it('promotes a membership-less operator only on the privileged connection', function (): void {
    $operator = committedConsoleOperator('promote@consoletest.local');

    // Re-reading on the privileged connection every time: the app connection cannot SEE this row either, so
    // asserting through Eloquent's default connection would report "absent" whatever actually happened.
    $stored = fn () => DB::connection('pgsql_privileged')->table('users')->where('id', $operator->id)->first();

    // ⚠️ EACH ARM MUST RE-RESOLVE THE MODEL, exactly as `resolveOrCreateUser()` does. Reusing one instance
    // across both makes the second `forceFill()` non-dirty — Eloquent synced those attributes clean on the
    // first save — so `save()` returns true having issued no UPDATE at all, and BOTH arms then pass for a
    // reason that has nothing to do with row-level security. The first version of this test did exactly
    // that and its green arm 1 was vacuous.
    $demote = fn () => DB::connection('pgsql_privileged')->table('users')->where('id', $operator->id)
        ->update(['is_super_admin' => false, 'two_factor_confirmed_at' => null]);
    $resolve = fn (): User => User::on('pgsql_auth')->where('email', 'promote@consoletest.local')->firstOrFail();

    // ── Arm 1: the shape both seeders shipped. It reports success and changes nothing. ──
    $demote();
    $resolve()->setConnection((string) config('database.default'))
        ->forceFill(['is_super_admin' => true, 'two_factor_confirmed_at' => now()])->save();

    expect($stored()->is_super_admin)->toBeFalse('the app connection must not be able to promote an invisible row')
        ->and($stored()->two_factor_confirmed_at)->toBeNull();

    // ── Arm 2: the fix. Same resolution, same attributes, privileged connection. ──
    $demote();
    $resolve()->setConnection('pgsql_privileged')
        ->forceFill(['is_super_admin' => true, 'two_factor_confirmed_at' => now()])->save();

    expect($stored()->is_super_admin)->toBeTrue()
        ->and($stored()->two_factor_confirmed_at)->not->toBeNull();
});
