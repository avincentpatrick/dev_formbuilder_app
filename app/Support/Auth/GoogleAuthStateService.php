<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Guest\GuestShareTokenService;
use JsonException;

/**
 * Mints and verifies the Google sign-in `state` (Increment J3c2, ADR-0009 §D3 as amended, ADR-0017 §D6).
 *
 * WHY A TOKEN AND NOT A SESSION, unchanged from ADR-0009: the session cookie is host-only
 * (`SESSION_DOMAIN` is null), so a session established at `acme.example.com` is not sent to the central
 * host Google redirects to. This reuses the wire format the platform already proved twice
 * ({@see GuestShareTokenService}, {@see ConnectorOAuthStateService}) — three dot-separated base64url
 * segments with no `/`, so it survives a query-string round trip through a provider untouched:
 *
 *   v1.<base64url(json{tid,sid,exp})>.<base64url(HMAC-SHA256(body, key))>
 *
 * Verification recomputes the MAC over the received body with a constant-time compare and decodes only
 * afterwards, so a tamper is refused before the payload is parsed and long before any tenant GUC exists.
 *
 * ── THREE DIFFERENCES FROM THE CONNECTOR STATE, EACH DELIBERATE ───────────────────────────────────────
 * 1. **`tid` MAY BE NULL.** A sign-in begun on the central host belongs to no workspace (ADR-0017 §D12).
 *    The connector flow has no such case. `null` is a legitimate verified value here, not a failure.
 * 2. **THERE IS NO `uid`.** A connector state names the user who started the flow, because the connection
 *    is attributed to them. Here the user is the OUTCOME — nobody is authenticated at mint time — so a
 *    `uid` claim would be a value no honest caller could fill.
 * 3. **THERE IS NO `prov`.** That claim exists to stop a state minted for one provider being replayed at
 *    another provider's callback. This flow has exactly one provider and one callback, and a second one
 *    would need its own route anyway. ⚠️ The DERIVED KEY is what keeps a connector state from being
 *    replayed here and vice versa: a different domain separator means a connector token simply fails the
 *    MAC check, which is a stronger guarantee than a claim comparison and needs no code to enforce.
 *
 * ⚠️ `sid` IS NOT DECORATION AND IS NOT THE `nonce` THE CONNECTOR STATE CARRIES. It is the primary lookup
 * for the `google_auth_requests` row that makes this flow single-use: the signed token proves WE minted
 * it, and the row proves it has not already been spent. ADR-0009 §D3's stateless narrowing is bounded
 * here by that row rather than only by Google's single-use code — see the migration's docblock.
 */
final class GoogleAuthStateService
{
    private const VERSION = 'v1';

    public function __construct(
        private readonly string $key,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * Bind this flow to one workspace (or to none) and to one `google_auth_requests` row.
     *
     * `$now` is injectable so a test can forge an already-expired token deterministically; production
     * callers omit it.
     */
    public function mint(?string $tenantId, string $stateId, ?int $now = null): string
    {
        $now ??= time();

        try {
            $encodedPayload = self::base64UrlEncode(json_encode([
                'tid' => $tenantId,
                'sid' => $stateId,
                'exp' => $now + $this->ttlSeconds,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            // Unreachable for a UUID and 32 hex characters; refused rather than returned malformed.
            throw InvalidGoogleAuthStateException::rejected();
        }

        $body = self::VERSION.'.'.$encodedPayload;

        return $body.'.'.self::base64UrlEncode($this->sign($body));
    }

    /**
     * Verify: structure, signature, claim shape, then expiry — in that order, so nothing about the payload
     * is trusted before the MAC proves we wrote it. Does no database work and sets no context.
     *
     * Every failure collapses to one exception on purpose. This is an unauthenticated inbound surface, and
     * telling a caller which half of the token to keep working on is a gift (ADR-0017 §D9).
     *
     * @throws InvalidGoogleAuthStateException
     */
    public function verify(string $state, ?int $now = null): GoogleAuthState
    {
        $now ??= time();

        $segments = explode('.', $state);
        if (count($segments) !== 3 || $segments[0] !== self::VERSION) {
            throw InvalidGoogleAuthStateException::rejected();
        }

        [$version, $encodedPayload, $encodedSignature] = $segments;
        $body = $version.'.'.$encodedPayload;

        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === null || ! hash_equals($this->sign($body), $signature)) {
            throw InvalidGoogleAuthStateException::rejected();
        }

        $json = self::base64UrlDecode($encodedPayload);
        if ($json === null) {
            throw InvalidGoogleAuthStateException::rejected();
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidGoogleAuthStateException::rejected();
        }

        if (! is_array($payload)) {
            throw InvalidGoogleAuthStateException::rejected();
        }

        $tenantId = $payload['tid'] ?? null;
        $stateId = $payload['sid'] ?? null;
        $expiresAt = $payload['exp'] ?? null;

        // ⚠️ `tid` is valid as NULL or as a uuid, and nothing else. A non-uuid string must be refused
        // rather than coerced: it would otherwise reach a `where('tenant_id', …)` as attacker-chosen text.
        if (! ($tenantId === null || self::isUuid($tenantId))
            || ! is_string($stateId)
            || preg_match('/^[0-9a-f]{32}$/', $stateId) !== 1
            || ! is_int($expiresAt)
            || $expiresAt < $now) {
            throw InvalidGoogleAuthStateException::rejected();
        }

        return new GoogleAuthState(
            $tenantId === null ? null : (string) $tenantId,
            $stateId,
            $expiresAt,
        );
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->key, true);
    }

    private static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
