<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * Emails a respondent their save-and-resume link (Increment H9b) — the first real product consumer of the
 * H3 queued-mail substrate. Sent when a guest picks "Save and finish later" on a form that collected a
 * contact email; opening the link restores the in-progress draft (§5.2 of the form-filling UX flow). The
 * styled resume/welcome-back experience lands with the SPA surface in H10; this proves the delivery path.
 *
 * QUEUED ON THE ASYNC SUBSTRATE (H3), the {@see TenantInvitationNotification} pattern exactly: it rides the
 * `mail` queue via Illuminate\Notifications\SendQueuedNotifications, is SCALAR-ONLY (a pre-built resume URL +
 * the form's display name) and TENANT-LESS on the worker, and is dispatched to an on-demand notifiable
 * (Notification::route('mail', $email)) so no Eloquent model is serialized under a NULL GUC. It is listed in
 * scripts/job-payload-lint.php's EXEMPT_JOBS (R1) as a framework queued notification; R2 (this #[Queue]) and
 * R3 (scalar payload) still apply and are satisfied.
 *
 * SECRET-IN-QUEUE (accepted): the resume URL carries the plaintext resume token into the `jobs` payload, the
 * same bounded trade-off the invitation notification already accepts — there is no way to email a resume link
 * without the token transiting the payload; the token is HMAC-signed and self-expiring, never a stored secret.
 */
#[Queue(QueueName::Mail)]
final class ResumeLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $formName,
        public readonly string $resumeUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Continue your response to {$this->formName}")
            ->line("Your progress on \"{$this->formName}\" has been saved.")
            ->action('Continue your response', $this->resumeUrl)
            ->line('This link will expire in 30 days.');
    }
}
