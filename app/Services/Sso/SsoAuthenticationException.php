<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\SsoFailureReason;
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
 * can read and nobody can clean.
 *
 * ── P1c BUILT THE OTHER STORE, AND THE DISTINCTION IS THE WHOLE REASON IT COULD ─────────────────────
 * The follow-up this docblock used to name is `sso_auth_failures`, written by {@see SsoAuthFailureRecorder}
 * and read by the settings screen. It is not `audits` under another name: it is trimmed on every write to a
 * bounded row count and a retention window, so an anonymous caller can fill it and gain nothing. The log
 * line stays exactly where it was — the operator's surface and the tenant's are different surfaces.
 */
final class SsoAuthenticationException extends RuntimeException
{
    /**
     * ⚠️ `$subject` IS SET STRUCTURALLY, NEVER PARSED BACK OUT OF `$message` (P1c). Three factories below
     * interpolate an email into their prose, and the failures panel needs that address as a field — reading
     * it back with a regular expression would make the wording of a log line into a wire format.
     *
     * ⚠️ AND IT IS NULL FOR EVERY PRE-VALIDATION REFUSAL, WHICH IS THE POINT. An address only exists here
     * once a signature over the assertion carrying it has verified. Recording one from a document that
     * failed validation would put attacker-chosen text on an admin's screen and in a tenant's database, on
     * an endpoint anyone on the internet can post to.
     */
    private function __construct(
        public readonly SsoFailureReason $reason,
        string $message,
        ?Throwable $previous = null,
        public readonly ?string $subject = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /*
    |--------------------------------------------------------------------------
    | Transport and parsing — refused before the XML parser allocates anything
    |--------------------------------------------------------------------------
    */

    public static function missingResponse(): self
    {
        return new self(SsoFailureReason::ResponseMissing, 'The request carried no SAMLResponse field.');
    }

    public static function responseTooLarge(int $bytes): self
    {
        return new self(SsoFailureReason::ResponseTooLarge, "The SAMLResponse field is {$bytes} bytes, beyond saml.max_response_bytes.");
    }

    public static function malformedResponse(string $detail, ?Throwable $previous = null): self
    {
        return new self(SsoFailureReason::ResponseMalformed, "The SAMLResponse could not be read: {$detail}", $previous);
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
        return new self(SsoFailureReason::UnsolicitedAssertion, 'The assertion carries no InResponseTo; IdP-initiated SSO is refused.');
    }

    /** Never minted, minted by another tenant, already consumed, or expired — four things, one answer. */
    public static function unknownRequest(string $inResponseTo): self
    {
        return new self(SsoFailureReason::UnknownRequest, "No live authentication request matches InResponseTo {$inResponseTo}.");
    }

    /** The atomic consume lost its race: another request burned this row between the read and the write. */
    public static function requestReplayed(string $requestId): self
    {
        return new self(SsoFailureReason::RequestReplayed, "Authentication request {$requestId} was consumed concurrently.");
    }

    /** The second, independent ledger: this assertion id has been presented before (§D8). */
    public static function assertionReplayed(string $assertionId): self
    {
        return new self(SsoFailureReason::AssertionReplayed, "Assertion {$assertionId} has already been consumed.");
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /** php-saml refused it — signature, audience, destination, issuer, subject confirmation or schema. */
    public static function invalidAssertion(string $detail, ?Throwable $previous = null): self
    {
        return new self(SsoFailureReason::InvalidAssertion, "The assertion failed validation: {$detail}", $previous);
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
        return new self(SsoFailureReason::AssertionOutsideConditions, "The assertion is outside its Conditions window: {$detail}");
    }

    /*
    |--------------------------------------------------------------------------
    | Identity and provisioning
    |--------------------------------------------------------------------------
    */

    public static function noEmail(): self
    {
        return new self(SsoFailureReason::NoEmail, 'The assertion carries no usable email address for this connection.');
    }

    /** A subject this workspace has never seen, on a connection whose admin turned JIT off. */
    public static function provisioningDisabled(string $email): self
    {
        return new self(SsoFailureReason::JitDisabled, "No member matches {$email} and just-in-time provisioning is disabled.", subject: $email);
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
        return new self(SsoFailureReason::MembershipSuspended, "The membership for {$email} is suspended.", subject: $email);
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
        return new self(SsoFailureReason::SeatQuotaExhausted, "The workspace has no seat available for {$email}.", subject: $email);
    }

    /*
    |--------------------------------------------------------------------------
    | Step-up (P1c) — the three conditions, refused one at a time
    |--------------------------------------------------------------------------
    |
    | `SsoAuthIntent`'s docblock states why the stamp requires intent AND subject AND ForceAuthn together.
    | These are the arms that fire when one of them does not hold at the ACS. The third condition — a
    | `user_id` matching the CURRENTLY AUTHENTICATED user — cannot be evaluated here at all (the ACS has no
    | session; see the P1c migration), so its refusal lives on the completion hop instead.
    |
    */

    /**
     * A step-up row whose request did not carry `ForceAuthn`.
     *
     * Unreachable through `SsoStepUpController`, which always sets it, and checked anyway: without it the
     * IdP was free to answer from an existing session, so the assertion proves the person had signed in at
     * some point rather than that they are at the keyboard now — which is the entire content of a step-up.
     */
    public static function stepUpNotForced(string $requestId): self
    {
        return new self(SsoFailureReason::StepUpNotForced, "Step-up request {$requestId} did not carry ForceAuthn.");
    }

    /**
     * The assertion validated, but its subject is not an account this deployment knows.
     *
     * A step-up NEVER provisions: the person already has a session, so an assertion naming somebody we have
     * never seen is a mismatch rather than a new joiner. Distinguished from {@see stepUpSubjectMismatch()}
     * only in the log — on the wire both are the same 404.
     */
    public static function stepUpUnknownSubject(string $email): self
    {
        return new self(SsoFailureReason::StepUpUnknownSubject, "No account matches {$email}; a step-up never provisions.", subject: $email);
    }

    /**
     * The IdP re-authenticated somebody ELSE.
     *
     * Legitimate on a shared machine — the person signed in at the IdP under their other account — and it
     * must still refuse: the pending action belongs to the session that asked, and quietly stamping its
     * clock on the strength of a different human's credentials is the failure this check exists for.
     */
    public static function stepUpSubjectMismatch(string $email): self
    {
        return new self(SsoFailureReason::StepUpSubjectMismatch, "The assertion re-authenticated {$email}, who is not the subject this step-up named.", subject: $email);
    }
}
