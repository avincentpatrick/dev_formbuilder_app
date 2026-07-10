<?php

declare(strict_types=1);

namespace App\Support\Guest;

use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use JsonException;

/**
 * Mints and verifies the stateless, HMAC-signed guest share token (Increment F5; data-dictionary §2 —
 * "no `guest_tokens` table … a stateless, HMAC-signed token minted at request time"). The token is NEVER
 * persisted as a row: authenticity and expiry are proven by the signature + embedded `exp` alone, so a
 * link is reusable until it expires and needs no database read to verify.
 *
 * Wire format (three dot-separated, base64url segments — no `/`, so it rides in a `{shareToken}` path
 * segment untouched):
 *
 *   v1.<base64url(json{tid,fid,vid,exp})>.<base64url(HMAC-SHA256(body, key))>
 *
 * where `body` is the `v1.<payload>` prefix. Verification recomputes the MAC over the received body with a
 * constant-time compare (never re-serialises the payload — it validates the exact bytes that were signed),
 * so any tamper flips the signature and 401s before {@see verify()} even decodes, and long before any RLS
 * tenant context is set. The key is derived from `APP_KEY` with domain separation (see AppServiceProvider),
 * so it rotates with the app key and can never be confused with another APP_KEY-derived MAC.
 */
final class GuestShareTokenService
{
    private const VERSION = 'v1';

    public function __construct(
        private readonly string $key,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * Mint a token pinned to one published version. `$now` is injectable so tests can forge an already-expired
     * token deterministically; production callers omit it and get the wall clock.
     */
    public function mint(string $tenantId, string $formId, string $formVersionId, ?int $now = null): MintedShareToken
    {
        $now ??= time();
        $expiresAt = $now + $this->ttlSeconds;

        $payload = ['tid' => $tenantId, 'fid' => $formId, 'vid' => $formVersionId, 'exp' => $expiresAt];

        try {
            $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            // Only reachable on a non-UTF-8 id, which cannot occur for UUIDv7 primary keys.
            throw new InvalidShareTokenException('The share token payload could not be encoded.', 0, $e);
        }

        $body = self::VERSION.'.'.$encodedPayload;
        $token = $body.'.'.self::base64UrlEncode($this->sign($body));

        return new MintedShareToken($token, $expiresAt);
    }

    /**
     * Verify signature THEN expiry, returning the decoded payload. Does no database work and sets no context —
     * the caller (the tenant-from-token middleware) applies the tenant only after this returns cleanly.
     *
     * @throws InvalidShareTokenException malformed structure or a bad/forged signature
     * @throws ExpiredShareTokenException authentic but past its `exp`
     */
    public function verify(string $token, ?int $now = null): GuestShareToken
    {
        $now ??= time();

        $segments = explode('.', $token);
        if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
            throw InvalidShareTokenException::malformed();
        }

        [$version, $encodedPayload, $encodedSignature] = $segments;
        $body = $version.'.'.$encodedPayload;

        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === null || ! hash_equals($this->sign($body), $signature)) {
            throw InvalidShareTokenException::badSignature();
        }

        $json = self::base64UrlDecode($encodedPayload);
        if ($json === null) {
            throw InvalidShareTokenException::malformed();
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidShareTokenException::malformed();
        }

        if (! is_array($payload)) {
            throw InvalidShareTokenException::malformed();
        }

        $tenantId = $payload['tid'] ?? null;
        $formId = $payload['fid'] ?? null;
        $versionId = $payload['vid'] ?? null;
        $expiresAt = $payload['exp'] ?? null;

        if (! self::isUuid($tenantId) || ! self::isUuid($formId) || ! self::isUuid($versionId) || ! is_int($expiresAt)) {
            throw InvalidShareTokenException::malformed();
        }

        if ($expiresAt < $now) {
            throw ExpiredShareTokenException::atExpiry();
        }

        return new GuestShareToken((string) $tenantId, (string) $formId, (string) $versionId, $expiresAt, $token);
    }

    /**
     * A stable, non-reversible correlation handle for the minted token, stored on `submissions.guest_token`
     * (varchar(128)). The raw token exceeds that width; the sha256 fingerprint (64 hex) still ties a
     * submission back to the link it came through without persisting a replayable credential.
     */
    public function fingerprint(string $token): string
    {
        return hash('sha256', $token);
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
