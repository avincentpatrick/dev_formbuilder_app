<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Services\Auth\GoogleSignInProvisioner;
use RuntimeException;

/**
 * Google answered honestly and this product refuses anyway (Increment J3c2, ADR-0019 §D4, §D8).
 *
 * Distinct from {@see GoogleAuthException}, which means Google could not be asked or answered unusably.
 * Everything here is OUR policy: an unverified address, a subject that does not match the account holding
 * that email, a suspended membership, a closed workspace, a full seat quota.
 *
 * ⚠️ EVERY ONE OF THESE PRODUCES THE SAME `?google=failed`, AND THE `reason` NEVER REACHES THE RESPONSE.
 * ADR-0019 §D9 gives this flow one indistinguishable bounce, because the alternative is a disclosure
 * oracle: "your local account is not verified" tells a stranger that an account exists on that address,
 * and "this workspace is invitation-only" tells them the workspace exists. The reason is a LOG field, so
 * an operator answering "why can nobody sign in" is not left guessing — the two audiences get different
 * amounts of truth on purpose, which is ADR-0016 §D19's posture applied here.
 *
 * ⚠️ AND NONE OF THEM IS WRITTEN TO `audits`. That table is append-only by RLS and never pruned, so an
 * unauthenticated endpoint writing a row per rejection is an amplification primitive.
 */
final class GoogleSignInRefusedException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * This flow's analogue of SAML's signature check (§D3), asserted a second time here because the
     * callback and this class are separated by a host boundary and a stored row.
     */
    public static function emailNotVerified(): self
    {
        return new self('email_not_verified', 'Google did not vouch for this address.');
    }

    /**
     * The local account on this address is already bound to a DIFFERENT Google `sub`.
     *
     * This is the reassigned-Workspace-address takeover §D2 describes: a Workspace administrator can hand
     * `alice@company.com` to a new employee after Alice leaves, and without this the new holder would
     * inherit Alice's account.
     */
    public static function subjectMismatch(): self
    {
        return new self('subject_mismatch', 'That address is already linked to a different Google account.');
    }

    /**
     * The local account exists but never proved it owns its own address (decision of record, §D4).
     *
     * Linking anyway would hand an account to whoever registered an unverified row on that address first.
     */
    public static function localAccountUnverified(): self
    {
        return new self('local_account_unverified', 'The local account on that address is not verified.');
    }

    /**
     * The link UPDATE affected a number of rows other than one.
     *
     * Either a concurrent sign-in won the race, or — the case worth naming — the write ran somewhere it
     * could not see the row. See {@see GoogleSignInProvisioner::link()}.
     */
    public static function linkageNotWritten(): self
    {
        return new self('linkage_not_written', 'The Google identity could not be linked to the account.');
    }

    /** An administrative sanction a fourth door must not quietly reverse (§D8). */
    public static function membershipSuspended(): self
    {
        return new self('membership_suspended', 'That membership is suspended.');
    }

    /** `RegistrationGate` said no — the platform toggle, or this workspace being invitation-only (§D8). */
    public static function registrationClosed(): self
    {
        return new self('registration_closed', 'Registration is not open here.');
    }

    /** The workspace is full. Raised so the enclosing transaction discards a freshly created account. */
    public static function seatQuotaExhausted(): self
    {
        return new self('seat_quota_exhausted', 'That workspace has no seats available.');
    }
}
