<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment M70 — the positive control for `POST /user/confirm-password`.
|--------------------------------------------------------------------------
| ⛔ NOTHING IN THIS REPOSITORY ASSERTED THAT A CORRECT PASSWORD CONFIRMS, and `M68` filed the row after
| trying: a case posting the factory's own password against a `User::factory()` identity failed with
| "The provided password was incorrect". The cause is structural rather than a fixture mistake.
| `config/auth.php`'s provider is `rls_aware`, so `RlsAwareUserProvider::createModel()` resolves the
| lookup on the SEPARATE `pgsql_auth` connection; Fortify's default `ConfirmPassword` action calls
| `$guard->validate()`, which goes through that provider; and under `RefreshDatabase` the whole test is
| one open transaction on the DEFAULT connection, which a second session cannot see. So the row is never
| found and the answer is always "incorrect", whatever is posted.
|
| ⚠️ THE ROW CALLED THE REMEDY "A CHOICE" AND IT IS NOT ONE ANY MORE. It offered committing the fixture
| outside the transaction OR binding the auth provider to the default connection under test. The repo
| took the first years ago and wrote it down as a rule — `docs/testing-strategy.md` requires auth
| fixtures to be seeded with a committed write on the privileged connection — and there are six helpers
| implementing it, of which `committedTenantIdentity()` is the promoted global one. The second is not
| merely unchosen but hostile: `rls_aware` is the explicit SUBJECT of at least six suites, two of which
| assert `getConnectionName()` and name deleting `retrieveById()`'s `setConnection()` as their own
| mutation check. Rebinding the provider under test would make those vacuous. A fixture swap, not a fork.
|
| ⛔⛔ THE STATUS CODE IS USELESS HERE AND THE FIRST CASE BELOW EXISTS TO SAY SO IN AN ASSERTION RATHER
| THAN A COMMENT. `FailedPasswordConfirmationResponse::toResponse()` returns `back()->withErrors(...)`
| for a non-JSON request — a 302 — and a success returns `redirect()->intended(...)`, also a 302. That is
| why `FortifyRateLimitTest` posting a correct password and asserting `assertStatus(302)` READS as a
| positive control for the credential while proving nothing about it. It is unaffected for its own
| purpose (302 vs 429) and is deliberately left alone; this file is where the credential is claimed.
|
| ⚠️ BOTH ARMS ARE REQUIRED AND NEITHER IS DECORATIVE. Without the refusal case the success case is
| satisfied by a permissive `Fortify::confirmPasswordsUsing(fn () => true)`; without the success case the
| refusal case is satisfied by the very defect this file exists to close. They are proved as a pair.
*/

beforeEach(function (): void {
    TenantContext::flush();

    // The password `committedTenantIdentity()` hashes. Read from the helper's behaviour rather than
    // re-declared: a second copy of a fixture password is the paired-artefact hazard in miniature.
    $this->identity = committedTenantIdentity('Confirming Member');
    $this->correct = 'secret-password-123';
});

it('mints a confirmation when the password is correct', function (): void {
    $response = $this->actingAs($this->identity)
        ->post('/user/confirm-password', ['password' => $this->correct]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('auth.password_confirmed_at');

    // ⛔ ASSERTED, NOT NOTED: the status cannot tell the two outcomes apart, which is the whole reason
    // the two assertions above are about the session. If this ever stops being 302 the file above it in
    // this comment block needs re-reading, not "fixing".
    expect($response->getStatusCode())->toBe(302);
});

it('refuses an incorrect password and mints nothing', function (): void {
    $response = $this->actingAs($this->identity)
        ->post('/user/confirm-password', ['password' => 'not-the-password']);

    $response->assertSessionHasErrors('password');
    $response->assertSessionMissing('auth.password_confirmed_at');

    // The same 302 as the success above. This pair IS the evidence for the sentence in the header.
    expect($response->getStatusCode())->toBe(302);
});

it('runs the real credential check rather than an overridden callback', function (): void {
    // ⚠️ THE VACUITY GUARD, and it is not hypothetical: this file's success case is fully satisfied by a
    // `Fortify::confirmPasswordsUsing(fn () => true)` registered anywhere in the application. Fortify's
    // own action branches on that callback being null, so if one is ever registered the two cases above
    // stop measuring the credential and nothing else would say so.
    expect(Fortify::$confirmPasswordsUsingCallback)->toBeNull(
        'a confirm-password callback is registered, so the cases in this file no longer test the credential',
    );
});

it('is the committed identity that makes the credential reachable, and an in-transaction one that does not', function (): void {
    // ⛔ THE NEGATIVE CONTROL FOR THE FIXTURE ITSELF, kept because it is the entire finding of `M68`'s
    // row and the reason this file exists. A `User::factory()` identity is written inside
    // `RefreshDatabase`'s open transaction; `pgsql_auth` is a separate session and cannot see it; so the
    // credential check finds no row and refuses a password that is provably correct.
    //
    // This pins the TRAP, not a product behaviour — if it ever goes green, the separate-session
    // constraint has changed and `committedTenantIdentity()`'s reason for existing should be re-read.
    $inTransaction = App\Models\User::factory()->create([
        'password' => Illuminate\Support\Facades\Hash::make($this->correct),
    ]);

    $response = $this->actingAs($inTransaction)
        ->post('/user/confirm-password', ['password' => $this->correct]);

    $response->assertSessionHasErrors('password');
    $response->assertSessionMissing('auth.password_confirmed_at');
});
