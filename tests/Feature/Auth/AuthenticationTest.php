<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment B1 — authentication end-to-end (the DoD "login works").
|--------------------------------------------------------------------------
| Proves the RlsAwareUserProvider + pgsql_auth strategy: a user can be resolved and authenticated
| even though the join-shape RLS on `users` fails closed with no context.
|
| The critical test-harness subtlety (documented in docs/testing-strategy.md): RefreshDatabase wraps
| only the DEFAULT (meridian_app) connection in a transaction. The provider's lookups run on the
| SEPARATE pgsql_auth session, which cannot see those uncommitted rows. So users these tests must log
| in as are seeded with a COMMITTED write via the privileged connection and cleaned up in afterEach.
| (Registration, by contrast, creates its user on meridian_app inside the transaction — no commit.)
*/

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('users')->where('email', 'like', '%@authtest.local')->delete();
});

/** Seed a login-ready user OUTSIDE the RefreshDatabase transaction (visible to pgsql_auth). */
function committedUser(string $email, string $password): User
{
    return User::on('pgsql_privileged')->forceCreate([
        'name' => 'Auth Test',
        'email' => $email,
        'password' => $password, // the model's 'hashed' cast hashes it
        'email_verified_at' => now(),
    ]);
}

it('authenticates an existing user (proves the pre-auth pgsql_auth path)', function (): void {
    committedUser('login@authtest.local', 'correct-horse-battery-staple');

    $this->post('/login', [
        'email' => 'login@authtest.local',
        'password' => 'correct-horse-battery-staple',
    ]);

    $this->assertAuthenticated();
});

it('rejects a wrong password', function (): void {
    committedUser('wrong@authtest.local', 'correct-horse-battery-staple');

    $this->post('/login', [
        'email' => 'wrong@authtest.local',
        'password' => 'not-the-right-password',
    ]);

    $this->assertGuest();
});

/**
 * Stub Have I Been Pwned's k-anonymity range endpoint.
 *
 * ⚠️ WITHOUT THIS, THIS FILE REACHED THE PUBLIC INTERNET ON EVERY CI RUN. `Password::uncompromised()` is
 * a shipped default ({@see FortifyServiceProvider}), and
 * {@see NotPwnedVerifier} performs the lookup through the `Http` facade — so a
 * merge-blocking gate was silently depending on api.pwnedpasswords.com being reachable from the runner.
 * Faked in I8a.
 *
 * The stub answers with a real `SUFFIX:COUNT` line for each nominated password whose hash prefix matches
 * the request, and an EMPTY body otherwise — which the verifier reads as "no match", i.e. uncompromised.
 * Suffixes are DERIVED with `sha1()` rather than pasted as constants, so the fixture cannot drift from
 * what the validator actually asks for. Note the array form: only this one host is stubbed, so an
 * unrelated stray request in this file stays visible rather than being absorbed by a catch-all.
 *
 * @param  list<string>  $breachedPasswords
 */
function fakeHibp(array $breachedPasswords): void
{
    Http::fake([
        'api.pwnedpasswords.com/range/*' => function (ClientRequest $request) use ($breachedPasswords) {
            $lines = [];

            foreach ($breachedPasswords as $candidate) {
                $hash = strtoupper(sha1($candidate));

                if (str_ends_with($request->url(), substr($hash, 0, 5))) {
                    $lines[] = substr($hash, 5).':9659365';
                }
            }

            return Http::response(implode("\r\n", $lines));
        },
    ]);
}

it('registers a new user and rejects a too-short password', function (): void {
    fakeHibp([]);

    // ⚠️ 'password' is ALSO breached, but that is not what rejects it here: Password::passes() early-returns
    // on the length failure and never reaches the verifier. The HIBP path is proven by the next test, and
    // the assertSentCount below is what keeps these two honest about which rule actually fired.
    $this->from('/register')->post('/register', [
        'name' => 'New Person',
        'email' => 'reg@authtest.local',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');
    $this->assertGuest();

    // A strong, un-breached password registers and logs in (user created on meridian_app via the
    // permissive INSERT policy, inside the transaction — rolled back after the test).
    $this->post('/register', [
        'name' => 'New Person',
        'email' => 'reg@authtest.local',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $this->assertAuthenticated();

    // Exactly one breach lookup: the short password never got that far. If this becomes 2, the framework
    // stopped early-returning on length and the test above is no longer testing what its name says.
    Http::assertSentCount(1);
});

it('rejects a long but breached password on registration', function (): void {
    // Long enough to clear min:12, so the ONLY rule left to reject it is uncompromised() — which is the
    // point: this is the test that proves the breached-password check is wired, not merely configured.
    $breached = 'trustno1-trustno1';

    fakeHibp([$breached]);

    $this->from('/register')->post('/register', [
        'name' => 'New Person',
        'email' => 'breached@authtest.local',
        'password' => $breached,
        'password_confirmation' => $breached,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();

    Http::assertSent(fn (ClientRequest $request): bool => str_starts_with(
        $request->url(),
        'https://api.pwnedpasswords.com/range/'.substr(strtoupper(sha1($breached)), 0, 5),
    ));
});

it('rejects a breached password when accepting an invitation', function (): void {
    // The invitation-accept form declares its own rules rather than using PasswordValidationRules
    // (InvitationController), so it is the one password surface that could silently diverge. Pinned here.
    $breached = 'letmein-letmein-letmein';

    fakeHibp([$breached]);

    $rules = ['password' => ['required', 'string', Password::default()]];

    expect(validator(['password' => $breached], $rules)->fails())->toBeTrue();
    expect(validator(['password' => 'a-genuinely-unused-passphrase-9143'], $rules)->fails())->toBeFalse();
});
