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
    | Trust anchor (M2) — refused before the document is read at all
    |--------------------------------------------------------------------------
    */

    /**
     * No stored signing certificate is inside its validity window, so nothing can vouch for an assertion.
     *
     * ⚠️ THIS IS THE ONE REFUSAL THAT IS ABOUT THE CONNECTION RATHER THAN ABOUT THE DOCUMENT, which is why
     * it fires before a single byte of the response is parsed and why {@see $subject} stays null: no
     * signature has verified, so there is no address anyone may be shown.
     *
     * `$state` is the roll-up — `expired`, `not_yet_valid` or `unreadable` — and it exists so the operator's
     * log line says which, while the admin's panel row says the one thing they can act on.
     */
    public static function idpCertificateUnusable(string $state): self
    {
        return new self(
            SsoFailureReason::IdpCertificateUnusable,
            "No stored signing certificate is currently usable (roll-up state: {$state}); the assertion was not read.",
        );
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

    /**
     * ⚠️ A WORKSPACE'S TRUST ANCHOR VOUCHES FOR AN IdP, NOT FOR AN ADDRESS SPACE (M18 — ADR-0016 §D34).
     *
     * The two refusals below this one, and M1's and M9's before them, are membership-layer answers to a
     * trust-layer question. Each is correct and neither could ask the question this one asks: a SAML
     * connection is metadata the workspace itself installed, so "the assertion is signed by the tenant's own
     * trust anchor" establishes that somebody authenticated at a provider that workspace chose — and nothing
     * at all about which addresses that provider is entitled to speak for. `SsoConnectionService` and
     * `UpdateSsoConnectionRequest` accept any IdP metadata; nothing in `app/`, `database/` or `config/`
     * mentioned domain ownership before this increment.
     *
     * ⛔ **TWO CONSEQUENCES SURVIVED M9, AND THE BACKLOG ROW NAMED NEITHER.**
     *
     * 1. **A PLATFORM-GLOBAL IDENTITY FACT WRITTEN FROM A TENANT-SCOPED TRUST ROOT.** JIT is allowed to
     *    CREATE, and `SsoUserProvisioner::createUser()` stamps `email_verified_at` — defending it as
     *    "the IdP's claim rather than a convenience". For an address in a domain the workspace does not
     *    control, that claim is unfounded, and it is not local: `users` is a deployment-wide table.
     *    `TenantMembershipService::identityIsEstablished()` then READS that column, so the stamp feeds
     *    M8's own authentication predicate — the address's true owner, invited later by their real employer,
     *    is refused the password-setting arm and handed a sign-in-then-accept hop they cannot complete.
     *    Squatting plus a denial of the recovery path M8 built.
     * 2. **A CROSS-TENANT ACCOUNT-EXISTENCE ORACLE.** {@see self::existingAccountNotMember()} renders on the
     *    failures panel as "Address already has an account elsewhere" while {@see self::provisioningDisabled()}
     *    renders as "Nobody here matches that address". An SSO-entitled admin could therefore assert any
     *    address and read back, from their own settings page, whether it has an account anywhere in the
     *    deployment. §D19's uniform 404 is intact and is simply not the surface that leaked.
     *
     * **THIS REFUSAL IS RAISED BEFORE EITHER OF THEM, AND THAT ORDER IS THE FIX FOR (2).** An admin who has
     * not proven the domain now learns exactly one thing — that they have not proven the domain — which is
     * also the only thing they can act on.
     *
     * ⚠️ **WHAT IT DOES NOT REFUSE, AND WHY THAT NEEDS NO GRANDFATHERING COLUMN.** It sits AFTER the `Active`
     * early return, so **an active membership is the grandfather**: no existing member of any live deployment
     * is locked out, and no backfill, per-connection mode or public-mailbox exclusion list is required. That
     * rests on an enumerated fact rather than a hopeful one — the only writers of `Active` are
     * `accept()` (an emailed token AND, since M8, either a fresh identity or the real person signed in as
     * themselves), `joinOpenTenant()` (self-registration, an older door where nothing is forged),
     * `joinViaGoogle()` (Google verified the mailbox) and `joinViaSso()` (downstream of this check). None
     * mints an Active row for a stranger's address on an assertion alone.
     */
    public static function domainNotVerified(string $email, string $domain): self
    {
        return new self(
            SsoFailureReason::DomainNotVerified,
            "{$email} is in the domain {$domain}, which this workspace has not verified; single sign-on will not bring in an address from a domain it cannot prove it controls.",
            subject: $email,
        );
    }

    /** A subject this workspace has never seen, on a connection whose admin turned JIT off. */
    public static function provisioningDisabled(string $email): self
    {
        return new self(SsoFailureReason::JitDisabled, "No member matches {$email} and just-in-time provisioning is disabled.", subject: $email);
    }

    /**
     * ⚠️ JIT MAY CREATE AN ACCOUNT; IT MAY NEVER ADOPT ONE — and that distinction is the whole control.
     *
     * Nothing requires that the email an IdP asserts belongs to a domain the asserting workspace controls,
     * and `TenantMembershipService::resolveUserByEmail()` runs on `pgsql_auth`, which sees EVERY account in
     * the deployment. Without this refusal an admin of any SSO-entitled workspace could point a connection
     * at an IdP they own, assert a stranger's address, have that stranger's CENTRAL account attached to
     * their own workspace, and be signed in as them — with no personal-2FA challenge, because the SAML door
     * does not run the password pipeline that would have issued one.
     *
     * ⛔ **AMENDED BY M9 — THE PARAGRAPH THAT USED TO CLOSE THIS BLOCK WAS ITSELF THE DEFECT.** It read: *"the
     * refusal is deliberately narrow… `Invited` passes because an administrator named that person by address,
     * which is a stronger statement than any assertion; `Declined` and `Removed` pass because a row exists, so
     * the workspace has already made a decision about this person."* **A membership row is a decision about an
     * ADDRESS, and so is an identity provider's assertion — neither is a claim about the PERSON.** That is what
     * M8 established one door over, and the carve-out this paragraph defended was a strictly STRONGER version of
     * the takeover the refusal above closes, because inviting a stranger needs no access to their mailbox at all.
     *
     * The scope of THIS factory is unchanged: an account exists and this workspace has no row for it. The three
     * row-exists statuses are now asked a second question by {@see self::establishedIdentityNotJoined()}, and a
     * genuinely new address still provisions exactly as before.
     */
    public static function existingAccountNotMember(string $email): self
    {
        return new self(
            SsoFailureReason::ExistingAccountNotMember,
            "{$email} already has an account and is not a member of this workspace; single sign-on will not adopt it.",
            subject: $email,
        );
    }

    /**
     * ⚠️ AN INVITATION NAMES AN ADDRESS; IT DOES NOT ESTABLISH WHO IS BEHIND ONE (M9).
     *
     * The sibling refusal above fires only when this workspace has NO row for an existing account. A row of any
     * other status — `Invited`, `Declined`, `Removed` — used to disarm it, on the reasoning that the workspace
     * had "already made a decision about that person". `MemberController::invite()` validates
     * `['required', 'email', 'max:255']` with no domain-ownership check anywhere, and
     * `TenantMembershipService::resolveOrCreateUser()` binds the invitation to the address's EXISTING global
     * identity on `pgsql_auth`. So an admin of any SSO-entitled workspace could invite `victim@othercompany.com`,
     * assert that address at an identity provider they configured themselves, and hold a session as the victim —
     * **needing no emailed token at all**, which makes it strictly stronger than the invitation-door takeover M8
     * closed. `Invited` additionally bypasses `jit_provisioning_enabled` by explicit condition, so no
     * configuration protected a workspace, and `SsoUserProvisioner::roleFor()` lands them at the INVITED role, so
     * the attacker chose the privilege level too.
     *
     * So the door asks M8's own question — `TenantMembershipService::identityIsEstablished()` — and refuses an
     * identity that has demonstrably been used: an established person completes an invitation IN THEIR OWN
     * BROWSER, where signing in runs the password check and the second-factor challenge. A never-used placeholder
     * this workspace's own invitation created is untouched and still completes through the identity provider.
     *
     * ⚠️ THE REFUSAL IS RAISED BEFORE ANY WRITE, AND THAT MATTERS BEYOND TIDINESS. `attachMember()` force-fills
     * `invite_token => null`, so an adoption also CONSUMED the real invitee's emailed link: their own link then
     * 404s and the whole event is indistinguishable from an ordinary expired invitation.
     *
     * ⛔ REJECTED: narrowing the carve-out to *"a placeholder this workspace actually created"* instead. It needs
     * a fact the schema does not record — nothing distinguishes an invite placeholder from a self-registered
     * account that was never used — which is the residual M8 priced and left, and the reason `users.password_set_at`
     * is filed rather than assumed. What ADR-0016 §D33 records is the narrower question that IS answerable today.
     */
    public static function establishedIdentityNotJoined(string $email): self
    {
        return new self(
            SsoFailureReason::EstablishedIdentityNotJoined,
            "{$email} already has an established identity and has not completed this workspace's invitation; single sign-on will not complete it for them.",
            subject: $email,
        );
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
