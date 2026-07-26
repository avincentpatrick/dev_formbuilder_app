<?php

declare(strict_types=1);

namespace App\Notifications\Webhooks;

use App\Enums\QueueName;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Notifications\Entitlements\QuotaOverageNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Queue;

/**
 * Tells a tenant owner their webhook endpoint was auto-paused by the circuit breaker (H13b —
 * webhook-integration-design.md §5, technical-architecture.md §7.4). Dispatched once, on the edge when
 * {@see DeliverWebhookJob} trips the breaker at `webhooks.breaker_threshold` consecutive failures; re-enabling
 * is a manual admin action (PATCH the endpoint status to active) that resets the breaker.
 *
 * QUEUED ON THE ASYNC SUBSTRATE (H3), scalar-only and tenant-less on the worker, sent to an on-demand
 * notifiable (`Notification::route('mail', $email)`) — the {@see QuotaOverageNotification} pattern — so no
 * Eloquent model is ever serialized under a NULL GUC. Listed in scripts/job-payload-lint.php EXEMPT_JOBS for
 * the same reason (R2's #[Queue] and R3's scalar payload still apply and are satisfied here).
 */
#[Queue(QueueName::Mail)]
final class WebhookAutoDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $endpointName,
        public readonly string $endpointUrl,
        public readonly int $failureCount,
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
            ->subject("Your webhook \"{$this->endpointName}\" was automatically paused")
            ->line("Your webhook endpoint \"{$this->endpointName}\" ({$this->endpointUrl}) failed {$this->failureCount} deliveries in a row, so we paused it to stop it from consuming delivery capacity.")
            ->line('No deliveries are being attempted while it is paused. Recent attempts and their responses are in the endpoint\'s delivery log.')
            ->line('Once the receiving endpoint is healthy again, re-enable the webhook to resume deliveries — that also resets the failure counter.');
    }
}
