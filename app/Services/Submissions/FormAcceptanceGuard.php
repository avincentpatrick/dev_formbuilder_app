<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Models\Form;
use App\Models\Submission;
use App\Support\Forms\FormSchedule;
use Carbon\CarbonImmutable;

/**
 * The single scheduled-form enforcement point (Increment H12a). Answers "may this form take THIS write right
 * now" for the two ways a submission is created and the one way a draft is finalized, throwing
 * {@see FormNotAcceptingSubmissionException} when not. Every check is LIVE — computed from `opens_at`/
 * `closes_at`/`now()` and a transactional cap COUNT — never from the persisted `schedule_state` (which lags the
 * ≤5-minute sweep and exists only to fire `form.opened`/`form.closed` once).
 *
 * The draft-vs-close policy is a GRACE WINDOW (ratified in H12a): a save-and-resume draft STARTED before the
 * form closed may still be promoted after close (bounded by its own `draft_expires_at` reaper), so a
 * respondent who saved at 60% is never stranded. Only a NEW submission or a NEW draft is blocked once closed —
 * and the time close does NOT waive the capacity cap, which is enforced at every finalize.
 */
final class FormAcceptanceGuard
{
    /**
     * A fresh submission or a NEW draft may start only inside the open window. Runs early (before any
     * transaction) so a refusal is cheap and leaves nothing to roll back.
     *
     * @throws FormNotAcceptingSubmissionException
     */
    public function assertCanStart(Form $form): void
    {
        $now = CarbonImmutable::now();

        if ($form->opens_at !== null && FormSchedule::isBeforeOpen($form, $now)) {
            throw FormNotAcceptingSubmissionException::notYetOpen($form->opens_at);
        }

        if ($form->closes_at !== null && FormSchedule::isAfterClose($form, $now)) {
            throw FormNotAcceptingSubmissionException::closed($form->closes_at);
        }
    }

    /**
     * A draft may be promoted after close under the grace window — UNLESS it was somehow created at/after the
     * close instant (a draft that should never have started), which is refused defensively. The capacity cap
     * still applies and is enforced separately at finalize.
     *
     * @throws FormNotAcceptingSubmissionException
     */
    public function assertCanPromote(Form $form, Submission $draft): void
    {
        $closesAt = $form->closes_at;

        if ($closesAt !== null && $draft->created_at !== null && $draft->created_at->greaterThanOrEqualTo($closesAt)) {
            throw FormNotAcceptingSubmissionException::closed($closesAt);
        }
    }

    /**
     * The response cap (H-map row 233: "transactional COUNT-under-RLS, not a running aggregate"). MUST be
     * called INSIDE the finalize transaction, AFTER the head row exists (created on submit / given its
     * finalized status on promote), so the live COUNT includes the row being finalized **whenever that row
     * consumes a slot** — hence the threshold is `> max`, not `+1 > max`. The form-row `lockForUpdate`
     * serializes concurrent finalizers on the same form so two racing submits cannot both slip past the last
     * slot. A null cap takes no lock and no COUNT.
     *
     * The qualifier on "includes the row being finalized" is I9a's and it is not pedantry: a `screened_out`
     * row is written before this runs and is then excluded by the predicate, so for THAT row the count is of
     * the others alone. The `>` threshold stays correct either way — a non-consuming row cannot push the
     * count past the cap, which is precisely the outcome the state exists to produce.
     *
     * The COUNT is {@see Submission::scopeConsumesCapacity()} and NOT `scopeCountable()`: the cap is a
     * purchased quantity, so what it counts is rows that consumed a slot — which since I9a excludes
     * `screened_out` as well as `draft`. Its two display twins, `PublicFormPresenter::capacityCount()` and
     * `EncodeFormPresenter::capacityCount()`, use the same scope for the same reason. **They move together or
     * the public banner says "capacity reached" while this guard still accepts.**
     *
     * @throws FormNotAcceptingSubmissionException
     */
    public function assertCapacity(Form $form): void
    {
        if ($form->max_responses === null) {
            return; // unlimited — no serialization cost for uncapped forms
        }

        $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

        if ($locked->max_responses === null) {
            return; // the cap was cleared concurrently between load and lock
        }

        $count = Submission::query()
            ->where('form_id', $form->id)
            ->consumesCapacity()
            ->count();

        if ($count > $locked->max_responses) {
            throw FormNotAcceptingSubmissionException::capacityReached($locked->max_responses, $count);
        }
    }
}
