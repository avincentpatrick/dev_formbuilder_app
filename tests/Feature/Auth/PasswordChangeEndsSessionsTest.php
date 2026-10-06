<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M140 (`R-d060bb77`) — a new password ends every OTHER session of the account.
|--------------------------------------------------------------------------
| Whoever registered an address first kept the session that registration opened, through the owner's reset and
| confirmation, and could then move the account's email through `PUT /user/profile-information` (only `auth`).
| The suite runs the `array` session driver (phpunit.xml); every deployment runs `database`, which is the only
| driver that can be searched by user, so each case switches to it and writes its session rows by hand.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config(['session.driver' => 'database']);
});

/** A `sessions` row for $userId — what a signed-in device leaves. Returns its id. */
function sessionRowFor(?string $userId, string $device, ?string $id = null): string
{
    $id ??= Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => $device,
        'payload' => base64_encode(''),
        'last_activity' => now()->timestamp,
    ]);

    return $id;
}

/** @return list<string> */
function sessionIds(): array
{
    return DB::table('sessions')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
}

it('ends every session of the account on a password reset, and nobody else’s', function (): void {
    $owner = User::factory()->create();
    $someoneElse = User::factory()->create();

    sessionRowFor($owner->id, 'the squatter’s browser');
    sessionRowFor($owner->id, 'a forgotten laptop');
    $kept = sessionRowFor($someoneElse->id, 'someone else');

    // Raised by Fortify's `CompletePasswordReset`; through the real dispatcher, so the listener's wiring is proved.
    event(new PasswordReset($owner));

    expect(sessionIds())->toBe([$kept]);
});

it('keeps only the session that changed the password, and rotates the remember token', function (): void {
    fakeHibp();
    $owner = User::factory()->create(['remember_token' => 'remembered-on-another-device']);

    $current = sessionRowFor($owner->id, 'the device making the change', Str::random(40));
    sessionRowFor($owner->id, 'another device');
    $someoneElses = sessionRowFor(null, 'a guest');

    $this->actingAs($owner)
        ->withCookie((string) config('session.cookie'), $current)
        ->put('http://meridian.test/user/password', [
            'current_password' => 'password',
            'password' => 'A-New-Passphrase-9!',
            'password_confirmation' => 'A-New-Passphrase-9!',
        ])
        ->assertSessionHasNoErrors();

    // The changing device's session survives the request that changed the password; the other device's does not.
    expect(sessionIds())->toEqualCanonicalizing([$current, $someoneElses]);

    // And no other device's "remember me" cookie can sign it back in. Re-read under the owner's own context:
    // `users` row security admits a row to its own user, and the GUC died with the request.
    TenantContext::applyLocal(null, $owner->id);
    $tokenAfter = User::query()->findOrFail($owner->id)->getRememberToken();
    expect($tokenAfter)->not->toBeEmpty()->and($tokenAfter)->not->toBe('remembered-on-another-device');
});
