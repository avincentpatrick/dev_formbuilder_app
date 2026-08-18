<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationType;
use App\Events\SubmissionApproved;
use App\Models\Form;
use App\Services\Notifications\NotificationDispatcher;

/**
 * A reviewer approved a submission — tell the person who submitted it (Increment I3).
 *
 * Auto-discovered and SYNCHRONOUS; see {@see NotifyOnSubmissionCreated} for why that is not optional.
 *
 * The recipient is named by the event, not resolved from a role: this notification is about ONE person's
 * response, and {@see NotificationType::SubmissionApproved}'s empty `recipientRoles()` is the enum saying
 * so. A guest submission has no `respondent_user_id` and therefore nobody to tell — a clean no-op, pinned
 * by a test, because "we approved a guest's response and 500ed" is exactly the shape of bug an empty
 * recipient set produces if nobody checks.
 */
final class NotifyOnSubmissionApproved
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(SubmissionApproved $event): void
    {
        if ($event->respondentUserId === null) {
            return;
        }

        $form = Form::query()->whereKey($event->formId)->first(['id', 'title']);

        $this->dispatcher->dispatch(
            NotificationType::SubmissionApproved,
            [$event->respondentUserId],
            [
                'submission_id' => $event->submissionId,
                'form_id' => $event->formId,
                'form_title' => $form?->title,
            ],
        );
    }
}
