<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationType;
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
            ->first(['id', 'form_id', 'respondent_user_id']);

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

        $reviewers = array_values(array_diff(
            $this->recipients->forType(NotificationType::ReviewRequested, $form, $actorId),
            $received,
        ));

        $this->dispatcher->dispatch(NotificationType::ReviewRequested, $reviewers, $data);
    }
}
