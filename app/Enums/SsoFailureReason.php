<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Sso\SsoAuthenticationException;

/**
 * Why a SAML sign-in was refused (Phase 4, P1c — ADR-0016 §D26). Persisted on `sso_auth_failures.reason`
 * and pinned by a CHECK generated from {@see values()}, the {@see SsoAuthIntent} precedent.
 *
 * ── THESE TOKENS WERE ALREADY THE VOCABULARY; P1c ONLY GAVE THEM A TYPE ──────────────────────────────
 * P1b wrote them as bare strings inside {@see SsoAuthenticationException}'s factories, and its docblock
 * already said what they were for: *"a stable machine token rather than the prose, because the prose is for
 * a human reading a log line and the token is what an operator greps for and what a future failures panel
 * would group by."* This is that panel, so the list stops being a convention and becomes a closed set the
 * database, the presenter and the front end all read from one place.
 *
 * ── ⚠️ THE LABEL AND HINT ARE FOR AN ADMIN, WHICH IS NOT THE AUDIENCE §D19 REFUSES TO EXPLAIN ANYTHING TO ─
 * §D19 makes every ACS refusal an indistinguishable 404 because an endpoint that explains itself is an
 * oracle for anyone tuning a forgery: "wrong audience" says the signature verified. That posture is about
 * the UNAUTHENTICATED WIRE. The workspace's own Owner or Admin, authenticated and holding
 * `tenant.settings.manage`, is a different audience being asked a different question — "why can my
 * colleague not sign in?" — and answering it discloses nothing an attacker could not already determine by
 * having the credentials of an admin of the workspace they are attacking.
 *
 * {@see hint()} is deliberately an INSTRUCTION rather than a restatement. "The identity provider's clock is
 * more than a minute out" tells an admin what to do; "assertion outside conditions" sends them hunting
 * through a certificate they never touched.
 */
enum SsoFailureReason: string
{
    /*
    |--------------------------------------------------------------------------
    | Transport and parsing — refused before the XML parser allocates anything
    |--------------------------------------------------------------------------
    */
    case ResponseMissing = 'response_missing';
    case ResponseTooLarge = 'response_too_large';
    case ResponseMalformed = 'response_malformed';

    /*
    |--------------------------------------------------------------------------
    | Binding — §D9's refusal of anything this SP did not ask for
    |--------------------------------------------------------------------------
    */
    case UnsolicitedAssertion = 'unsolicited_assertion';
    case UnknownRequest = 'unknown_request';
    case RequestReplayed = 'request_replayed';
    case AssertionReplayed = 'assertion_replayed';

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    case InvalidAssertion = 'invalid_assertion';
    case AssertionOutsideConditions = 'assertion_outside_conditions';

    /*
    |--------------------------------------------------------------------------
    | Identity and provisioning
    |--------------------------------------------------------------------------
    */
    case NoEmail = 'no_email';
    case JitDisabled = 'jit_disabled';
    case ExistingAccountNotMember = 'existing_account_not_member';
    case MembershipSuspended = 'membership_suspended';
    case SeatQuotaExhausted = 'seat_quota_exhausted';

    /*
    |--------------------------------------------------------------------------
    | Step-up (P1c)
    |--------------------------------------------------------------------------
    */
    case StepUpNotForced = 'step_up_not_forced';
    case StepUpUnknownSubject = 'step_up_unknown_subject';
    case StepUpSubjectMismatch = 'step_up_subject_mismatch';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** The one line the panel leads with. Names what happened, never what to do — that is {@see hint()}. */
    public function label(): string
    {
        return match ($this) {
            self::ResponseMissing => 'No SAML response',
            self::ResponseTooLarge => 'Response too large',
            self::ResponseMalformed => 'Response could not be read',
            self::UnsolicitedAssertion => 'Sign-in did not start here',
            self::UnknownRequest => 'Sign-in request expired or already used',
            self::RequestReplayed => 'Sign-in request used twice',
            self::AssertionReplayed => 'Response presented twice',
            self::InvalidAssertion => 'Response failed validation',
            self::AssertionOutsideConditions => 'Response was outside its validity window',
            self::NoEmail => 'No email address in the response',
            self::JitDisabled => 'Not a member, and automatic provisioning is off',
            self::ExistingAccountNotMember => 'Address already has an account elsewhere',
            self::MembershipSuspended => 'Membership is suspended',
            self::SeatQuotaExhausted => 'No seat available',
            self::StepUpNotForced => 'Re-authentication was not forced',
            self::StepUpUnknownSubject => 'Re-authentication named an unknown account',
            self::StepUpSubjectMismatch => 'Re-authentication named a different person',
        };
    }

    /**
     * What the admin should do about it.
     *
     * ⚠️ NEVER PHRASED AS AN ACCUSATION OR A DIAGNOSIS OF THE MEMBER. Several of these are ordinary — a
     * member who left a sign-in page open overnight produces `unknown_request`, and a workspace that
     * deliberately runs invite-only produces `jit_disabled` every time somebody new tries. A panel that
     * reads as an incident log for its own routine configuration teaches admins to ignore it.
     */
    public function hint(): string
    {
        return match ($this) {
            self::ResponseMissing,
            self::ResponseMalformed,
            self::ResponseTooLarge => 'The identity provider sent something this service provider could not read. Check that it is posting to the ACS URL on the details card.',
            self::UnsolicitedAssertion => 'Sign-in must start from the sign-in URL on the details card. Identity-provider-initiated sign-in is not supported.',
            self::UnknownRequest => 'Usually a sign-in page left open too long, or a second tab. Ask them to start again from the sign-in URL.',
            self::RequestReplayed, self::AssertionReplayed => 'The same response arrived twice. Harmless if it was a refreshed browser tab; worth investigating if it repeats.',
            self::InvalidAssertion => 'The signature, audience or destination did not check out. Re-import the identity provider metadata — this is what an expired or rotated signing certificate looks like.',
            self::AssertionOutsideConditions => 'The identity provider’s clock is out by more than the allowance. Check time synchronisation on that server.',
            self::NoEmail => 'The response carried no usable email address. Map an email attribute on the policy card, or set the NameID format to emailAddress.',
            self::JitDisabled => 'Nobody here matches that address and automatic provisioning is off. Invite them, or turn on just-in-time provisioning.',
            self::ExistingAccountNotMember => 'That address already has an account on this platform but no membership here, so single sign-on will not adopt it. Invite them by email instead — an invitation is an administrator naming them, which is what makes a claim on somebody else’s address safe.',
            self::MembershipSuspended => 'Their membership was suspended by an administrator. Single sign-on deliberately does not reverse that.',
            self::SeatQuotaExhausted => 'The workspace has no free seat. Remove a member or raise the plan’s seat limit.',
            self::StepUpNotForced => 'A re-authentication request reached the identity provider without ForceAuthn. Report this — it should not be possible.',
            self::StepUpUnknownSubject => 'Re-authentication resolved to an address with no account here. Re-authentication never creates one.',
            self::StepUpSubjectMismatch => 'They signed in at the identity provider as somebody else — common on a shared machine. Ask them to sign out there first.',
        };
    }
}
