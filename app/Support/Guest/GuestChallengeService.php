<?php

declare(strict_types=1);

namespace App\Support\Guest;

use App\Enums\FormBotChallenge;
use App\Http\Middleware\VerifyGuestBotChallenge;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use JsonException;
use Random\RandomException;

/**
 * Mints and verifies the guest proof-of-work challenge (Increment I8b) — PRD Feature #3's bot-challenge
 * criterion and docs/security-threat-model.md §4's *"optional, tenant-configurable CAPTCHA/proof-of-work
 * challenge"*.
 *
 * Deliberately shaped after {@see GuestShareTokenService}: base64url wire format, HMAC-SHA256 under a
 * key domain-separated from APP_KEY, signature verified with `hash_equals` BEFORE any untrusted claim is
 * parsed. Two token families in one namespace should look alike, and the reader who has understood one
 * should not have to re-derive the other.
 *
 * ── HOW IT WORKS ──────────────────────────────────────────────────────────────────────────────────────
 * The server picks a random `salt` and a secret `number` in `[0, max]`, publishes `challenge =
 * sha256(salt . number)` and a signature over the parameters, and withholds the number. The client
 * brute-forces `n` upward until `sha256(salt . n) === challenge`, then returns `n`. Verifying is ONE hash
 * plus one HMAC; solving is expected `max/2` hashes. That asymmetry is the entire mechanism: it makes
 * volume expensive for the attacker without making a single submission expensive for us.
 *
 * The wire shape borrows Altcha's field names (`algorithm/challenge/salt/number/signature`) because the
 * shape is right and free to adopt, and it leaves a path to the official widget open. **We do not claim
 * compatibility, do not test against Altcha, and take no dependency on it.**
 *
 * ── ⚠️ WHY THERE IS NO `guest_challenges` TABLE ───────────────────────────────────────────────────────
 * The replay guard is the cache, for two reasons already settled in this codebase: ADR-0007 §D10 chose the
 * cache store for exactly this shape of short-lived, high-churn, single-use marker, and data-dictionary §2
 * governs this whole namespace with *"no `guest_tokens` table … stateless, HMAC-signed"*. A table would
 * also carry a `tenant_id` and trip the migration linter, and both escapes from that are worse.
 *
 * ── ⚠️ THE CLOCK IS LARAVEL'S, NOT `time()` ──────────────────────────────────────────────────────────
 * A deliberate divergence from {@see GuestShareTokenService}, which reads `time()` and makes every expiry
 * test inject a forged `$now`. That works there because a token is minted by a service call the test
 * controls; here the mint happens inside an HTTP request, so a test has no seam to inject through and
 * `travel()` is the only way to reach the expiry branch. `Carbon::now()` respects `travelTo`/`setTestNow`
 * and is identical in production. The `$now` parameter stays for unit tests that prefer explicitness.
 *
 * The honest residual: **a cache flush makes every unspent challenge replayable for one TTL window**
 * (default 5 minutes). That is a bounded, self-healing degradation of an anti-spam control, not a
 * confidentiality or integrity failure — no submission it admits could not have been made by solving a
 * fresh challenge.
 *
 * ── ⚠️ BOUND TO THE FORM, NOT TO THE SHARE TOKEN ──────────────────────────────────────────────────────
 * The obvious binding is the token the challenge was issued against; it is wrong. `replay.ts` re-mints the
 * share token immediately before every outbox POST, and `GuestFormController::resume()` mints a fresh one
 * on a resume-link navigation — so a token binding would reject exactly the offline replays this product
 * exists to support. The form binding still stops the attack that matters: a farm pre-solving cheap
 * challenges on one form to spend against another.
 */
