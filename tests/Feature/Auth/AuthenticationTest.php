<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

it('registers a new user and rejects a breached password', function (): void {
    // 'password' is both < 12 chars and a known-breached password → rejected by Password::defaults().
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
});
