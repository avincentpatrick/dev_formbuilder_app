<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\SubmissionStatus;
use App\Exceptions\Submissions\SubmissionReviewException;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The reviewer workflow (Increment F7): the guarded status transitions a Validator/Owner/Admin drives from
 * the inbox detail view. Each transition takes a fresh `SELECT … FOR UPDATE` lock, asserts the current status
 * is a legal source state (else {@see SubmissionReviewException}), records the reviewer + timestamps per
 * data-dictionary §7, and saves — all in one transaction. Authorization is the `can:review,submission` route
 * middleware ({@see SubmissionPolicy::review()}); this service assumes it has already passed.
 *
 * Audit-log rows + reviewer notifications are stubbed (no `audits` table lands until its own increment —
 * consistent with F2–F6). `submitted → approved/returned` may skip the intermediate `under_review` claim.
 */
final class SubmissionReviewService
{
    /** Claim a submitted response for review (no reviewer/timestamp is finalized until approve/return). */
    public function markUnderReview(Submission $submission, User $reviewer, ?string $remarks = null): Submission
    {
        return $this->apply($submission, [SubmissionStatus::Submitted], 'start reviewing', function (Submission $s) use ($remarks): void {
            $s->status = SubmissionStatus::UnderReview;
            $this->applyRemarks($s, $remarks);
        });
    }

    public function approve(Submission $submission, User $reviewer, ?string $remarks = null): Submission
    {
        return $this->apply($submission, [SubmissionStatus::Submitted, SubmissionStatus::UnderReview], 'approve', function (Submission $s) use ($reviewer, $remarks): void {
            $s->status = SubmissionStatus::Approved;
            $s->validated_by = (string) $reviewer->id;
            $s->validated_at = now();
            $s->finalized_at = now();
            $this->applyRemarks($s, $remarks);
        });
    }

    public function returnToRespondent(Submission $submission, User $reviewer, string $reason, ?string $remarks = null): Submission
    {
        return $this->apply($submission, [SubmissionStatus::Submitted, SubmissionStatus::UnderReview], 'return', function (Submission $s) use ($reviewer, $reason, $remarks): void {
            $s->status = SubmissionStatus::Returned;
            $s->validated_by = (string) $reviewer->id;
            $s->validated_at = now();
            $s->returned_reason = $reason;
            $this->applyRemarks($s, $remarks);
        });
    }

    /** Move a non-draft submission to the terminal `archived` retention state. */
    public function archive(Submission $submission, User $reviewer, ?string $remarks = null): Submission
    {
        return $this->apply($submission, [
            SubmissionStatus::Submitted, SubmissionStatus::UnderReview,
            SubmissionStatus::Approved, SubmissionStatus::Returned,
        ], 'archive', function (Submission $s) use ($remarks): void {
            $s->status = SubmissionStatus::Archived;
            $s->finalized_at = now();
            $this->applyRemarks($s, $remarks);
        });
    }

    /**
     * @param  list<SubmissionStatus>  $from  legal source states
     * @param  Closure(Submission): void  $mutate
     */
    private function apply(Submission $submission, array $from, string $action, Closure $mutate): Submission
    {
        return DB::transaction(function () use ($submission, $from, $action, $mutate): Submission {
            $fresh = Submission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, $from, true)) {
                throw SubmissionReviewException::illegalTransition($fresh->status, $action);
            }

            $mutate($fresh);
            $fresh->save();

            // TODO(audit): emit an `updated` audit event + reviewer notification once the audits table lands.

            return $fresh;
        });
    }

    private function applyRemarks(Submission $submission, ?string $remarks): void
    {
        if ($remarks !== null) {
            $submission->remarks = $remarks;
        }
    }
}
