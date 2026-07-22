<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\QueueName;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue;

/**
 * Queued password-reset (H3). A ShouldQueue subclass of the framework's ResetPassword that reuses its
 * wording/localization but delivers on the `mail` queue (ADR-0007 §D6).
 *
 * Same design as {@see QueuedVerifyEmail}: ResetPassword::resetUrl() reads
 * $notifiable->getEmailForPasswordReset(), which an on-demand notifiable does not provide. So
 * User::sendPasswordResetNotification() builds the reset URL IN-REQUEST and hands it here as a scalar;
 * delivery is to Notification::route('mail', …), so no User model is serialized under a NULL GUC (§D5).
 * The parent constructor is deliberately NOT called — the inherited $token is left unused because the URL
 * is already fully built. Listed in EXEMPT_JOBS (R1); R2/R3 still apply and are satisfied (one scalar prop).
 */
#[Queue(QueueName::Mail)]
final class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $signedUrl)
    {
        // Intentionally do not call parent::__construct(): the inherited $token is unused because the
        // reset URL has already been built (with the token embedded) in the caller's request context.
    }

    /**
     * Return the URL pre-built in-request; the on-demand $notifiable is never inspected.
     *
     * @param  mixed  $notifiable
     */
    protected function resetUrl($notifiable): string
    {
        return $this->signedUrl;
    }
}
