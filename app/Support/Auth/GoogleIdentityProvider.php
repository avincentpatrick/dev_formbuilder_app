<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\Connectors\ConnectorProvider;
use App\Support\Tenancy\DnsTxtResolver;

/**
 * Google's half of first-party sign-in, behind a contract (Increment J3c2, ADR-0019 §D10).
 *
 * An interface for the same reason {@see DnsTxtResolver} is one: the real implementation talks to a third
 * party over the network, so nothing downstream of it could be tested without either live credentials or
 * a Guzzle mock — and live Google credentials are an input only the product owner can supply. Everything
 * from the redirect through provisioning, membership, the two-factor fork and the handoff is exercised
 * against `Tests\Support\Auth\FakeGoogleIdentityProvider`, so the build never waited on them.
 *
 * ⚠️ TWO METHODS, ONE QUESTION EACH, AND DELIBERATELY NOT A GENERIC OAUTH CLIENT.
 * {@see ConnectorProvider} is the generic one and answers four questions because a
 * connector holds a durable token it must refresh and revoke. This flow reads an identity once and throws
 * the token away, so a `refresh()` here would be a method nobody could implement honestly. ADR-0009's
 * Socialite carve-out turns on exactly that difference.
 *
 * ⚠️ NEITHER METHOD READS THE AMBIENT REQUEST. The redirect URI is passed in and the code is passed in,
 * because the central callback is a different host from the tenant page that started the flow — an
 * implementation that reached for `request()` would work in a unit test and be wrong in production.
 */
interface GoogleIdentityProvider
{
    /**
     * Where to send the browser to obtain consent.
     *
     * `$state` is ADR-0009 §D3's signed token and is the flow's ONLY trust anchor across the hop: the
     * implementation must pass it through untouched and must not substitute a state of its own.
     */
    public function authorizeUrl(string $state, string $redirectUri): string;

    /**
     * Exchange an authorization code for the identity behind it.
     *
     * ⚠️ EVERY FAILURE IS {@see GoogleAuthException}, INCLUDING TRANSPORT ONES. A caller on an
     * unauthenticated endpoint must not have to distinguish "Google said no" from "Guzzle threw", because
     * both produce the same user-facing outcome (ADR-0019 §D9's single indistinguishable bounce) and
     * letting a raw client exception escape would put a provider's message on the way to a log line that
     * an anonymous caller can trigger at will.
     *
     * @throws GoogleAuthException
     */
    public function identityFromCode(string $code, string $redirectUri): GoogleIdentity;
}
