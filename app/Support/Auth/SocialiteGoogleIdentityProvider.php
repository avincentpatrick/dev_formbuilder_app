<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

/**
 * The real {@see GoogleIdentityProvider}, on `laravel/socialite` (Increment J3c2).
 *
 * ⚠️ ADR-0009 REJECTS SOCIALITE BY NAME AND THIS CLASS EXISTS UNDER AN EXPLICIT CARVE-OUT, not in spite of
 * it. That rejection is about the CONNECTOR lane, which holds a durable third-party credential so the
 * platform can act as the tenant inside the tenant's own workspace; this flow reads an identity once and
 * discards the token. See ADR-0009's Alternatives Considered, where the carve-out answers the rejection's
 * three reasons individually, and ADR-0017 §D10. `ConnectorProvider` does not adopt Socialite and must not.
 *
 * ⚠️ `->stateless()` IS LOAD-BEARING, AND IT IS WHAT ANSWERS THE ORIGINAL OBJECTION. It tells Socialite to
 * neither mint nor verify a `state` of its own, which is exactly right here: the callback lands on the
 * CENTRAL host while the flow began on a tenant host, so a session-backed nonce has no session to live in
 * (`SESSION_DOMAIN` is null, so the cookie is host-only). The application's own signed state is passed
 * through `->with()` and remains the sole trust anchor across the hop. Without `stateless()`, Socialite
 * would emit a second `state` parameter and overwrite ours.
 *
 * ⚠️ THE REDIRECT URI IS PASSED IN RATHER THAN READ FROM CONFIG HERE, even though config holds a default:
 * Google matches the `redirect_uri` on the token exchange against the one used at the authorize step
 * BYTE FOR BYTE, so the two calls must be handed the same string by the same caller. Deriving it
 * independently in two places is how that becomes a `redirect_uri_mismatch` that only appears in
 * production, where the host differs from the developer's.
 */
final class SocialiteGoogleIdentityProvider implements GoogleIdentityProvider
{
    /**
     * `openid` is what makes this an identity request rather than an API grant; `email` and `profile`
     * carry the two claims this product stores. Nothing else is requested — a sign-in that asked for
     * Drive scopes would be a connector, and would fall under ADR-0009's custody rules in full.
     *
     * @var list<string>
     */
    private const SCOPES = ['openid', 'email', 'profile'];

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->driver($redirectUri)
            ->scopes(self::SCOPES)
            // `select_account` rather than the default: a shared machine whose browser is already signed
            // in to one Google account would otherwise sign the person straight back in as that account
            // with no visible choice, which reads as "the app logged me in as my colleague".
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect()
            ->getTargetUrl();
    }

    public function identityFromCode(string $code, string $redirectUri): GoogleIdentity
    {
        try {
            $token = $this->driver($redirectUri)->getAccessTokenResponse($code);
            $user = $this->driver($redirectUri)->userFromToken((string) ($token['access_token'] ?? ''));
        } catch (Throwable $e) {
            // Deliberately swallowed into one type. See the interface: an anonymous caller can provoke
            // this at will, so the distinction between "Google refused" and "the network failed" belongs
            // in the log, not in anything the caller can observe.
            throw GoogleAuthException::exchangeFailed($e);
        }

        $identity = GoogleIdentity::fromSocialiteUser($user);

        // A sign-in with no address is unusable downstream — `users.email` is NOT NULL and unique, and it
        // is the only join this flow has to an existing account. Refused here rather than allowed to
        // surface as a database error three layers later.
        if ($identity->email === '' || $identity->subject === '') {
            throw GoogleAuthException::identityUnusable('no subject or email claim');
        }

        return $identity;
    }

    private function driver(string $redirectUri): GoogleProvider
    {
        /** @var GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->stateless()->redirectUrl($redirectUri);
    }
}
