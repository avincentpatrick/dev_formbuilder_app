<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Exceptions\Connectors\InvalidConnectorStateException;
use App\Support\Guest\GuestShareTokenService;
use Illuminate\Support\Str;
use JsonException;

/**
 * Mints and verifies the OAuth `state` parameter (ADR-0009 §D3, H15a) — the only thing that carries tenant
 * and user identity across the host boundary from a tenant subdomain to the central-domain callback.
 *
 * WHY A TOKEN AND NOT A SESSION: the app's session cookie is host-only (`config/session.php` reads
 * `SESSION_DOMAIN`, which is null), so a session established at `acme.example.com` is simply not sent to the
 * central host where the provider redirects. Widening `SESSION_DOMAIN` would make every tenant's session
 * cookie a cross-host artifact on every request to solve a problem that arises twice per connection. This is
 * instead the mechanism the platform already proved for exactly this shape — a signed, domain-separated
 * token verified before any RLS context is set ({@see GuestShareTokenService}) — reused
 * as the `state` value, which is also the role OAuth itself assigns to `state` (CSRF).
 *
 * Wire format, identical to the guest tokens (three dot-separated base64url segments, no `/`, so it survives
 * a query-string round trip through a provider untouched):
 *
 *   v1.<base64url(json{tid,uid,prov,nonce,exp})>.<base64url(HMAC-SHA256(body, key))>
 *
 * Verification recomputes the MAC over the received body with a constant-time compare and decodes only
 * afterwards, so a tamper is refused before the payload is even parsed — and long before a tenant GUC exists.
 *
 * `prov` is load-bearing, not decoration: without it a state minted for one provider's flow could be replayed
 * against another provider's callback. The caller passes the provider the callback route is bound to, and a
 * mismatch is rejected the same way a bad signature is.
 *
 * ACCEPTED NARROWING (ADR-0009 §D3): the token is stateless, so it is replayable until it expires. That is
 * bounded by the provider's authorization code being single-use (a replayed callback fails at the exchange)
 * and by the fact that a successful replay could only rewrite the tenant's own connection. A server-side
 * single-use nonce store is the named fix if a provider's codes ever stop being single-use; `nonce` is here
 * so two flows started in the same second are still distinct tokens.
 */
final class ConnectorOAuthStateService
{
    private const VERSION = 'v1';

    public function __construct(
        private readonly string $key,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * Mint a state token binding this flow to one tenant, one user and one provider. `$now` is injectable so
     * tests can forge an already-expired token deterministically; production callers omit it.
     */
    public function mint(string $tenantId, string $userId, ConnectorProviderKey $provider, ?int $now = null): string
    {
        $now ??= time();

        $payload = [
            'tid' => $tenantId,
            'uid' => $userId,
            'prov' => $provider->value,
            'nonce' => (string) Str::uuid(),
            'exp' => $now + $this->ttlSeconds,
        ];

        try {
            $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            // Only reachable on non-UTF-8 ids, which cannot occur for UUIDv7 primary keys.
            throw InvalidConnectorStateException::rejected();
        }

        $body = self::VERSION.'.'.$encodedPayload;

        return $body.'.'.self::base64UrlEncode($this->sign($body));
    }

    /**
     * Verify a state token: structure, signature, claim shape, provider binding, then expiry — in that order,
     * so nothing about the payload is trusted before the MAC proves we wrote it. Does no database work and
     * sets no context; the middleware applies the tenant only after this returns cleanly.
     *
     * All four failure causes collapse to one exception on purpose: this is an unauthenticated inbound
     * surface, and telling a caller which half of the token to keep working on is a gift.
     *
     * @throws InvalidConnectorStateException
     */
    public function verify(string $state, ConnectorProviderKey $expectedProvider, ?int $now = null): ConnectorOAuthState
    {
        $now ??= time();

        $segments = explode('.', $state);
        if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
            throw InvalidConnectorStateException::rejected();
        }

        [$version, $encodedPayload, $encodedSignature] = $segments;
        $body = $version.'.'.$encodedPayload;

        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === null || ! hash_equals($this->sign($body), $signature)) {
            throw InvalidConnectorStateException::rejected();
        }

        $json = self::base64UrlDecode($encodedPayload);
        if ($json === null) {
            throw InvalidConnectorStateException::rejected();
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidConnectorStateException::rejected();
        }

        if (! is_array($payload)) {
            throw InvalidConnectorStateException::rejected();
        }

        $expiresAt = $payload['exp'] ?? null;

        // Compared as the raw claim STRING against the expected case's value, not as two enum instances:
        // with a single-case enum an instance-to-instance comparison is statically always-false, and the
        // check must keep working the moment a second provider exists.
        if (! self::isUuid($payload['tid'] ?? null)
            || ! self::isUuid($payload['uid'] ?? null)
            || ! is_string($payload['prov'] ?? null)
            || $payload['prov'] !== $expectedProvider->value
            || ! is_int($expiresAt)
            || $expiresAt < $now) {
            throw InvalidConnectorStateException::rejected();
        }

        return new ConnectorOAuthState(
            (string) $payload['tid'],
            (string) $payload['uid'],
            $expectedProvider,
            $expiresAt,
        );
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->key, true);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), strict: true);

        return $decoded === false ? null : $decoded;
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
