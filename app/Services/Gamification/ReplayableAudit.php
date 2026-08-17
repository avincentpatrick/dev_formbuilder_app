<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * One row of the `audits` ledger, reduced to the six facts {@see AuditReplayMap} is allowed to see
 * — Increment K1c.
 *
 * ⚠️ **`newValueKeys` IS KEY NAMES ONLY, AND THE NARROWING IS DELIBERATE RATHER THAN CONVENIENT.** The map
 * has to tell a review apart from an edit, and both are written as `('submission', 'updated')`; the only
 * signal is the SHAPE of the payload. Handing it the payload itself would put audit VALUES — a compliance
 * ledger's redacted contents — inside a scoring service that has no business reading them. Key names carry
 * the whole discriminator and nothing else. `AuditRedactor::apply()` replaces a sensitive value in place
 * and never removes its key, so redaction cannot erase the signal either.
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
     */
    public function __construct(
        public string $auditId,
        public string $auditableType,
        public string $event,
        public string $auditableId,
        public ?string $actorUserId,
        public ?string $respondentUserId,
        public array $newValueKeys,
    ) {}
}
