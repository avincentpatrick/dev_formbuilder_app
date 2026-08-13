<?php

declare(strict_types=1);

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 * Assert that `$password` satisfies EVERY rule in the policy except `uncompromised()`.
 *
 * ⚠️ THIS IS WHAT KEEPS THE TWO BREACH TESTS BELOW HONEST, AND J3a IS WHY IT NOW HAS TO EXIST.
 * `Rules\Password::passes()` runs the length check and all four character-class checks inside ONE inner
 * validator, and reaches the HIBP verifier **only if that validator passes** — structurally, regardless of
 * the order the builder chain was written in. So a fixture that fails any class is rejected before a single
 * request is made, and a test asserting "this was rejected by the breach check" would pass **for the wrong
 * reason, with zero requests sent**.
 *
 * That was a real risk here rather than a theoretical one: before J3a all four fixtures in this file were
 * lowercase-only passphrases, and adding `->mixedCase()` to the defaults would have silently converted both
 * breach tests into length-and-class tests wearing a breach test's name.
 */
function expectSatisfiesEveryRuleButBreach(string $password): void
{
    $withoutBreachCheck = Password::min(PasswordPolicy::MIN_LENGTH)->letters()->mixedCase()->numbers()->symbols();

    expect(validator(['password' => $password], ['password' => $withoutBreachCheck])->fails())
        ->toBeFalse('fixture must satisfy every rule but uncompromised(), or the breach check is never reached');
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
    $strong = 'Correct-Horse-Battery-9';
    expectSatisfiesEveryRuleButBreach($strong);

    $this->post('/register', [
        'name' => 'New Person',
        'email' => 'reg@authtest.local',
        'password' => $strong,
        'password_confirmation' => $strong,
    ]);

    $this->assertAuthenticated();

    // Exactly one breach lookup: the short password never got that far. If this becomes 2, the framework
    // stopped early-returning on length and the test above is no longer testing what its name says.
    Http::assertSentCount(1);
});

it('rejects a new password that satisfies the length but not the four character classes', function (): void {
    fakeHibp([]);

    // J3a. Long, memorable, un-breached — and lowercase-only, so it fails `mixedCase()` and `numbers()`.
    // This is the exact shape of every password fixture in the suite before J3a, which is why it is worth
    // its own case rather than being folded into the length test above.
    $noClasses = 'correct-horse-battery-staple';

    $this->from('/register')->post('/register', [
        'name' => 'New Person',
        'email' => 'classes@authtest.local',
        'password' => $noClasses,
        'password_confirmation' => $noClasses,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();

    // ⚠️ ZERO, not one. The class failure short-circuits the inner validator, so the breach lookup is never
    // made — which is the property `expectSatisfiesEveryRuleButBreach()` exists to defend in the two tests
    // below, stated here as a positive assertion so the mechanism is pinned rather than assumed.
    Http::assertSentCount(0);
});

it('rejects a long but breached password on registration', function (): void {
    // Satisfies min-12 AND all four classes, so the ONLY rule left to reject it is uncompromised() — which
    // is the point: this is the test that proves the breached-password check is wired, not merely configured.
    $breached = 'Trustno1-Trustno1!';
    expectSatisfiesEveryRuleButBreach($breached);

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

    // ⚠️ `assertSent` alone is satisfied by ANY matching request and says nothing about how many were made;
    // it is the COUNT that proves the class gate let this through to the verifier exactly once.
    Http::assertSentCount(1);
});

it('rejects a breached password when accepting an invitation', function (): void {
    // The invitation-accept form is single-field, so it cannot inherit `'confirmed'` — historically it
    // declared its own rules inline, making it the one password surface that could silently diverge. J3a
    // moved that divergence onto a NAMED METHOD of the shared trait; the case below pins that the two
    // methods differ by exactly `'confirmed'` and nothing else, so a future rule cannot reach three
    // surfaces and miss the fourth.
    $breached = 'Letmein-Letmein-42!';
    $unused = 'A-Genuinely-Unused-Passphrase-9143';

    expectSatisfiesEveryRuleButBreach($breached);
    expectSatisfiesEveryRuleButBreach($unused);

    fakeHibp([$breached]);

    $surface = new class
    {
        use PasswordValidationRules;

        /** @return array<int, mixed> */
        public function confirmed(): array
        {
            return $this->passwordRules();
        }

        /** @return array<int, mixed> */
        public function unconfirmed(): array
        {
            return $this->passwordRulesUnconfirmed();
        }
    };

    // Compared by SHAPE, not by identity: `Password::default()` builds a fresh instance per call, so two
    // structurally identical rule sets are never the same object and `toBe()` would fail on a difference
    // that does not exist. Mapping each rule to its class name (or its own string) pins what actually
    // matters — the same rules, in the same order, differing by exactly the trailing `'confirmed'`.
    $shape = fn (array $rules): array => array_map(
        fn (mixed $rule): string => is_object($rule) ? $rule::class : (string) $rule,
        $rules,
    );

    expect($shape($surface->confirmed()))->toBe([...$shape($surface->unconfirmed()), 'confirmed']);

    $rules = ['password' => $surface->unconfirmed()];

    expect(validator(['password' => $breached], $rules)->fails())->toBeTrue();
    expect(validator(['password' => $unused], $rules)->fails())->toBeFalse();
});
