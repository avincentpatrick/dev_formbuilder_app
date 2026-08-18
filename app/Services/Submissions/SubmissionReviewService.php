<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AuditEvent;
use App\Enums\SubmissionStatus;
use App\Events\DomainEvent;
use App\Events\SubmissionApproved;
use App\Events\SubmissionReturned;
use App\Exceptions\Submissions\SubmissionReviewException;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer workflow (Increment F7): the guarded status transitions a Validator/Owner/Admin drives from
 * the inbox detail view. Each transition takes a fresh `SELECT … FOR UPDATE` lock, asserts the current status
 * is a legal source state (else {@see SubmissionReviewException}), records the reviewer + timestamps per
 * data-dictionary §7, and saves — all in one transaction. Authorization is the `can:review,submission` route
 * middleware ({@see SubmissionPolicy::review()}); this service assumes it has already passed.
 *
 * `submitted → approved/returned` may skip the intermediate `under_review` claim.
 *
 * ── The 7th TODO(audit) site, retired in I2 ───────────────────────────────────────────────────────────
 * H4 retired six `TODO(audit)` markers and its test file pins those six; this one survived, uncounted,
 * because the four review verbs funnel through a single private {@see self::apply()} that H4's inventory
 * never walked. All four now emit one `submission`/`updated` row from that one site.
 *
 * `remarks` IS in the payload and IS redacted at write ({@see AuditRedactor::PII}); `returned_reason` is
 * in the payload raw. The asymmetry is deliberate and argued where the registration lives.
 *
 * ── Two of the four verbs also ANNOUNCE (I3) ───────────────────────────────────────────────────────────
 * `approve` and `return` raise {@see SubmissionApproved} / {@see SubmissionReturned} post-commit; the
 * notification listeners tell the respondent, and any webhook or Slack rule subscribed to those event types
 * fires from the same one event. `start reviewing` and `archive` announce nothing: claiming a submission is
 * internal queue mechanics nobody outside this screen is waiting on, and archival is a retention act — both
 * are still fully audited, which is the record that matters for them.
 *
 * That is why {@see self::apply()} no longer returns the transaction directly: an announcement made INSIDE
 * the closure would fire for a transition that then rolled back.
 */
