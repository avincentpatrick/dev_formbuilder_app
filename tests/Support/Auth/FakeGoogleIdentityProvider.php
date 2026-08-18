<?php

declare(strict_types=1);

namespace Tests\Support\Auth;

use App\Support\Auth\GoogleAuthException;
use App\Support\Auth\GoogleIdentity;
use App\Support\Auth\GoogleIdentityProvider;
use Tests\Support\FakeDnsTxtResolver;
use Tests\Support\Sso\FakeIdp;

/**
 * The in-memory Google, for every test downstream of the seam (Increment J3c2).
 *
 * RECORDING, not merely stubbing, and the recording is the point — the {@see FakeDnsTxtResolver}
 * discipline. Three things about the outbound leg are invisible from the resulting session and can only be
 * asserted here:
 *
 *   1. THAT OUR STATE, AND ONLY OUR STATE, CROSSED THE HOP. Socialite's `->stateless()` is what stops it
 *      minting a competing `state`; if that call were ever dropped, the flow would still work end to end
 *      in a test that only checked the outcome, while the real Google round trip carried a nonce the
 *      application never verified.
 *   2. THE EXACT REDIRECT URI. Google matches it byte for byte between authorize and exchange, so the two
 *      calls agreeing is a property worth asserting rather than assuming.
 *   3. HOW MANY EXCHANGES HAPPENED. A replayed callback must not reach Google a second time.
 *
 * The knobs take {@see FakeIdp}'s shape: a fake for a security boundary is only worth
 * having if it can produce the REFUSALS, not just the happy path. `unverifiedEmail()` is the important one
 * — it is J3c2's analogue of an invalid signature.
 */
final class FakeGoogleIdentityProvider implements GoogleIdentityProvider
{
    /** @var list<array{state: string, redirectUri: string}> Every authorize URL minted, in order. */
    public array $authorizeCalls = [];

    /** @var list<array{code: string, redirectUri: string}> Every exchange attempted, in order. */
    public array $exchangeCalls = [];

    public bool $refusingExchange = false;

    private string $subject = 'google-sub-0000000000';

    private string $email = 'gmail-person@example.test';

    private bool $emailVerified = true;

    private string $name = 'Greta Google';

    public function as(string $subject, string $email, ?string $name = null): self
    {
        $this->subject = $subject;
        $this->email = $email;
        $this->name = $name ?? $this->name;

        return $this;
    }

    /** The refusal that matters most: Google says it never proved this address belongs to the account. */
    public function unverifiedEmail(): self
    {
        $this->emailVerified = false;

        return $this;
    }

    /** A revoked client secret, a dead network, a reused code — all one outcome to the caller. */
    public function refusingExchange(): self
    {
        $this->refusingExchange = true;

        return $this;
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        $this->authorizeCalls[] = ['state' => $state, 'redirectUri' => $redirectUri];

        return 'https://accounts.google.test/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => 'fake-client-id',
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]);
    }

    public function identityFromCode(string $code, string $redirectUri): GoogleIdentity
    {
        $this->exchangeCalls[] = ['code' => $code, 'redirectUri' => $redirectUri];

        if ($this->refusingExchange) {
            throw GoogleAuthException::exchangeFailed();
        }

        return new GoogleIdentity(
            subject: $this->subject,
            email: $this->email,
            emailVerified: $this->emailVerified,
            name: $this->name,
        );
    }

    /** The state this fake was handed on the most recent authorize call. */
    public function lastState(): ?string
    {
        $last = end($this->authorizeCalls);

        return $last === false ? null : $last['state'];
    }
}
