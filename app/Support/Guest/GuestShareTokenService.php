<?php

declare(strict_types=1);

namespace App\Support\Guest;

use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use JsonException;

/**
 * Mints and verifies the stateless, HMAC-signed guest tokens (Increment F5; data-dictionary §2 —
 * "no `guest_tokens` table … a stateless, HMAC-signed token minted at request time"). A token is NEVER
 * persisted as a row: authenticity and expiry are proven by the signature + embedded `exp` alone, so a
 * link is reusable until it expires and needs no database read to verify.
 *
 * Two token kinds share the wire format but NOT the signing key:
 *   - the SHARE token (F5) — pinned to `(tenant, form, published version)`, ~24h, {@see mint()}/{@see verify()};
 *   - the RESUME token (H9b) — the durable variant additionally scoped to one draft `submissions.id`, ~30d,
 *     {@see mintResume()}/{@see verifyResume()}, carrying an extra `sid` claim + a `typ: 'resume'` discriminator.
 *
 * The two are non-interchangeable at BOTH layers: each is signed with its own domain-separated key (see
 * AppServiceProvider), so a resume token fails the share signature check and vice-versa; and the claim layer
 * additionally requires `typ`/`sid` to match. Neither can ever be replayed as the other.
 *
 * Wire format (three dot-separated, base64url segments — no `/`, so it rides in a `{shareToken}` path
 * segment untouched):
 *
 *   v1.<base64url(json{tid,fid,vid[,sid,typ],exp})>.<base64url(HMAC-SHA256(body, key))>
 *
 * where `body` is the `v1.<payload>` prefix. Verification recomputes the MAC over the received body with a
 * constant-time compare (never re-serialises the payload — it validates the exact bytes that were signed),
 * so any tamper flips the signature and 401s before the payload is even decoded, and long before any RLS
 * tenant context is set.
 */
final class GuestShareTokenService
{
    private const VERSION = 'v1';

    private const TYPE_RESUME = 'resume';

    public function __construct(
        private readonly string $key,
        private readonly int $ttlSeconds,
        private readonly string $resumeKey,
        private readonly int $resumeTtlSeconds,
    ) {}

    /**
     * Mint a SHARE token pinned to one published version. `$now` is injectable so tests can forge an
     * already-expired token deterministically; production callers omit it and get the wall clock.
     */
    public function mint(string $tenantId, string $formId, string $formVersionId, ?int $now = null): MintedShareToken
    {
        $now ??= time();
        $expiresAt = $now + $this->ttlSeconds;

        return $this->encode(
            ['tid' => $tenantId, 'fid' => $formId, 'vid' => $formVersionId, 'exp' => $expiresAt],
            $this->key,
            $expiresAt,
        );
    }

    /**
     * Mint a RESUME token (H9b) additionally scoped to one draft `submissions.id`, valid for the (longer)
     * resume TTL. Carries a `sid` claim + a `typ: 'resume'` discriminator and is signed with the separate
     * resume key, so it can never be confused with a share token.
     */
    public function mintResume(
        string $tenantId,
        string $formId,
        string $formVersionId,
        string $submissionId,
        ?int $now = null,
    ): MintedShareToken {
        $now ??= time();
        $expiresAt = $now + $this->resumeTtlSeconds;

        return $this->encode(
            [
                'tid' => $tenantId,
                'fid' => $formId,
                'vid' => $formVersionId,
                'sid' => $submissionId,
                'typ' => self::TYPE_RESUME,
                'exp' => $expiresAt,
            ],
            $this->resumeKey,
            $expiresAt,
        );
    }

    /**
     * Verify a SHARE token: signature THEN expiry, returning the decoded payload. Does no database work and
     * sets no context — the caller (the tenant-from-token middleware) applies the tenant only after this
     * returns cleanly. A resume-typed payload is rejected (belt-and-suspenders on top of the key separation,
     * which already fails the signature check).
     *
     * @throws InvalidShareTokenException malformed structure or a bad/forged signature
     * @throws ExpiredShareTokenException authentic but past its `exp`
     */
    public function verify(string $token, ?int $now = null): GuestShareToken
    {
        $payload = $this->decodeAndVerify($token, $this->key, $now);

        if (($payload['typ'] ?? null) === self::TYPE_RESUME) {
            throw InvalidShareTokenException::malformed();
        }

        return new GuestShareToken(
            (string) $payload['tid'],
            (string) $payload['fid'],
            (string) $payload['vid'],
            (int) $payload['exp'],
            $token,
        );
    }

    /**
     * Verify a RESUME token (H9b): the same signature+expiry checks under the resume key, plus a claim-layer
     * requirement that `typ === 'resume'` and `sid` is a well-formed submission id. Returns the payload with
     * `submissionId` populated.
     *
     * @throws InvalidShareTokenException malformed structure, a bad/forged signature, or a share token
     * @throws ExpiredShareTokenException authentic but past its `exp`
     */
    public function verifyResume(string $token, ?int $now = null): GuestShareToken
    {
        $payload = $this->decodeAndVerify($token, $this->resumeKey, $now);

        if (($payload['typ'] ?? null) !== self::TYPE_RESUME || ! self::isUuid($payload['sid'] ?? null)) {
            throw InvalidShareTokenException::malformed();
        }

        return new GuestShareToken(
            (string) $payload['tid'],
            (string) $payload['fid'],
            (string) $payload['vid'],
            (int) $payload['exp'],
            $token,
            (string) $payload['sid'],
        );
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

    /**
     * Serialise + sign a payload into the wire format under the given key.
     *
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload, string $key, int $expiresAt): MintedShareToken
    {
        try {
            $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            // Only reachable on a non-UTF-8 id, which cannot occur for UUIDv7 primary keys.
            throw new InvalidShareTokenException('The token payload could not be encoded.', 0, $e);
        }

        $body = self::VERSION.'.'.$encodedPayload;
        $token = $body.'.'.self::base64UrlEncode($this->sign($key, $body));

        return new MintedShareToken($token, $expiresAt);
    }

    /**
     * Structure + signature (under `$key`) + common-claim + expiry validation, returning the decoded payload
     * array. The per-kind claim checks (`typ`/`sid`) are applied by the caller.
     *
     * @return array<string, mixed>
     */
    private function decodeAndVerify(string $token, string $key, ?int $now): array
    {
        $now ??= time();

        $segments = explode('.', $token);
        if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
            throw InvalidShareTokenException::malformed();
        }

        [$version, $encodedPayload, $encodedSignature] = $segments;
        $body = $version.'.'.$encodedPayload;

        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === null || ! hash_equals($this->sign($key, $body), $signature)) {
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

        $expiresAt = $payload['exp'] ?? null;
        if (! self::isUuid($payload['tid'] ?? null)
            || ! self::isUuid($payload['fid'] ?? null)
            || ! self::isUuid($payload['vid'] ?? null)
            || ! is_int($expiresAt)) {
            throw InvalidShareTokenException::malformed();
        }

        if ($expiresAt < $now) {
            throw ExpiredShareTokenException::atExpiry();
        }

        return $payload;
    }

    private function sign(string $key, string $body): string
    {
        return hash_hmac('sha256', $body, $key, true);
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