final class GuestChallengeService
{
    private const ALGORITHM = 'SHA-256';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $key,
        private readonly int $ttlSeconds,
        private readonly int $maxNumber,
    ) {}

    /**
     * Issue a challenge bound to one form.
     *
     * @return array{algorithm: string, challenge: string, salt: string, maxnumber: int, signature: string}
     *
     * @throws RandomException on a failure of the CSPRNG, which is not a condition to paper over
     */
    public function mint(string $formId, ?int $now = null): array
    {
        $now ??= Carbon::now()->getTimestamp();
        $expiresAt = $now + $this->ttlSeconds;

        // The salt carries the claims IN THE CLEAR and is signed as part of the challenge parameters —
        // the Altcha shape. Nothing here is secret except `number`; `fid` and `exp` are claims the
        // verifier must be able to read back without state, which is what makes this stateless.
        $salt = bin2hex(random_bytes(12)).'.'.$this->encodeClaims(['fid' => $formId, 'exp' => $expiresAt]);

        $number = random_int(0, $this->maxNumber);
        $challenge = hash('sha256', $salt.$number);

        return [
            'algorithm' => self::ALGORITHM,
            'challenge' => $challenge,
            'salt' => $salt,
            'maxnumber' => $this->maxNumber,
            'signature' => hash_hmac('sha256', $challenge, $this->key),
        ];
    }

    /**
     * Verify a solved challenge for `$formId`, and SPEND it.
     *
     * Returns false for every failure mode rather than throwing per-reason: the caller answers a guest,
     * and telling an attacker which of six checks they failed is free reconnaissance. The distinction the
     * PRODUCT needs — "you sent nothing" vs "what you sent is not good" — is drawn one level up by
     * {@see VerifyGuestBotChallenge}, which knows whether a header was present.
     *
     * ⚠️ THE ORDER OF THESE CHECKS IS THE SECURITY PROPERTY, NOT A STYLE CHOICE. The signature is verified
     * FIRST, against the exact bytes received, before a single untrusted claim is parsed — the same
     * discipline {@see GuestShareTokenService::decodeAndVerify()} keeps. Reversed, a forged salt would be
     * json_decoded and its `exp` compared before anything had established that we wrote it.
     *
     * ⚠️ THE REPLAY GUARD IS LAST, AND MUST BE. `Cache::add()` is the atomic claim, so it has to run after
     * every cheap rejection: a forged solution that burned the cache key would let an attacker DoS a
     * legitimate respondent's in-flight challenge by racing it with garbage.
     *
     * @param  array<string, mixed>  $solution  the decoded client payload
     */
    public function verify(array $solution, string $formId, ?int $now = null): bool
    {
        $now ??= Carbon::now()->getTimestamp();

        $challenge = $solution['challenge'] ?? null;
        $salt = $solution['salt'] ?? null;
        $number = $solution['number'] ?? null;
        $signature = $solution['signature'] ?? null;

        if (! is_string($challenge) || ! is_string($salt) || ! is_string($signature) || ! is_int($number)) {
            return false;
        }

        if (($solution['algorithm'] ?? self::ALGORITHM) !== self::ALGORITHM) {
            return false;
        }

        // 1. Did we issue this challenge at all?
        if (! hash_equals(hash_hmac('sha256', $challenge, $this->key), $signature)) {
            return false;
        }

        // 2. Only now are the claims ours to trust enough to parse.
        $claims = $this->decodeClaims($salt);
        if ($claims === null) {
            return false;
        }

        if (($claims['fid'] ?? null) !== $formId) {
            return false;
        }

        $expiresAt = $claims['exp'] ?? null;
        if (! is_int($expiresAt) || $expiresAt < $now) {
            return false;
        }

        // 3. Did they actually do the work? `hash_equals` rather than `===` for uniformity with the
        //    signature compare above — the value is not secret, but two hash comparisons in one method
        //    that differ in kind invite the wrong one to be copied.
        if (! hash_equals(hash('sha256', $salt.$number), $challenge)) {
            return false;
        }

        // 4. Spend it. `add()` is atomic set-if-absent, so two concurrent replays cannot both win.
        //    The remaining lifetime is the TTL: a spent challenge only needs to be remembered for as long
        //    as it would otherwise still verify.
        return $this->cache->add(
            'guest-challenge:'.$challenge,
            true,
            max(1, $expiresAt - $now),
        );
    }

    /** Whether a form's configured mechanism requires a solved challenge on submit. */
    public function required(FormBotChallenge $mechanism): bool
    {
        return match ($mechanism) {
            FormBotChallenge::Off => false,
            FormBotChallenge::ProofOfWork => true,
        };
    }

    /** @param array<string, mixed> $claims */
    private function encodeClaims(array $claims): string
    {
        try {
            return self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            // Unreachable: both claims are a uuid and an int. Fail closed rather than mint an unverifiable
            // challenge that would refuse every legitimate respondent.
            return '';
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeClaims(string $salt): ?array
    {
        $parts = explode('.', $salt);
        if (count($parts) !== 2) {
            return null;
        }

        $json = self::base64UrlDecode($parts[1]);
        if ($json === null) {
            return null;
        }

        try {
            $claims = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($claims) ? $claims : null;
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
}
