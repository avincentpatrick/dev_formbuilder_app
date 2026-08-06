<?php

declare(strict_types=1);

namespace App\Listeners\Notifications;

use App\Enums\NotificationType;
use App\Events\SubmissionReturned;
use App\Models\Form;
use App\Services\Notifications\NotificationDispatcher;

/**
 * A reviewer returned a submission — tell the person who has to act on it (Increment I3).
 *
 * The mirror of {@see NotifyOnSubmissionApproved}, including the guest no-op.
 *
 * `returned_reason` is deliberately NOT copied into `data`. The notification is a pointer, and the
 * submission page shows the reason verbatim next to the answers it is about — which is the only place it
 * can actually be acted on. Keeping it out also keeps the promise the `notifications` migration makes about
 * what that column may hold: identifiers and display labels, nothing a reader would be surprised to find
 * there. (The event's own payload excludes it too, for a different reason — see {@see SubmissionReturned}.)
 */
final class NotifyOnSubmissionReturned
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(SubmissionReturned $event): void
    {
        if ($event->respondentUserId === null) {
            return;
        }

        $form = Form::query()->whereKey($event->formId)->first(['id', 'title']);

        $this->dispatcher->dispatch(
            NotificationType::SubmissionReturned,
            [$event->respondentUserId],
            [
                'submission_id' => $event->submissionId,
                'form_id' => $event->formId,
                'form_title' => $form?->title,
            ],
        );
    }
}
