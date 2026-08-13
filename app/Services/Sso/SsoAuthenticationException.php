<?php

declare(strict_types=1);

namespace App\Services\Sso;

use RuntimeException;
use Throwable;

/**
 * Why an SSO sign-in was refused (Phase 4, P1b — ADR-0016 §D4).
 *
 * ── ⚠️ THE MESSAGE NEVER REACHES THE CALLER, AND THAT IS THE DESIGN ─────────────────────────────────
 * The ACS answers every failure with the same 404 — unknown request, expired assertion, bad signature,
 * wrong audience, suspended member, exhausted seat quota. An endpoint that distinguished them would be an
 * oracle for anyone tuning a forged assertion: "wrong audience" tells an attacker the signature verified,
 * "already consumed" tells them the id was real. So the detail lives HERE, is written to the log by
 * `SsoAcsController`, and dies there.
 *
 * {@see $reason} is a stable machine token rather than the prose, because the prose is for a human reading
 * a log line and the token is what an operator greps for and what a future failures panel would group by.
 *
 * ── ⚠️ WHY THESE ARE NOT AUDIT ROWS ─────────────────────────────────────────────────────────────────
 * `audits` is append-only by RLS policy and is never pruned. An UNAUTHENTICATED endpoint that writes to it
 * on every rejection is an amplification primitive: one script, one afternoon, a compliance ledger nobody
 * can read and nobody can clean. The failures are logged instead. The cost is stated rather than hidden —
 * a tenant admin has no in-app view of why a member's sign-in failed, and building one is a follow-up.
 */
final class SsoAuthenticationException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport and parsing — refused before the XML parser allocates anything
    |--------------------------------------------------------------------------
    */

    public static function missingResponse(): self
    {
        return new self('response_missing', 'The request carried no SAMLResponse field.');
    }

    public static function responseTooLarge(int $bytes): self
    {
        return new self('response_too_large', "The SAMLResponse field is {$bytes} bytes, beyond saml.max_response_bytes.");
    }

    public static function malformedResponse(string $detail, ?Throwable $previous = null): self
    {
        return new self('response_malformed', "The SAMLResponse could not be read: {$detail}", $previous);
    }

    /*
    |--------------------------------------------------------------------------
    | Binding — the §D9 refusal of anything this SP did not ask for
    |--------------------------------------------------------------------------
    */

    /**
     * No `InResponseTo` at all: an unsolicited, IdP-initiated assertion.
     *
     * Refused permanently and structurally — there is nothing to bind it to a request this SP minted, and
     * accepting one is a login-CSRF primitive. This arm is what makes `allow_unsolicited => false` real
     * rather than merely configured.
     */
    public static function unsolicited(): self
    {
        return new self('unsolicited_assertion', 'The assertion carries no InResponseTo; IdP-initiated SSO is refused.');
    }

    /** Never minted, minted by another tenant, already consumed, or expired — four things, one answer. */
    public static function unknownRequest(string $inResponseTo): self
    {
        return new self('unknown_request', "No live authentication request matches InResponseTo {$inResponseTo}.");
    }

    /** The atomic consume lost its race: another request burned this row between the read and the write. */
    public static function requestReplayed(string $requestId): self
    {
        return new self('request_replayed', "Authentication request {$requestId} was consumed concurrently.");
    }

    /** The second, independent ledger: this assertion id has been presented before (§D8). */
    public static function assertionReplayed(string $assertionId): self
    {
        return new self('assertion_replayed', "Assertion {$assertionId} has already been consumed.");
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /** php-saml refused it — signature, audience, destination, issuer, subject confirmation or schema. */
    public static function invalidAssertion(string $detail, ?Throwable $previous = null): self
    {
        return new self('invalid_assertion', "The assertion failed validation: {$detail}", $previous);
    }

    /**
     * Outside `Conditions`, measured against `config('saml.clock_skew_seconds')`.
     *
     * Separate from {@see invalidAssertion()} because it is the arm php-saml would have ACCEPTED: its
     * `Constants::ALLOWED_CLOCK_DRIFT` is a hard-coded 180 seconds and cannot be configured. An operator
     * reading a log full of this token is being told their IdP's clock is wrong, which is actionable —
     * where "invalid assertion" would send them hunting for a certificate problem.
     */
    public static function outsideConditions(string $detail): self
    {
        return new self('assertion_outside_conditions', "The assertion is outside its Conditions window: {$detail}");
    }

    /*
    |--------------------------------------------------------------------------
    | Identity and provisioning
    |--------------------------------------------------------------------------
    */

    public static function noEmail(): self
    {
        return new self('no_email', 'The assertion carries no usable email address for this connection.');
    }

    /** A subject this workspace has never seen, on a connection whose admin turned JIT off. */
    public static function provisioningDisabled(string $email): self
    {
        return new self('jit_disabled', "No member matches {$email} and just-in-time provisioning is disabled.");
    }

    /**
     * An explicit administrative sanction, and SSO must not launder it.
     *
     * `Declined` and `Removed` mean "not currently a member", which is exactly what JIT is for, so those
     * are reactivated. `Suspended` is somebody deciding this person should not get in — a sign-in that
     * silently reversed it would make the sanction unenforceable in the one workspace type most likely to
     * rely on it.
     */
    public static function membershipSuspended(string $email): self
    {
        return new self('membership_suspended', "The membership for {$email} is suspended.");
    }

    /**
     * Refused rather than admitted seatless.
     *
     * `TenantMembershipService::joinOpenTenant()` returns null on a full workspace and lets the registrant
     * keep an account with no membership, which is a state that product already has. Here it is not: a
     * session established with no membership sees an empty workspace through RLS and reads as data loss.
     */
    public static function seatQuotaExhausted(string $email): self
    {
        return new self('seat_quota_exhausted', "The workspace has no seat available for {$email}.");
    }
}
