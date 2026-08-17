<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\PointRule;

/**
 * Turns one `audits` row into the award it evidences, or into nothing — Increment K1c.
 *
 * Pure: no database, no container, no clock. That is what lets the branch this whole increment turns on —
 * telling a review apart from an edit — be tested exhaustively against literal payloads instead of against
 * a fixture that happened to be shaped right.
 *
 * ── ⚠️ THE ROW SAID "REPLAY `audits` THROUGH THE SAME `PointRule` MAP". THERE IS NO SUCH MAP ────────────
 * Verified against the code before this class was written, and the premise is false in three places:
 *
 *   1. **`member.invited` is not in `audits` at all.** `TenantMembershipService::invite()` raises
 *      `MemberInvited` and writes NO audit row. (`docs/audit-compliance-logging-spec.md` §1 claims the
 *      `tenant_users` `created` row covers "invite sent"; as built it does not.)
 *   2. **`member.joined` is only half there.** The `('tenant_users', 'created')` row is written by the
 *      private `attachMember()`, which serves the three self-serve doors — `accept()`, the commonest door
 *      of all, writes neither an audit row nor an event.
 *   3. **`('submission', 'updated')` is written by TWO services** — `SubmissionReviewService::apply()` and
 *      `SubmissionAnswerEditService::edit()` — so `(auditable_type, event)` cannot tell a 3-point review
 *      from a 1-point edit.
 *
 * So this class maps the five ACT rules only, and the two MEMBERSHIP rules are read from `tenant_users`
 * instead — `invited_by` / `invited_at` / `joined_at`, which is the authority on membership and is complete
 * for every door. ADR-0020 §D10 records the correction; §D5's "backfilled once from `audits`" is now
 * "from `audits` and `tenant_users`".
 *
 * ── HOW REVIEW AND EDIT ARE TOLD APART, AND WHY IT IS THE PAYLOAD'S SHAPE ──────────────────────────────
 * `SubmissionReviewService::snapshot()` always emits `remarks` and `returned_reason` and never an answer
 * key. `SubmissionAnswerEditService` emits `statusSnapshot()` — four keys, neither of those two — plus a
 * flattened `answers.<key>` entry per CHANGED answer, and its own docblock records that the `answers.`
 * prefix sits at the top level precisely so `AuditRedactor` can match it. The two key sets are therefore
 * disjoint on exactly the markers below. Redaction cannot erase the signal: `AuditRedactor::apply()`
 * replaces a sensitive value in place and leaves its key.
 *
 * ⚠️ **ANYTHING MATCHING NEITHER MARKER IS UNMAPPED, NOT GUESSED.** An edit that changed no answers at all
 * would emit the four status keys and nothing else, and there is no honest way to read that as a review.
 * The backfill counts unmapped rows and reports them, which is the only way a future third writer of this
 * tuple becomes visible rather than becoming silently mis-scored at whichever rule the fallback picked.
 */
final class AuditReplayMap
{
    /**
     * The `auditable_type` values worth reading at all — the enumerator's WHERE clause is built from this,
     * so the query and the map cannot drift into disagreeing about what is worth fetching.
     *
     * `tenant_users` is absent on purpose: the membership rules come from the membership table.
     *
     * @var list<string>
     */
    public const array SCORED_TYPES = ['form', 'form_version', 'submission'];

    /** @var list<string> */
    public const array SCORED_EVENTS = ['created', 'published', 'updated'];

    /** An edit's marker: the flattened per-answer diff `SubmissionAnswerEditService::answerDiff()` writes. */
    public const string EDIT_MARKER_PREFIX = 'answers.';

    /** A review's marker: `SubmissionReviewService::snapshot()` emits it on every transition. */
    public const string REVIEW_MARKER = 'remarks';

    /**
     * The award this row evidences, or null if it evidences none.
     *
     * Null covers four distinct facts and deliberately does not distinguish them here — the enumerator
     * does, by counting what it skipped and why: a row of no scoring interest, an ambiguous
     * `('submission','updated')`, a guest submission that credits nobody, and an actor whose account has
     * since been deleted (`audits.user_id` is `nullOnDelete`, so a departed member's history stops being
     * creditable).
     */
    public function candidate(ReplayableAudit $row): ?AwardCandidate
    {
        $rule = $this->rule($row);

        if ($rule === null) {
            return null;
        }

        // ⚠️ §D8 LIVES ON THIS LINE. Collection credits the member on the SUBMISSION, never the audit's own
        // actor — see ReplayableAudit for why the two are not the same fact even though they agree today.
        $userId = $rule === PointRule::SubmissionCollected ? $row->respondentUserId : $row->actorUserId;

        if ($userId === null || $userId === '') {
            return null;
        }

        return new AwardCandidate($rule, $userId, $this->subjectType($rule), $row->auditableId);
    }

    /**
     * ⚠️ The subject vocabulary is the LISTENERS', copied here on purpose and pinned by a test.
     *
     * A replay that keyed on a different subject than the live listener does not collide with it — it
     * writes a SECOND row, because `subject_type`/`subject_id` are part of the idempotency index. The
     * failure mode is therefore a silently doubled score rather than an error, which is why this is a
     * total `match` over the five act rules rather than a lookup that could return a default.
     */
    private function subjectType(PointRule $rule): string
    {
        return match ($rule) {
            PointRule::FormCreated => 'form',
            PointRule::FormPublished => 'form_version',
            PointRule::SubmissionCollected,
            PointRule::SubmissionReviewed,
            PointRule::SubmissionEdited => 'submission',
            // Unreachable: rule() never returns either, because neither is evidenced in `audits` at all.
            // Spelled out rather than defaulted so that adding a case to PointRule is a compile-time
            // decision here instead of a silent 'form'.
            PointRule::MemberInvited => 'invite',
            PointRule::MemberJoined => 'member',
        };
    }

    /**
     * The act this row evidences, ignoring whether there is anybody to credit for it.
     *
     * Public because {@see GamificationBackfill} has to tell "this row scores nothing" apart from "this row
     * scores something but credits nobody" in order to report the two separately — and those are genuinely
     * different facts about a workspace: the first is noise in the ledger, the second is a guest response
     * or a departed member. Asking twice is cheaper and far clearer than probing {@see self::candidate()}
     * with invented ids to see which branch it took.
     */
    public function rule(ReplayableAudit $row): ?PointRule
    {
        return match (true) {
            $row->auditableType === 'form' && $row->event === 'created' => PointRule::FormCreated,
            $row->auditableType === 'form_version' && $row->event === 'published' => PointRule::FormPublished,
            $row->auditableType === 'submission' && $row->event === 'created' => PointRule::SubmissionCollected,
            $row->auditableType === 'submission' && $row->event === 'updated' => $this->submissionUpdate($row),
            default => null,
        };
    }

    /** See the class docblock: the shape of `new_values`' key set is the only signal there is. */
    private function submissionUpdate(ReplayableAudit $row): ?PointRule
    {
        foreach ($row->newValueKeys as $key) {
            if (str_starts_with($key, self::EDIT_MARKER_PREFIX)) {
                return PointRule::SubmissionEdited;
            }
        }

        return in_array(self::REVIEW_MARKER, $row->newValueKeys, true)
            ? PointRule::SubmissionReviewed
            : null;
    }
}
