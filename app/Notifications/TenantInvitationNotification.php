<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\QueueName;
use App\Notifications\Concerns\CarriesTenantBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * Delivers a tenant invitation's accept link (the plaintext token, which is only ever hashed at rest).
 * Minimal by design — the styled invitation email/landing experience lands with the design system in
 * Increment C; B2b proves the lifecycle end-to-end.
 *
 * QUEUED ON THE ASYNC SUBSTRATE (H3). This runs via Illuminate\Notifications\SendQueuedNotifications on
 * the `mail` queue (ADR-0007 §D6). It is deliberately SCALAR-ONLY and TENANT-LESS on the worker: the
 * caller (TenantMembershipService) resolves the tenant name and the full accept URL IN-REQUEST — under
 * live tenant context — and passes them as strings, and dispatch is to an on-demand notifiable
 * (Notification::route('mail', $email)). So no Eloquent model is ever serialized under a NULL GUC (the
 * §D5 hazard) and no tenant context is needed to render toMail(). It is listed in
 * scripts/job-payload-lint.php's EXEMPT_JOBS (R1) precisely because it is a framework queued notification
 * that rides the substrate without extending TenantAwareJob; R2 (this #[Queue]) and R3 (scalar payload)
 * still apply and are satisfied here.
 *
 * SECRET-IN-QUEUE (accepted): the accept URL carries the plaintext invite token into the `jobs` payload
 * (and `failed_jobs` on failure). Bounded and acceptable — the job runs within seconds; those tables are
 * the same DB-internal, RLS-free trust boundary the substrate already accepts for tenant uuids; and the
 * invite TTL (7d) is shorter than the failed-job retention (14d), so a leaked token in a failed job is
 * already expired. There is no way to email an accept link without the token transiting the payload.
 */
#[Queue(QueueName::Mail)]
final class TenantInvitationNotification extends Notification implements ShouldQueue
{
    use CarriesTenantBrand;
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $acceptUrl,
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
                ->subject("You've been invited to {$this->tenantName}")
                ->line("You've been invited to join {$this->tenantName}.")
                ->action('Accept invitation', $this->acceptUrl)
                ->line('This invitation will expire in 7 days.')
        );
    }
}
