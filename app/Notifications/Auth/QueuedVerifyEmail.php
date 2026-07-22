<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\QueueName;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue;

/**
 * Queued email-verification (H3). A ShouldQueue subclass of the framework's VerifyEmail that reuses its
 * wording/localization but delivers on the `mail` queue (ADR-0007 §D6).
 *
 * WHY A PRE-BUILT URL. VerifyEmail::verificationUrl() reads $notifiable->getKey()/getEmailForVerification()
 * to sign the link. A queued notification is delivered through Illuminate\Notifications\SendQueuedNotifications,
 * which serializes the notifiable via SerializesModels BEFORE the handler runs, under a NULL GUC — and a
 * User model would then fail closed under RLS (the §D5 hazard). So User::sendEmailVerificationNotification()
 * signs the URL IN-REQUEST (where the User is live, and where the URL correctly captures the REQUEST host)
 * and hands it here as a scalar; delivery is to an on-demand notifiable (Notification::route('mail', …)),
 * so nothing is ever restored from the database on the worker. Listed in scripts/job-payload-lint.php's
 * EXEMPT_JOBS (R1); R2 (#[Queue]) and R3 (the single scalar payload) still apply and are satisfied.
 */
#[Queue(QueueName::Mail)]
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $signedUrl) {}

    /**
     * Return the URL pre-signed in-request; the on-demand $notifiable is never inspected.
     *
     * @param  mixed  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        return $this->signedUrl;
    }
}
