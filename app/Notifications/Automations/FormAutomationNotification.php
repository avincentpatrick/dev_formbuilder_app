<?php

declare(strict_types=1);

namespace App\Notifications\Automations;

use App\Enums\QueueName;
use App\Jobs\Automations\SendFormAutomationEmailJob;
use App\Notifications\Concerns\CarriesTenantBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * A form automation's email (M132, `R-b7bc5149`, `D82`): a response arrived — the form, its reference number and the
 * time, and a link that opens it. **No answer is ever in it**: the link leads into the app, where the response's own
 * access check decides who may read it, so health data never travels by email to an address an author typed.
 *
 * The `ResumeLinkNotification` shape: queued on `mail`, SCALAR-ONLY (every string is resolved
 * by {@see SendFormAutomationEmailJob} under the tenant context), sent to an on-demand address, and listed in
 * `scripts/job-payload-lint.php`'s EXEMPT_JOBS and `QueuedMailContractTest`.
 */
#[Queue(QueueName::Mail)]
final class FormAutomationNotification extends Notification implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(
        public readonly string $formTitle,
        public readonly string $automationName,
        public readonly string $reference,
        public readonly string $submittedAt,
        public readonly string $responseUrl,
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
        return $this->branded(
            (new MailMessage)
                ->subject("New response to {$this->formTitle}")
                ->line("A response was submitted to \"{$this->formTitle}\".")
                ->line("Reference: {$this->reference}")
                ->line("Submitted: {$this->submittedAt}")
                ->action('Open the response', $this->responseUrl)
                ->line('The answers are not in this email. The link opens the response for members of the workspace who may see it.')
                ->line("You are receiving this because of the automation \"{$this->automationName}\" on this form.")
        );
    }
}