final class SubmissionReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Claim a submitted response for review (no reviewer/timestamp is finalized until approve/return). */
    public function markUnderReview(Submission $submission, User $reviewer, ?string $remarks = null): Submission
    {
        return $this->apply($submission, [SubmissionStatus::Submitted], 'start reviewing', $reviewer, function (Submission $s) use ($remarks): void {
            $s->status = SubmissionStatus::UnderReview;
            $this->applyRemarks($s, $remarks);
        });
    }

    public function approve(Submission $submission, User $reviewer, ?string $remarks = null): Submission
    {
        return $this->apply(
            $submission,
            [SubmissionStatus::Submitted, SubmissionStatus::UnderReview],
            'approve',
            $reviewer,
            function (Submission $s) use ($reviewer, $remarks): void {
                $s->status = SubmissionStatus::Approved;
                $s->validated_by = (string) $reviewer->id;
                $s->validated_at = now();
                $s->finalized_at = now();
                $this->applyRemarks($s, $remarks);
            },
            static fn (Submission $s): DomainEvent => SubmissionApproved::for($s, $reviewer),
        );
    }

    public function returnToRespondent(Submission $submission, User $reviewer, string $reason, ?string $remarks = null): Submission
    {
        return $this->apply(
            $submission,
            [SubmissionStatus::Submitted, SubmissionStatus::UnderReview],
            'return',
            $reviewer,
            function (Submission $s) use ($reviewer, $reason, $remarks): void {
                $s->status = SubmissionStatus::Returned;
                $s->validated_by = (string) $reviewer->id;
                $s->validated_at = now();
                $s->returned_reason = $reason;
                $this->applyRemarks($s, $remarks);
            },
            static fn (Submission $s, SubmissionStatus $from): DomainEvent => SubmissionReturned::for($s, $reviewer, $from->value),
        );
    }

    /**
     * Move a non-draft submission to the terminal `archived` retention state.
     *
     * ⚠️ `SubmissionStatus::ScreenedOut` IS DELIBERATELY ABSENT FROM THIS `$from` LIST — and from the other
     * three — rather than merely un-added (I9a). It is not a workflow preference: `archived` CONSUMES a
     * `max_responses` slot and `screened_out` does not ({@see Submission::scopeConsumesCapacity()}),
     * so archiving a screened-out row would silently convert a non-consuming row into a consuming one and
     * retroactively overfill a cap that was already at its limit. Any instinct to widen this "so reviewers can
     * tidy the inbox" has to be answered with a separate `capacity_consumed_at` column, not with a fifth
     * enum case in this array. `ScreenedOutTest` asserts the accepted set is exactly the four below, so a
     * future case cannot be waved in without the assertion arguing back.
     */
    public function archive(Submission $submission, User $reviewer, ?string $remarks = null): Submission
    {
        return $this->apply($submission, [
            SubmissionStatus::Submitted, SubmissionStatus::UnderReview,
            SubmissionStatus::Approved, SubmissionStatus::Returned,
        ], 'archive', $reviewer, function (Submission $s) use ($remarks): void {
            $s->status = SubmissionStatus::Archived;
            $s->finalized_at = now();
            $this->applyRemarks($s, $remarks);
        });
    }

    /**
     * @param  list<SubmissionStatus>  $from  legal source states
     * @param  Closure(Submission): void  $mutate
     * @param  Closure(Submission, SubmissionStatus): DomainEvent|null  $announce  builds the post-commit
     *                                                                             domain event, or null for a
     *                                                                             verb that announces nothing
     */
    private function apply(
        Submission $submission,
        array $from,
        string $action,
        User $reviewer,
        Closure $mutate,
        ?Closure $announce = null,
    ): Submission {
        $previousStatus = null;

        $fresh = DB::transaction(function () use ($submission, $from, $action, $reviewer, $mutate, &$previousStatus): Submission {
            $fresh = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, $from, true)) {
                throw SubmissionReviewException::illegalTransition($fresh->status, $action);
            }

            $previousStatus = $fresh->status;
            $old = $this->snapshot($fresh);

            $mutate($fresh);
            $fresh->save();

            // The actor is the passed-in $reviewer, NOT AuditLogger's Auth::id() fallback, and the
            // difference is not theoretical: approve()/returnToRespondent() stamp `validated_by =
            // $reviewer->id`, so any caller that ever passes a reviewer who is not the authenticated user
            // (I9's review/edit surface, a bulk-review job, an artisan command) would leave the ledger
            // contradicting the very row it describes — and the ledger would be the one that is wrong.
            $this->audit->record(
                AuditEvent::Updated,
                'submission',
                (string) $fresh->getKey(),
                old: $old,
                new: $this->snapshot($fresh),
                actorId: (string) $reviewer->getKey(),
            );

            return $fresh;
        });

        // POST-COMMIT, by call-site ordering — the convention the whole event pipeline uses
        // (technical-architecture.md §7.4; `DB::afterCommit` is verified never to fire under the suite's
        // uncommitted outer transaction, so it is not an option here). The audit row above is the ledger
        // and is atomic with the change; this is the announcement, and it must not fire for a transition
        // that rolled back — which is exactly what moving it out of the closure buys.
        if ($announce !== null && $previousStatus instanceof SubmissionStatus) {
            event($announce($fresh, $previousStatus));
        }

        return $fresh;
    }

    /**
     * The reviewable state of a submission, snapshotted identically before and after the mutation so one
     * emission site serves all four verbs.
     *
     * Timestamps are ISO strings, never Carbon instances: a Carbon serializes into jsonb as
     * `{"date":…,"timezone_type":3,…}`, which no reader can parse.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Submission $submission): array
    {
        return [
            'status' => $submission->status->value,
            'validated_by' => $submission->validated_by,
            'validated_at' => $submission->validated_at?->toIso8601String(),
            'finalized_at' => $submission->finalized_at?->toIso8601String(),
            'returned_reason' => $submission->returned_reason,
            // Placeholdered on both sides by AuditRedactor before this ever reaches the table — the row
            // records THAT the remarks changed, never what they said. See AuditRedactor::PII['submission'].
            'remarks' => $submission->remarks,
        ];
    }

    private function applyRemarks(Submission $submission, ?string $remarks): void
    {
        if ($remarks !== null) {
            $submission->remarks = $remarks;
        }
    }
}
