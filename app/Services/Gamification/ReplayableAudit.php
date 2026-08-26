<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * One row of the `audits` ledger, reduced to the seven facts {@see AuditReplayMap} is allowed to see
 * — Increment K1c, widened by M24.
 *
 * ⚠️ **`newValueKeys` IS KEY NAMES ONLY, AND THE NARROWING IS DELIBERATE RATHER THAN CONVENIENT.** The map
 * has to tell a review apart from an edit, and both are written as `('submission', 'updated')`; the only
 * signal is the SHAPE of the payload. Handing it the payload itself would put audit VALUES — a compliance
 * ledger's redacted contents — inside a scoring service that has no business reading them. Key names carry
 * the whole discriminator and nothing else. `AuditRedactor::apply()` replaces a sensitive value in place
 * and never removes its key, so redaction cannot erase the signal either.
 *
 * ── ⛔ M24: THE PARAGRAPH ABOVE WAS RIGHT ABOUT THE PRINCIPLE AND WRONG ABOUT THE FACT ──────────────────
 * "Key names carry the whole discriminator" held for review-versus-edit and **fails for review-versus-
 * review**. `SubmissionReviewService::snapshot()` is one fixed six-key literal serving all FOUR review
 * verbs, so `newValueKeys` is byte-identical for `markUnderReview`, `approve`, `returnToRespondent` and
 * `archive`. Key shape carries exactly zero signal about which of them ran, and only two of them score
 * (ADR-0020 §D12). The map was not reasoning badly; it was being handed insufficient evidence.
 *
 * So ONE value is admitted, and the narrowness is the whole point of admitting it:
 *
 *   - It is `new_values.status` and nothing else — a workflow enum, not ledger contents. The principle
 *     above is about a compliance ledger's *redacted* material, and `AuditRedactor::PII['submission']` is
 *     `guest_ip`, `guest_user_agent`, `guest_contact_email` and `remarks`. `status` is not in it and never
 *     has been, so this field is always the true value and never a placeholder.
 *   - It is the **historical** status, read out of `new_values` by the enumerator. ⚠️ It is deliberately
 *     NOT `submissions.status`, which `GamificationBackfill::AUDITS_SQL` already has joined and which is
 *     one word away: that is the row's CURRENT state, so a submission approved and later archived would
 *     read `archived` today and the fix would erase the legitimate award instead of the spurious one.
 *   - Nullable, because a hand-authored or pre-K1c row may carry no `status` key at all — and a missing
 *     status must score nothing rather than be guessed at, which is the same refusal the `unmapped` bucket
 *     already exists to make visible.
 *
 * ⚠️ **`respondentUserId` IS NOT `actorUserId`, AND CONFLATING THEM WOULD BREAK ADR-0020 §D8.** For a
 * collected submission the credited member is the one on `submissions.respondent_user_id` — null for a
 * guest, who credits nobody. The `audits` row's own `user_id` happens to hold the same value today, because
 * `SubmissionFinalizer` passes the respondent as the actor, but that is two writers agreeing rather than
 * one fact: a hand-authored fixture row, or a `nullOnDelete` that fired on only one of the two columns,
 * separates them. The enumerator joins for the real column so §D8 holds structurally.
 */
final readonly class ReplayableAudit
{
    /**
     * @param  list<string>  $newValueKeys  the TOP-LEVEL keys of `new_values`; never the values
     * @param  ?string  $newStatus  `new_values.status` — the ONE value this type carries, and only
     *                              because key shape cannot tell four review verbs apart. See above.
     */
    public function __construct(
        public string $auditId,
        public string $auditableType,
        public string $event,
        public string $auditableId,
        public ?string $actorUserId,
        public ?string $respondentUserId,
        public array $newValueKeys,
        public ?string $newStatus = null,
    ) {}
}
