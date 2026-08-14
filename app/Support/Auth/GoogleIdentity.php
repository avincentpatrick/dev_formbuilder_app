<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\Tenancy\DnsTxtResolver;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * What Google told us about a person, reduced to the four things this product acts on (Increment J3c2).
 *
 * A value object rather than passing Socialite's `User` around, for the reason
 * {@see DnsTxtResolver} gives about `dns_get_record`'s array shape: letting the
 * vendor's object cross the seam would put its accessors into every consumer and every fake, and would
 * make swapping the adapter — the whole point of {@see GoogleIdentityProvider} — a rewrite rather than a
 * binding change.
 *
 * ⚠️ `subject` IS THE IDENTITY AND THE EMAIL IS NOT. Google's `sub` is stable for the life of the account;
 * the address is not — a Workspace administrator can reassign `alice@company.com` to a different human
 * after Alice leaves. Anything that keys a local account off the address is a takeover waiting for a
 * staff change, which is why ADR-0017 §D2 makes the email a one-time joining hint and §D5 forbids ever
 * rewriting `users.email` from it.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $name,
    ) {}

    /**
     * Map Socialite's user onto this contract.
     *
     * ⚠️ THE PARAMETER IS SOCIALITE'S **CONCRETE** `Two\User`, NOT ITS `Contracts\User`, AND PHPStan IS
     * WHAT CAUGHT THAT. `getRaw()` — the accessor this whole method turns on, because `email_verified`
     * lives only in the raw claim array — exists on the concrete class and is ABSENT from the interface.
     * Typed against the contract this compiled, ran, and passed its unit tests (which build a `Two\User`),
     * while being a `method.notFound` the moment anything checked. `AbstractProvider::userFromToken()` is
     * documented `@return \Laravel\Socialite\Two\User`, so the concrete type is what the caller actually
     * has and the narrower hint costs nothing.
     *
     * ⚠️ THIS IS THE UNIT UNDER TEST, AND THAT IS WHY IT IS HERE RATHER THAN INSIDE THE ADAPTER. Keeping
     * the mapping pure leaves {@see SocialiteGoogleIdentityProvider} as a thin call to a third party with
     * nothing worth asserting, instead of a class that can only be exercised by mocking Guzzle.
     *
     * ⚠️ `emailVerified` IS A STRICT `=== true` AND FAILS CLOSED ON EVERYTHING ELSE. The claim arrives
     * inside a raw JSON array, so it may be absent, `null`, the STRING `"true"`, or `1`; a loose check
     * would accept the last two and — worse — `(bool) "false"` is `true`. This flag is J3c2's analogue of
     * SAML's signature check (ADR-0017 §D3): it is the whole basis for believing the address belongs to
     * the person holding the account, so the only acceptable failure direction is refusing a real user.
     *
     * The name falls back to the local part of the address rather than to an empty string: `users.name` is
     * NOT NULL and is rendered in the members roster, and Google can legitimately withhold a profile name.
     */
    public static function fromSocialiteUser(SocialiteUser $user): self
    {
        $raw = $user->getRaw();
        $email = Str::lower((string) $user->getEmail());

        return new self(
            subject: (string) $user->getId(),
            email: $email,
            emailVerified: ($raw['email_verified'] ?? null) === true,
            name: trim((string) $user->getName()) !== ''
                ? trim((string) $user->getName())
                : Str::before($email, '@'),
        );
    }
}
