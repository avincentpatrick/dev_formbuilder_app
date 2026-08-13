<?php

declare(strict_types=1);

use App\Support\Auth\GoogleIdentity;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
|--------------------------------------------------------------------------
| The Google claim mapping (Increment J3c2) — the one part of the seam with logic in it.
|--------------------------------------------------------------------------
| `SocialiteGoogleIdentityProvider` is deliberately thin: it calls a third party and hands the result
| here. So this is where the assertions live, and none of them need a network, a fake HTTP client or a
| Google credential.
|
| ⚠️ THE `email_verified` CASES ARE THE SECURITY ONES. That claim is this flow's analogue of SAML's
| signature check (ADR-0017 §D3): it is the entire basis for believing the address belongs to the person
| holding the account, and an account that can attach an UNVERIFIED alias could otherwise sign in as
| somebody else's address. It arrives inside a raw JSON array, so it can be absent, null, or a string —
| and `(bool) "false"` is `true`, which is the trap these cases exist for.
*/

/** Build a Socialite user the way the Google driver does: typed accessors plus the raw claim array. */
function socialiteUser(array $raw, ?string $id = 'sub-123', ?string $email = 'person@example.test', ?string $name = 'Real Name'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->email = $email;
    $user->name = $name;

    return $user->setRaw($raw);
}

it('maps a fully verified Google identity', function (): void {
    $identity = GoogleIdentity::fromSocialiteUser(socialiteUser(['email_verified' => true]));

    expect($identity->subject)->toBe('sub-123')
        ->and($identity->email)->toBe('person@example.test')
        ->and($identity->emailVerified)->toBeTrue()
        ->and($identity->name)->toBe('Real Name');
});

it('lower-cases the address, because it is the join to an existing account', function (): void {
    // `users.email` is unique and stored lower-cased by every other door; an identity arriving as
    // `Person@Example.test` must find the same row, not create a second one.
    $identity = GoogleIdentity::fromSocialiteUser(
        socialiteUser(['email_verified' => true], email: 'Person@EXAMPLE.test')
    );

    expect($identity->email)->toBe('person@example.test');
});

it('fails closed on every shape of email_verified that is not literally true', function (mixed $claim): void {
    $identity = GoogleIdentity::fromSocialiteUser(socialiteUser(['email_verified' => $claim]));

    expect($identity->emailVerified)->toBeFalse();
})->with([
    'absent-as-null' => [null],
    'boolean false' => [false],
    // ⚠️ The two that a loose `(bool)` or `==` check would get WRONG, in opposite directions.
    'the string "true"' => ['true'],
    'the string "false"' => ['false'],
    'integer 1' => [1],
    'integer 0' => [0],
    'empty string' => [''],
]);

it('fails closed when the claim is missing entirely', function (): void {
    // Not the same as `email_verified => null`: this is a payload that never mentioned it, which is what
    // a changed Google scope set or a different provider would produce.
    expect(GoogleIdentity::fromSocialiteUser(socialiteUser([]))->emailVerified)->toBeFalse();
});

it('falls back to the local part when Google withholds a name', function (mixed $name): void {
    // `users.name` is NOT NULL and is rendered in the members roster, and a profile name is genuinely
    // optional on a Google account. An empty string here would put a blank row in front of an Owner.
    $identity = GoogleIdentity::fromSocialiteUser(
        socialiteUser(['email_verified' => true], email: 'greta@example.test', name: $name)
    );

    expect($identity->name)->toBe('greta');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace only' => ['   '],
]);

it('trims a name that arrives padded', function (): void {
    $identity = GoogleIdentity::fromSocialiteUser(
        socialiteUser(['email_verified' => true], name: '  Greta Google  ')
    );

    expect($identity->name)->toBe('Greta Google');
});
