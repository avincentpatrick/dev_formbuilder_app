<?php

declare(strict_types=1);

namespace App\Notifications\Submissions;

use App\Enums\QueueName;
use App\Enums\SubmissionPdfOutcome;
use App\Mail\TenantMail;
use App\Notifications\Concerns\CarriesTenantBrand;
use App\Notifications\ResumeLinkNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * Tells the staff user who asked for a submission PDF how it went (Increment H17).
 *
 * ── Why a Notification and not the TenantMail this increment was predicted to use ───────────────
 * {@see TenantMail}'s docblock names "the submission-PDF receipt (H17)" as an intended first
 * consumer, "where a Mailable with a Blade view earns its keep over a MailMessage". H17 declines,
 * and the decision is recorded on `TenantMail` itself rather than left as an unexplained gap:
 *
 *   - Its other predicted consumer, the H9b resume email, already went the Notification route
 *     ({@see ResumeLinkNotification}). The pattern has voted 1-0 in practice.
 *   - All SEVEN existing outbound emails are queued Notifications with a `MailMessage`. There is
 *     no Blade mail layout, no `resources/views/mail`, no published vendor mail views and no
 *     `lang/` directory. Making a one-link message the first of all of those is a mail-template
 *     layer, not a receipt.
 *   - A `TenantMail` is NOT `ShouldQueue` by design, so `scripts/job-payload-lint.php` never sees
 *     it and nothing statically enforces its payload shape. This class IS `ShouldQueue`, so R2
 *     (the `#[Queue]` attribute) and R3 (scalar-and-backed-enum payload) both bind it.
 *
 * The amend-rather-than-implement device H7 and H8 both used: the prediction was reasonable when
 * written, the evidence since says otherwise, so the prediction is corrected in place.
 *
 * PAYLOAD is scalar + one backed enum, and TENANT-LESS on the worker. Dispatched to an on-demand
 * notifiable (`Notification::route('mail', $email)`) so no Eloquent model is serialised under a
 * NULL GUC — the §D5 rule every sibling notification follows.
 *
 * ── The first success notification in the application ───────────────────────────────────────────
 * The three existing job-sent notifications are all failure alerts to the TENANT OWNER, because
 * they report tenant-level state (a quota overage, an auto-disabled webhook, a revoked grant).
 * This one goes to the REQUESTING USER, because it reports the outcome of a thing that specific
 * person did. A deliberate divergence, not an oversight.
 */
#[Queue(QueueName::Mail)]
final class SubmissionPdfReadyNotification extends Notification implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(
        public readonly string $formTitle,
        public readonly string $submissionId,
        public readonly SubmissionPdfOutcome $outcome,
        /** Absolute, pre-built on the tenant's host — a worker cannot resolve one. Null unless Ready. */
        public readonly ?string $downloadUrl = null,
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
        $mail = (new MailMessage)
            ->subject($this->outcome->subject($this->formTitle))
            ->line($this->outcome->body());

        if ($this->outcome->isReady() && $this->downloadUrl !== null) {
            $mail->action('Download the PDF', $this->downloadUrl);
        }

        return $this->branded($mail->line("Submission reference: {$this->submissionId}"));
    }
}
