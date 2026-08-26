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
 * ── ⛔ M24: AND HOW A SCORING REVIEW IS TOLD FROM A NON-SCORING ONE, WHICH THIS MAP USED TO GET WRONG ───
 * The section above is true and was never sufficient. It tells a review from an edit; it does not tell the
 * four REVIEW verbs apart, and `snapshot()` is one fixed six-key literal serving all four — `remarks`
 * included, emitted unconditionally, because `applyRemarks()` decides only the value. So the review marker
 * fires identically for `markUnderReview`, `approve`, `returnToRespondent` and `archive`, and this map
 * scored every one of them as {@see PointRule::SubmissionReviewed}. Two of the four have no live listener
 * at all, so a replay minted points for acts the live engine has never scored and never will — permanently,
 * because `point_awards` has no DELETE policy (ADR-0020 §D4).
 *
 * **The discriminator is therefore `new_values.status`, and the spec is the LIVE LISTENERS.** A backfill
 * exists to make history look as though they had been running, so {@see self::SCORED_REVIEW_STATUSES} is
 * exactly the pair they fire on. ADR-0020 §D12 records the correction; §D10(a)'s sentence — *"a review
 * always carries `remarks`"* — is where the defect came from, and it is corrected in place rather than
 * here, so the next reader of that ADR cannot rebuild this bug from the same authority.
 *
 * ⚠️ **ANYTHING MATCHING NEITHER MARKER IS UNMAPPED, NOT GUESSED.** An edit that changed no answers at all
 * would emit the four status keys and nothing else, and there is no honest way to read that as a review.
 * The backfill counts unmapped rows and reports them, which is the only way a future third writer of this
 * tuple becomes visible rather than becoming silently mis-scored at whichever rule the fallback picked.
 * As of M24 a claimed or archived submission lands in that same bucket, for the same reason.
 */
final class AuditReplayMap
{
    /**
     * The `(auditable_type, event)` tuples worth reading at all. {@see GamificationBackfill} builds its
     * WHERE clause from exactly this list, so the query and the map cannot drift about what is fetched.
     *
     * ⚠️ **PAIRS, NOT TWO SEPARATE LISTS, AND THE DIFFERENCE IS A REPORTING DEFECT RATHER THAN A TIDY-UP.**
     * The first version filtered `type = ANY(...) AND event = ANY(...)`, whose cross product also admits
     * `('form','updated')` — a tuple `FormService` writes from three separate methods and which scores
     * nothing. Those rows reached the map, mapped to null, and landed in the `unmapped` bucket, which the
     * operator report describes as *"audits has grown a writer this map has never been told about"*. On any
     * real workspace that number would have been dominated by ordinary form edits, making the one signal it
     * exists to carry unreadable. With pairs, `unmapped` means exactly one thing: a `('submission','updated')`
     * carrying neither marker.
     *
     * `tenant_users` is absent on purpose: the membership rules come from the membership table.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const array SCORED_PAIRS = [
        ['form', 'created'],
        ['form_version', 'published'],
        ['submission', 'created'],
        ['submission', 'updated'],
    ];

    /** An edit's marker: the flattened per-answer diff `SubmissionAnswerEditService::answerDiff()` writes. */
    public const string EDIT_MARKER_PREFIX = 'answers.';

    /** A review's marker: `SubmissionReviewService::snapshot()` emits it on every transition. */
    public const string REVIEW_MARKER = 'remarks';

    /**
     * The statuses of the two review verbs the LIVE engine actually scores — ADR-0020 §D12, added by M24.
     *
     * ⚠️ **THE REVIEW MARKER ALONE IS NOT A DISCRIMINATOR, AND BELIEVING IT WAS IS THE DEFECT THIS FIXES.**
     * `snapshot()` is one fixed six-key literal serving all FOUR review verbs, and it emits `remarks`
     * unconditionally — `applyRemarks()` decides only the VALUE. So `markUnderReview` and `archive` carry
     * the marker exactly as `approve` and `returnToRespondent` do, and the map scored all four. Only two of
     * them have a live listener: `AwardPointsForSubmissionApproved` and `AwardPointsForSubmissionReturned`,
     * both awarding {@see PointRule::SubmissionReviewed} on the same subject. A replay is supposed to make
     * a workspace's history look as though those listeners had been running all along, so the set below IS
     * that pair and is defined by them.
     *
     * ⚠️ **BOTH, NOT JUST `approved` — AND THE ROW THAT REPORTED THIS BUG INVITED THE ONE-VERB FIX.** It is
     * titled "two verbs the live engine never scores", which reads as "score only approval". Dropping
     * `returned` would silently stop crediting every returned submission in every backfill: the same class
     * of silent mis-scoring, pointed the other way, and no test in this file would have caught it before
     * M24 added one.
     *
     * ⚠️ **PINNED AS LITERALS, NOT AS `SubmissionStatus` CASES, AND THE REASON IS NOT STYLE.** These are the
     * strings as they appear in a `jsonb` column written months ago. An enum reference would silently stop
     * matching historical rows the day somebody renamed a case — the ledger keeps the old string forever,
     * because `point_awards` and `audits` are both append-only. The literal is an agreement with the
     * ledger's contents, which no enum can enforce retroactively. `AuditReplayMapTest` asserts this set
     * against `SubmissionStatus`'s current values, so a rename turns the gate red and forces the question
     * "what about the rows already written?" to be answered deliberately instead of by accident. Same
     * reasoning as the two markers above, one layer down.
     *
     * @var list<string>
     */
    public const array SCORED_REVIEW_STATUSES = ['approved', 'returned'];

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

    /**
     * See the class docblock: the shape of `new_values`' key set, and then — for a review — its status.
     *
     * ⚠️ **THE ORDER OF THE TWO CHECKS IS LOAD-BEARING AS OF M24, WHERE BEFORE IT WAS MERELY TIDY.** The
     * `answers.` loop used to run first only to settle precedence on a payload that cannot occur; now it is
     * doing real work, because `SubmissionAnswerEditService::statusSnapshot()` emits `status` TOO. An edit
     * of an approved submission carries `status = 'approved'`, so a status test reached before the edit
     * marker would price a 1-point correction as a 3-point review. The edit marker is checked first and
     * returns first, exactly as it always did — do not "simplify" these into one `match`.
     *
     * ⚠️ **AND A ROW WITH THE MARKER BUT NO SCORING STATUS IS `null`, NOT A CHEAPER RULE.** A claimed or
     * archived submission evidences no earnable act at all — the live engine awards nothing for either —
     * so it belongs in the `unmapped` bucket with everything else this map refuses to guess at. Scoring it
     * as {@see PointRule::SubmissionEdited} to "not lose the row" would be inventing an act that never
     * happened.
     */
    private function submissionUpdate(ReplayableAudit $row): ?PointRule
    {
        foreach ($row->newValueKeys as $key) {
            if (str_starts_with($key, self::EDIT_MARKER_PREFIX)) {
                return PointRule::SubmissionEdited;
            }
        }

        if (! in_array(self::REVIEW_MARKER, $row->newValueKeys, true)) {
            return null;
        }

        // The marker says "a review verb wrote this"; the status says WHICH, and only two of the four
        // score. A null status — a pre-K1c or hand-authored row carrying no `status` key — scores nothing
        // rather than being guessed at.
        return in_array($row->newStatus, self::SCORED_REVIEW_STATUSES, true)
            ? PointRule::SubmissionReviewed
            : null;
    }
}
