<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationType;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Listeners\Webhooks\DispatchWebhooksForSubmissionCreated;
use App\Models\Form;
use App\Models\Submission;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;

/**
 * A submission arrived — tell the people who own the data, and separately the people who review it
 * (Increment I3).
 *
 * Auto-discovered and SYNCHRONOUS, never `ShouldQueue`: every query below is RLS-scoped, and a queued
 * listener would run under a null tenant GUC where all of them fail closed and silently produce nothing.
 * This is the {@see DispatchWebhooksForSubmissionCreated} shape.
 *
 * ── Two notification types from one event, and why nobody gets both ─────────────────────────────────────
 * `submission_received` addresses Owner/Admin/Form Editor ("your data arrived"); `review_requested`
 * addresses Owner/Admin/Reviewer ("your queue has work"). Owner and Admin are in both sets, so a naive
 * fan-out gives them two bell rows for one submission — twice the noise, from a feature whose whole point
 * is to be worth looking at. The review set is therefore the review set MINUS whoever was already told.
 * An Owner gets "New submission", a Reviewer gets "Review requested", and a person who is both gets one
 * row. (The alternative — ship both and let people mute one — is a promise I3 cannot keep, because the
 * preferences card is I4.)
 *
 * The respondent is excluded from both. On the manual-encode channel the person who typed the response is
 * a Form Editor, and telling them their own keystrokes arrived is how a bell becomes background noise.
 *
 * ── A SCREENED-OUT SUBMISSION GETS `submission_received` BUT NOT `review_requested` (I9a) ───────────────
 * `SubmissionStatus::ScreenedOut` is TERMINAL: it is absent from all four `$from` lists in
 * `SubmissionReviewService`, so a reviewer opening one finds a detail page with every action refused. Telling
 * them "review requested" is asking for work that cannot be done, and a queue notification nobody can action
 * is exactly how people learn to ignore the bell — the same argument the paragraph above makes about
 * double-notifying an Owner, reached from the other direction.
 *
 * `submission_received` still fires, and that asymmetry is the point rather than an inconsistency: the tenant
 * DID receive something, it is listed in the inbox by default, and the whole reason the state is visible
 * rather than hidden is so that a form which screens everyone out is loud. The person who owns the data
 * should know; the person who works the queue has nothing to work.
 */
final class NotifyOnSubmissionCreated
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(SubmissionCreated $event): void
    {
        $submission = Submission::query()
            ->whereKey($event->submissionId)
            ->first(['id', 'form_id', 'respondent_user_id', 'status']);

        if (! $submission instanceof Submission) {
            return;
        }

        $form = Form::query()->whereKey($submission->form_id)->first(['id', 'title', 'scope_node_id']);

        if (! $form instanceof Form) {
            return;
        }

        $actorId = $submission->respondent_user_id === null ? null : (string) $submission->respondent_user_id;

        $data = [
            'submission_id' => (string) $submission->getKey(),
            'form_id' => (string) $form->getKey(),
            'form_title' => $form->title,
        ];

        $received = $this->recipients->forType(NotificationType::SubmissionReceived, $form, $actorId);
        $this->dispatcher->dispatch(NotificationType::SubmissionReceived, $received, $data);

        // Nothing to review on a terminal row — see the header. Returning here rather than filtering the
        // recipient list keeps the resolver query off the hot path entirely for these.
        if ($submission->status === SubmissionStatus::ScreenedOut) {
            return;
        }

        $reviewers = array_values(array_diff(
            $this->recipients->forType(NotificationType::ReviewRequested, $form, $actorId),
            $received,
        ));

        $this->dispatcher->dispatch(NotificationType::ReviewRequested, $reviewers, $data);
    }
}
